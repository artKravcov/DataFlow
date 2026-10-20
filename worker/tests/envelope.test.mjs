import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parseEnvelope } from '../dist/envelope.js';

const fixture = JSON.parse(await readFile(new URL('../../contracts/fixtures/redis-envelope-v1.json', import.meta.url), 'utf8'));
test('accepts the shared fixture', () => assert.deepEqual(parseEnvelope(JSON.stringify(fixture)), fixture));
test('rejects unknown fields, versions, invalid IDs and excessive data', () => {
  for (const value of [{...fixture, secret: 'no'}, {...fixture, message_version: 2}, {...fixture, execution_id: '0'}, {...fixture, execution_id: 123}, {...fixture, message_id: 'invalid'}, {...fixture, extra: 'x'.repeat(600)}]) {
    assert.throws(() => parseEnvelope(JSON.stringify(value)));
  }
});
