import { performance } from 'node:perf_hooks';
import { parseEnvelope } from './envelope.js';
import { ClaimUnavailableError, WorkerApi, runClaimed } from './execution.js';

export type StreamClient = { sendCommand(args: string[]): Promise<unknown> };
export type Delivery = { id: string; fields: string[] };

/** Acknowledge only a confirmed claim or an explicitly unavailable execution. */
export async function consumeDelivery(client: StreamClient, api: WorkerApi, stream: string, group: string, delivery: Delivery): Promise<'executed' | 'duplicate'> {
  if (delivery.fields.length !== 2 || delivery.fields[0] !== 'envelope') throw new Error('Invalid stream message');
  const envelope = parseEnvelope(delivery.fields[1]);
  const started = performance.now();
  let claim;
  try { claim = await api.claim(envelope.execution_id); }
  catch (error) {
    if (!(error instanceof ClaimUnavailableError) || error.code !== 'EXECUTION_UNAVAILABLE') throw error;
    if (await client.sendCommand(['XACK', stream, group, delivery.id]) !== 1) throw new Error('Acknowledgement failed');
    return 'duplicate';
  }
  if (await client.sendCommand(['XACK', stream, group, delivery.id]) !== 1) throw new Error('Acknowledgement failed');
  await runClaimed(api, claim, started);
  return 'executed';
}
