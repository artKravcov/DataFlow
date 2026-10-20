import { randomUUID } from 'node:crypto';
import { setTimeout as delay } from 'node:timers/promises';
import { createClient } from 'redis';
import { WorkerApi } from './execution.js';
import { ensureGroup, processDelivery, readNext, reclaimPending } from './stream-loop.js';

if (process.env.DATAFLOW_WORKER_ENABLED !== '1') {
  console.info('DataFlow worker is disabled.');
} else {
  const apiUrl = process.env.DATAFLOW_INTERNAL_URL ?? 'http://backend:8000';
  const token = process.env.INTERNAL_SERVICE_TOKEN ?? '';
  const workerId = `worker-${randomUUID()}`;
  const api = new WorkerApi(apiUrl, token, workerId);
  const stream = 'dataflow:executions:v1';
  const group = 'workers-v1';
  let stopping = false;
  process.on('SIGTERM', () => { stopping = true; });
  process.on('SIGINT', () => { stopping = true; });
  while (!stopping) {
    const client = createClient({ url: process.env.REDIS_URL ?? 'redis://redis:6379', socket: { connectTimeout: 5000, reconnectStrategy: false } });
    client.on('error', () => {});
    try {
      await client.connect();
      await ensureGroup(client, stream, group);
      let cursor = '0-0';
      while (!stopping) {
        const recovered = await reclaimPending(client, stream, group, workerId, cursor);
        cursor = recovered.cursor;
        const messages = recovered.messages.length ? recovered.messages : await readNext(client, stream, group, workerId);
        for (const message of messages) {
          try { await processDelivery(client, api, stream, group, message); }
          catch { console.warn('Execution delivery could not be completed; recovery will handle it.'); await delay(1000); }
        }
      }
    } catch {
      if (!stopping) { console.warn('Worker transport is unavailable; reconnecting.'); await delay(2000); }
    } finally {
      if (client.isOpen) await client.close().catch(() => {});
    }
  }
}
