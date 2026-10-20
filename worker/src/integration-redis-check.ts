import { createClient } from 'redis';
import { WorkerApi } from './execution.js';
import { consumeDelivery } from './consumer.js';

const stream = process.argv[2] ?? '';
if (process.env.DATAFLOW_INTEGRATION !== '1' || process.env.DATAFLOW_TEST_API_URL !== 'http://dataflow-integration-api:8000' || !/^dataflow:contract-test:[0-9a-f-]{36}$/.test(stream)) throw new Error('Isolated integration environment required');
const client = createClient({ url: 'redis://redis:6379', socket: { connectTimeout: 5000, reconnectStrategy: false } });
client.on('error', () => {});
await client.connect();
try {
  const group = 'integration-check';
  await client.sendCommand(['XGROUP', 'CREATE', stream, group, '0']);
  const raw = await client.sendCommand(['XREADGROUP', 'GROUP', group, 'integration-worker', 'COUNT', '2', 'STREAMS', stream, '>']) as unknown as Array<[string, Array<[string, string[]]>]> | null;
  if (!raw || raw.length !== 1 || raw[0][0] !== stream || raw[0][1].length !== 2) throw new Error('Expected duplicate test deliveries');
  const api = new WorkerApi(process.env.DATAFLOW_TEST_API_URL, process.env.INTERNAL_SERVICE_TOKEN ?? '', 'integration-worker');
  const results = [];
  for (const [id, fields] of raw[0][1]) results.push(await consumeDelivery(client, api, stream, group, { id, fields }));
  if (results.join(',') !== 'executed,duplicate') throw new Error('Incorrect duplicate delivery behavior');
  console.info('Redis delivery verified: one execution and one acknowledged duplicate.');
} finally { await client.close(); }
