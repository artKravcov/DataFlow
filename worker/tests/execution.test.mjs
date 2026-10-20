import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { performance } from 'node:perf_hooks';
import { WorkerApi, runClaimed, orderGraph, WorkerTransportError, LeaseLostError } from '../dist/execution.js';

const fixture = JSON.parse(await readFile(new URL('../../contracts/fixtures/csv-filter-map-v1.json', import.meta.url)));
const graph = structuredClone(fixture.graph);
graph.nodes[0].config.asset_id = '42';
const claim = { execution_id: '1', snapshot: { revision: 1, graph }, lease_token: 'a'.repeat(64), lease_seconds: 60 };

function server({ input = 'order_id,status,total\n101,paid,12.50\n102,pending,8.00\n103,paid,20.00\n', loseEvent = false, refuseEvent = false } = {}) {
  const events = [];
  const calls = [];
  const seen = new Map();
  let result = null;
  let dropped = false;
  const fetcher = async (url, options) => {
    const route = new URL(url).pathname;
    calls.push(route);
    assert.equal(new Headers(options.headers).get('Authorization'), 'Bearer ' + 'x'.repeat(32));
    if (route.endsWith('/content')) return new Response(input, { status: 200 });
    if (route.endsWith('/artifacts')) { result = Buffer.from(options.body).toString(); return Response.json({ data: { asset_id: '9' } }, { status: 201 }); }
    if (route.endsWith('/events')) {
      const event = JSON.parse(options.body);
      if (refuseEvent) return Response.json({}, { status: 409 });
      if (!seen.has(event.event_id)) { events.push(event); seen.set(event.event_id, event.sequence); }
      if (loseEvent && !dropped) { dropped = true; throw new Error('lost response'); }
      return Response.json({ data: { accepted_sequence: seen.get(event.event_id) } });
    }
    if (route.endsWith('/heartbeat')) return Response.json({ data: { lease_seconds: 60 } });
    throw new Error('unexpected call');
  };
  return { api: new WorkerApi('http://backend:8000', 'x'.repeat(32), 'worker-1', fetcher), events, calls, get result() { return result; } };
}

test('executes the CSV graph, reports each node and uploads the expected result', async () => {
  const s = server({ loseEvent: true });
  await runClaimed(s.api, claim);
  assert.equal(s.result, 'id,amount\n101,12.50\n103,20.00\n');
  assert.deepEqual(s.events.map(e => e.type), ['node.started','node.succeeded','node.started','node.succeeded','node.started','node.succeeded','node.started','node.succeeded','execution.succeeded']);
  assert.deepEqual(s.events.map(e => e.sequence), [1,2,3,4,5,6,7,8,9]);
  assert.equal(s.calls.filter(c => c.endsWith('/events')).length, 10);
});

test('reports a safe error and does not run subsequent nodes when an input field is missing', async () => {
  const s = server({ input: 'order_id,total\n101,12\n' });
  await runClaimed(s.api, claim);
  assert.deepEqual(s.events.map(e => e.type), ['node.started','node.succeeded','node.started','node.failed','execution.failed']);
  assert.equal(s.events.at(-1).error_code, 'FIELD_MISSING');
  assert.equal(s.result, null);
});

test('does not perform work after lease or event confirmation is lost', async () => {
  const rejected = server({ refuseEvent: true });
  await assert.rejects(runClaimed(rejected.api, claim), LeaseLostError);
  assert.equal(rejected.calls.some(c => c.endsWith('/content')), false);
  const expired = server();
  await assert.rejects(runClaimed(expired.api, claim, performance.now() - 56_000), LeaseLostError);
  assert.deepEqual(expired.calls, []);
});

test('rejects unsupported graph shapes before executing nodes', async () => {
  const invalid = structuredClone(graph);
  invalid.edges[1].target = 'source';
  assert.throws(() => orderGraph(invalid), error => error.code === 'TYPE_MISMATCH');
  const s = server();
  await runClaimed(s.api, { ...claim, snapshot: { revision: 1, graph: invalid } });
  assert.deepEqual(s.events.map(e => e.type), ['execution.failed']);
  assert.equal(s.result, null);
});
