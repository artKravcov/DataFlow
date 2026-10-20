import { createClient } from 'redis';
import { parseEnvelope } from './envelope.js';

const stream = process.argv[2] ?? '';
if (!/^dataflow:contract-test:[0-9a-f-]{36}$/.test(stream)) throw new Error('A dedicated test stream is required');
const client = createClient({ url: `redis://${process.env.REDIS_HOST ?? 'redis'}:${process.env.REDIS_PORT ?? '6379'}`, socket: { connectTimeout: 5000, reconnectStrategy: false } });
client.on('error', () => {});
await client.connect();
try {
  const group = 'contract-check';
  await client.sendCommand(['XGROUP', 'CREATE', stream, group, '0']);
  const raw = await client.sendCommand(['XREADGROUP', 'GROUP', group, 'consumer', 'COUNT', '2', 'STREAMS', stream, '>']) as unknown as Array<[string, Array<[string, string[]]>]> | null;
  if (!raw || raw.length !== 1 || raw[0][0] !== stream || raw[0][1].length !== 2) throw new Error('Expected two deliveries');
  const entries = raw[0][1];
  const messages = entries.map(([, fields]) => {
    if (fields.length !== 2 || fields[0] !== 'envelope') throw new Error('Invalid stream fields');
    return parseEnvelope(fields[1]);
  });
  if (JSON.stringify(messages[0]) !== JSON.stringify(messages[1])) throw new Error('Duplicate deliveries differ');
  const count = await client.sendCommand(['XACK', stream, group, ...entries.map(([id]) => id)]) as unknown as number;
  if (count !== 2) throw new Error('Could not acknowledge test messages');
  console.info('Redis contract verified: PHP publication, Node parsing and duplicate delivery.');
} finally {
  await client.close();
}
