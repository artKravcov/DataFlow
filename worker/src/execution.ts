import { createHash, randomUUID } from 'node:crypto';
import { performance } from 'node:perf_hooks';
import { readCsv, filterDataset, mapDataset, writeCsv, RuntimeError, type Dataset, type Value } from './csv-runtime.js';

const MAX_INPUT = 10 * 1024 * 1024;
const REQUEST_TIMEOUT = 5000;
const EXECUTION_TIMEOUT = 300_000;
type Node = { id: string; type: string; type_version: number; config: Record<string, unknown> };
type Graph = { schema_version: number; nodes: Node[]; edges: { source: string; target: string; source_port: string; target_port: string }[] };
type Claim = { execution_id: string; snapshot: { revision: number; graph: Graph }; lease_token: string; lease_seconds: number };
type EventType = 'node.started' | 'node.succeeded' | 'node.failed' | 'execution.failed' | 'execution.succeeded';
export class WorkerTransportError extends Error {}
export class LeaseLostError extends Error {}
export class ClaimUnavailableError extends Error { constructor(public readonly code: string) { super(code); } }

function object(value: unknown): value is Record<string, unknown> { return value !== null && typeof value === 'object' && !Array.isArray(value); }

export function orderGraph(graph: Graph): Node[] {
  if (!object(graph) || graph.schema_version !== 1 || !Array.isArray(graph.nodes) || !Array.isArray(graph.edges) || graph.nodes.length < 2 || graph.nodes.length > 50 || graph.edges.length !== graph.nodes.length - 1) throw new RuntimeError('TYPE_MISMATCH');
  const nodes = new Map<string, Node>();
  const input = new Map<string, string>();
  const next = new Map<string, string>();
  for (const node of graph.nodes) {
    if (!object(node) || typeof node.id !== 'string' || node.id.length === 0 || node.id.length > 128 || node.type_version !== 1 || !['csv.source', 'transform.filter', 'transform.map', 'csv.destination'].includes(node.type) || !object(node.config) || nodes.has(node.id)) throw new RuntimeError('TYPE_MISMATCH');
    nodes.set(node.id, node);
  }
  for (const edge of graph.edges) {
    if (!object(edge) || typeof edge.source !== 'string' || typeof edge.target !== 'string' || !nodes.has(edge.source) || !nodes.has(edge.target) || edge.source_port !== 'records' || edge.target_port !== 'records' || input.has(edge.target) || next.has(edge.source)) throw new RuntimeError('TYPE_MISMATCH');
    input.set(edge.target, edge.source); next.set(edge.source, edge.target);
  }
  const sources = graph.nodes.filter(node => node.type === 'csv.source');
  const destinations = graph.nodes.filter(node => node.type === 'csv.destination');
  if (sources.length !== 1 || destinations.length !== 1 || input.has(sources[0].id) || next.has(destinations[0].id)) throw new RuntimeError('TYPE_MISMATCH');
  const ordered: Node[] = [];
  let current: string | undefined = sources[0].id;
  while (current !== undefined) {
    const node = nodes.get(current);
    if (!node || ordered.includes(node)) throw new RuntimeError('TYPE_MISMATCH');
    ordered.push(node); current = next.get(current);
  }
  if (ordered.length !== nodes.size || ordered.at(-1)?.type !== 'csv.destination') throw new RuntimeError('TYPE_MISMATCH');
  return ordered;
}

export class WorkerApi {
  constructor(private readonly base: string, private readonly serviceToken: string, readonly workerId: string, private readonly fetcher: typeof fetch = fetch) {
    const url = new URL(base);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash || !/^[A-Za-z0-9._-]{1,128}$/.test(workerId) || serviceToken.length < 32) throw new Error('Invalid worker configuration');
  }
  async request(path: string, options: RequestInit = {}, signal?: AbortSignal): Promise<Response> {
    const headers = new Headers(options.headers);
    headers.set('Authorization', `Bearer ${this.serviceToken}`);
    try {
      return await this.fetcher(new URL(path, this.base), { ...options, headers, redirect: 'error', signal: AbortSignal.any([AbortSignal.timeout(REQUEST_TIMEOUT), ...(signal ? [signal] : [])]) });
    } catch { throw new WorkerTransportError('Internal API unavailable'); }
  }
  async claim(id: string): Promise<Claim> {
    const response = await this.request(`/internal/v1/executions/${id}/claim`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ worker_id: this.workerId }) });
    if (!response.ok) {
      if (response.status === 409) { const body = await response.json().catch(() => null) as { error?: { code?: string } } | null; throw new ClaimUnavailableError(body?.error?.code ?? 'UNKNOWN'); }
      throw new WorkerTransportError('Claim rejected');
    }
    const json = await response.json().catch(() => null) as { data?: Claim } | null;
    const claim = json?.data;
    if (!claim || claim.execution_id !== id || claim.lease_seconds !== 60 || !/^[a-f0-9]{64}$/.test(claim.lease_token) || !object(claim.snapshot) || !object(claim.snapshot.graph)) throw new WorkerTransportError('Invalid claim response');
    return claim;
  }
  async json(id: string, endpoint: string, body: object, signal: AbortSignal): Promise<Response> {
    return this.request(`/internal/v1/executions/${id}/${endpoint}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }, signal);
  }
  async input(id: string, asset: string, token: string, signal: AbortSignal): Promise<Uint8Array> {
    if (!/^[1-9][0-9]{0,17}$/.test(asset)) throw new RuntimeError('TYPE_MISMATCH');
    const response = await this.request(`/internal/v1/executions/${id}/assets/${asset}/content`, { headers: { 'X-Worker-Id': this.workerId, 'X-Lease-Token': token } }, signal);
    if (!response.ok || !response.body) throw new WorkerTransportError('Input unavailable');
    const reader = response.body.getReader();
    const chunks: Uint8Array[] = [];
    let size = 0;
    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        size += value.byteLength;
        if (size > MAX_INPUT) { await reader.cancel(); throw new RuntimeError('RESOURCE_LIMIT'); }
        chunks.push(value);
      }
    } catch (error) { if (error instanceof RuntimeError) throw error; throw new WorkerTransportError('Input interrupted'); }
    return Buffer.concat(chunks, size);
  }
  async artifact(id: string, node: string, token: string, content: Buffer, signal: AbortSignal): Promise<void> {
    const headers = { 'Content-Type': 'text/csv', 'X-Worker-Id': this.workerId, 'X-Lease-Token': token, 'X-Node-Id': node, 'X-Content-Sha256': createHash('sha256').update(content).digest('hex') };
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        const response = await this.request(`/internal/v1/executions/${id}/artifacts`, { method: 'POST', headers, body: new Uint8Array(content) }, signal);
        if (response.ok) return;
        if (response.status < 500) throw new LeaseLostError('Artifact rejected');
      } catch (error) { if (error instanceof LeaseLostError) throw error; }
      if (signal.aborted) throw new LeaseLostError('Lease unavailable');
    }
    throw new WorkerTransportError('Artifact confirmation unavailable');
  }
}

export async function runClaimed(api: WorkerApi, claim: Claim, claimStartedAt = performance.now()): Promise<void> {
  const id = claim.execution_id;
  const token = claim.lease_token;
  const aborter = new AbortController();
  const end = claimStartedAt + EXECUTION_TIMEOUT;
  let leaseDeadline = claimStartedAt + 55_000;
  let sequence = 0;
  let terminal = false;
  let stopped = false;
  const checkLease = () => { if (aborter.signal.aborted || performance.now() >= leaseDeadline) { aborter.abort(); throw new LeaseLostError('Lease expired'); } };
  const check = () => { checkLease(); if (performance.now() >= end) throw new RuntimeError('TIMEOUT'); };
  const heartbeat = setInterval(async () => {
    if (stopped) return;
    if (performance.now() >= leaseDeadline) { aborter.abort(); return; }
    const started = performance.now();
    try {
      const response = await api.json(id, 'heartbeat', { worker_id: api.workerId, lease_token: token }, aborter.signal);
      if (response.ok && started + 55_000 > leaseDeadline) leaseDeadline = started + 55_000;
      else if (response.status === 409 || response.status === 401) aborter.abort();
    } catch { /* transient error: the last confirmed deadline still applies */ }
  }, 10_000);
  const sendEvent = async (type: EventType, nodeId?: string, errorCode?: string) => {
    checkLease();
    const number = sequence + 1;
    const event = { worker_id: api.workerId, lease_token: token, event_id: randomUUID(), sequence: number, type, ...(nodeId ? { node_id: nodeId } : {}), ...(errorCode ? { error_code: errorCode } : {}) };
    for (let attempt = 0; attempt < 3; attempt++) {
      checkLease();
      try {
        const response = await api.json(id, 'events', event, aborter.signal);
        if (response.ok) {
          const data = await response.json() as { data?: { accepted_sequence?: number } };
          if (data.data?.accepted_sequence !== number) throw new WorkerTransportError('Invalid event confirmation');
          sequence = number; return;
        }
        if (response.status < 500) throw new LeaseLostError('Event rejected');
      } catch (error) { if (error instanceof LeaseLostError) throw error; }
    }
    throw new WorkerTransportError('Event confirmation unavailable');
  };
  let activeNode: string | undefined;
  try {
    check();
    const nodes = orderGraph(claim.snapshot.graph);
    let data: Dataset | undefined;
    for (const node of nodes) {
      check();
      await sendEvent('node.started', node.id); activeNode = node.id;
      if (node.type === 'csv.source') {
        if (typeof node.config.asset_id !== 'string' || (node.config.delimiter !== undefined && typeof node.config.delimiter !== 'string') || (node.config.header !== undefined && typeof node.config.header !== 'boolean')) throw new RuntimeError('TYPE_MISMATCH');
        const bytes = await api.input(id, node.config.asset_id, token, aborter.signal); check();
        data = readCsv(bytes, { delimiter: node.config.delimiter as string | undefined, header: node.config.header as boolean | undefined });
      } else if (node.type === 'transform.filter') {
        if (!data || typeof node.config.field !== 'string' || typeof node.config.operator !== 'string' || (node.config.value !== undefined && node.config.value !== null && !['string', 'number', 'boolean'].includes(typeof node.config.value))) throw new RuntimeError('TYPE_MISMATCH');
        data = filterDataset(data, node.config as { field: string; operator: string; value?: Value });
      } else if (node.type === 'transform.map') {
        if (!data || !Array.isArray(node.config.mappings) || !node.config.mappings.every((m: unknown) => object(m) && typeof m.source === 'string' && typeof m.target === 'string')) throw new RuntimeError('TYPE_MISMATCH');
        data = mapDataset(data, node.config.mappings as { source: string; target: string }[]);
      } else {
        if (!data) throw new RuntimeError('TYPE_MISMATCH');
        const output = writeCsv(data); check(); await api.artifact(id, node.id, token, output, aborter.signal);
      }
      check(); await sendEvent('node.succeeded', node.id); activeNode = undefined;
    }
    await sendEvent('execution.succeeded'); terminal = true;
  } catch (error) {
    if (error instanceof RuntimeError && !terminal && !aborter.signal.aborted && performance.now() < leaseDeadline) {
      // A runtime error is reported only when all preceding state changes were confirmed.
      if (activeNode) await sendEvent('node.failed', activeNode, error.code);
      await sendEvent('execution.failed', undefined, error.code);
      terminal = true;
    } else throw error;
  } finally { stopped = true; clearInterval(heartbeat); aborter.abort(); }
}
