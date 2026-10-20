import { createClient } from 'redis';
import { WorkerApi } from './execution.js';
import { ensureGroup, processDelivery, readNext, reclaimPending } from './stream-loop.js';

const stream = process.argv[2] ?? '';
if (process.env.DATAFLOW_INTEGRATION !== '1' || process.env.DATAFLOW_TEST_API_URL !== 'http://dataflow-integration-api:8000' || !/^dataflow:contract-test:[0-9a-f-]{36}$/.test(stream)) throw new Error('Isolated integration environment required');
const client = createClient({ url: 'redis://redis:6379', socket: { connectTimeout: 5000, reconnectStrategy: false } });
client.on('error', () => {});
await client.connect();
try {
  const group = 'integration-check';
  await ensureGroup(client, stream, group);
  const abandoned = await readNext(client, stream, group, 'dead-worker');
  if (abandoned.length !== 1) throw new Error('Expected an abandoned test message');
  const recovered = await reclaimPending(client, stream, group, 'integration-worker', '0-0', 0);
  if (recovered.messages.length !== 1 || recovered.messages[0].id !== abandoned[0].id) throw new Error('Pending delivery was not recovered');
  const api = new WorkerApi(process.env.DATAFLOW_TEST_API_URL, process.env.INTERNAL_SERVICE_TOKEN ?? '', 'integration-worker');
  await processDelivery(client, api, stream, group, recovered.messages[0]);
  const duplicate = await readNext(client, stream, group, 'integration-worker');
  if (duplicate.length !== 1) throw new Error('Expected duplicate test delivery');
  await processDelivery(client, api, stream, group, duplicate[0]);
  const pending = await client.sendCommand(['XPENDING', stream, group]) as unknown[];
  if (pending[0] !== 0) throw new Error('Test deliveries remained pending');
  console.info('Redis delivery verified: recovered pending work, one execution and one acknowledged duplicate.');
} finally { await client.close(); }
