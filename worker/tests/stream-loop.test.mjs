import test from 'node:test';
import assert from 'node:assert/strict';
import { ensureGroup, processDelivery, reclaimPending } from '../dist/stream-loop.js';

test('dead-letter stores only safe metadata, then acknowledges invalid messages', async () => {
  const commands = [];
  const client = { sendCommand: async args => { commands.push(args); return args[0] === 'XACK' ? 1 : '1-0'; } };
  await processDelivery(client, {}, 'dataflow:test', 'group', { id: '9-0', fields: ['envelope', 'SECRET USER DATA'] });
  assert.deepEqual(commands, [
    ['XADD', 'dataflow:test:dead', 'MAXLEN', '~', '1000', '*', 'code', 'INVALID_ENVELOPE', 'source_id', '9-0'],
    ['XACK', 'dataflow:test', 'group', '9-0'],
  ]);
  assert.equal(JSON.stringify(commands).includes('SECRET USER DATA'), false);
});

test('a failed dead-letter write keeps the original message pending', async () => {
  const commands = [];
  const client = { sendCommand: async args => { commands.push(args); throw new Error('redis unavailable'); } };
  await assert.rejects(processDelivery(client, {}, 'stream', 'group', { id: '9-0', fields: [] }));
  assert.equal(commands.length, 1);
  assert.equal(commands[0][0], 'XADD');
});

test('reclaim checks bounded responses and carries the scan cursor forward', async () => {
  const commands = [];
  const client = { sendCommand: async args => { commands.push(args); return ['8-0', [['7-0', ['envelope', '{}']]], []]; } };
  const result = await reclaimPending(client, 'stream', 'group', 'worker', '3-0');
  assert.deepEqual(result, { cursor: '8-0', messages: [{ id: '7-0', fields: ['envelope', '{}'] }] });
  assert.deepEqual(commands[0], ['XAUTOCLAIM', 'stream', 'group', 'worker', '30000', '3-0', 'COUNT', '1']);
});

test('group creation tolerates only the existing-group error', async () => {
  await ensureGroup({ sendCommand: async () => { throw new Error('BUSYGROUP already exists'); } }, 's', 'g');
  await assert.rejects(ensureGroup({ sendCommand: async () => { throw new Error('offline'); } }, 's', 'g'));
});
