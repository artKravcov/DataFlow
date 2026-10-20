import test from 'node:test';
import assert from 'node:assert/strict';
import { consumeDelivery } from '../dist/consumer.js';
import { ClaimUnavailableError, WorkerTransportError } from '../dist/execution.js';

const envelope = JSON.stringify({ message_version: 1, message_id: 'a3612890-193f-45f1-a263-44f7a0fc5000', execution_id: '1' });
const delivery = { id: '1-0', fields: ['envelope', envelope] };

test('acknowledges a confirmed duplicate but preserves unconfirmed or unavailable work', async () => {
  const commands = [];
  const redis = { sendCommand: async args => { commands.push(args); return 1; } };
  const unavailable = { claim: async () => { throw new ClaimUnavailableError('EXECUTION_UNAVAILABLE'); } };
  assert.equal(await consumeDelivery(redis, unavailable, 'stream', 'group', delivery), 'duplicate');
  assert.deepEqual(commands, [['XACK', 'stream', 'group', '1-0']]);
  commands.length = 0;
  const invalidSnapshot = { claim: async () => { throw new ClaimUnavailableError('SNAPSHOT_UNAVAILABLE'); } };
  await assert.rejects(consumeDelivery(redis, invalidSnapshot, 'stream', 'group', delivery));
  const network = { claim: async () => { throw new WorkerTransportError('unavailable'); } };
  await assert.rejects(consumeDelivery(redis, network, 'stream', 'group', delivery));
  assert.deepEqual(commands, []);
});

test('never starts execution if acknowledgement fails or the message is invalid', async () => {
  let claims = 0;
  const api = { claim: async () => { claims++; return {}; } };
  const redis = { sendCommand: async () => 0 };
  await assert.rejects(consumeDelivery(redis, api, 'stream', 'group', delivery), /Acknowledgement failed/);
  assert.equal(claims, 1);
  await assert.rejects(consumeDelivery(redis, api, 'stream', 'group', { id: '2-0', fields: ['wrong', envelope] }));
  assert.equal(claims, 1);
});
