import { parseEnvelope } from './envelope.js';
import { consumeDelivery, type StreamClient, type Delivery } from './consumer.js';
import { WorkerApi } from './execution.js';

const isId = (value: unknown): value is string => typeof value === 'string' && /^[0-9]+-[0-9]+$/.test(value);

function delivery(value: unknown): Delivery {
  if (!Array.isArray(value) || value.length !== 2 || !isId(value[0]) || !Array.isArray(value[1]) || !value[1].every((item: unknown) => typeof item === 'string')) throw new Error('Invalid Redis response');
  return { id: value[0], fields: value[1] };
}

export async function ensureGroup(client: StreamClient, stream: string, group: string): Promise<void> {
  try { await client.sendCommand(['XGROUP', 'CREATE', stream, group, '0', 'MKSTREAM']); }
  catch (error) { if (!(error instanceof Error) || !error.message.startsWith('BUSYGROUP')) throw error; }
}

export async function reclaimPending(client: StreamClient, stream: string, group: string, workerId: string, cursor: string, idleMs = 30_000): Promise<{ cursor: string; messages: Delivery[] }> {
  const raw = await client.sendCommand(['XAUTOCLAIM', stream, group, workerId, String(idleMs), cursor, 'COUNT', '1']);
  if (!Array.isArray(raw) || !isId(raw[0]) || !Array.isArray(raw[1]) || raw[1].length > 1) throw new Error('Invalid Redis response');
  return { cursor: raw[0], messages: raw[1].map(delivery) };
}

export async function readNext(client: StreamClient, stream: string, group: string, workerId: string): Promise<Delivery[]> {
  const raw = await client.sendCommand(['XREADGROUP', 'GROUP', group, workerId, 'COUNT', '1', 'BLOCK', '2000', 'STREAMS', stream, '>']);
  if (raw === null) return [];
  if (!Array.isArray(raw) || raw.length !== 1 || !Array.isArray(raw[0]) || raw[0][0] !== stream || !Array.isArray(raw[0][1]) || raw[0][1].length > 1) throw new Error('Invalid Redis response');
  return raw[0][1].map(delivery);
}

export async function processDelivery(client: StreamClient, api: WorkerApi, stream: string, group: string, item: Delivery): Promise<void> {
  let malformed = false;
  try {
    if (item.fields.length !== 2 || item.fields[0] !== 'envelope') throw new Error('Invalid fields');
    parseEnvelope(item.fields[1]);
  } catch { malformed = true; }
  if (malformed) {
    // Never copy the untrusted payload into infrastructure logs or the dead-letter stream.
    await client.sendCommand(['XADD', `${stream}:dead`, 'MAXLEN', '~', '1000', '*', 'code', 'INVALID_ENVELOPE', 'source_id', item.id]);
    if (await client.sendCommand(['XACK', stream, group, item.id]) !== 1) throw new Error('Acknowledgement failed');
    return;
  }
  await consumeDelivery(client, api, stream, group, item);
}
