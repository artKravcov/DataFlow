import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { readCsv, filterDataset, mapDataset, writeCsv } from '../dist/csv-runtime.js';

const parse = text => readCsv(Buffer.from(text));
const code = expected => error => error.code === expected;

test('CSV fixture is filtered and mapped to the expected downloadable CSV', async () => {
  const fixture = JSON.parse(await readFile(new URL('../../contracts/fixtures/csv-filter-map-v1.json', import.meta.url)));
  const input = parse('order_id,status,total\n101,paid,12.50\n102,pending,8.00\n103,paid,20.00\n');
  const filtered = filterDataset(input, fixture.graph.nodes[1].config);
  const mapped = mapDataset(filtered, fixture.graph.nodes[2].config.mappings);
  assert.deepEqual(JSON.parse(JSON.stringify(mapped)), fixture.expected);
  assert.equal(writeCsv(mapped).toString(), 'id,amount\n101,12.50\n103,20.00\n');
});

test('handles quoted fields, CRLF, UTF-8 and a zero-row result', () => {
  const input = parse('name,status\r\n"A, ""B""",нет\r\n"line\n2",paid\r\n');
  assert.equal(input.records[0].name, 'A, "B"');
  assert.equal(input.records[1].name, 'line\n2');
  const empty = filterDataset(input, { field: 'status', operator: 'equals', value: 'missing' });
  assert.equal(writeCsv(empty).toString(), 'name,status\n');
  assert.equal(parse(writeCsv(input).toString()).records[1].name, 'line\n2');
});

test('rejects malformed CSV, missing fields, numeric comparison of strings and resource overflows', () => {
  for (const value of ['a,a\n1,2\n', 'a,b\n1\n', 'a\n"unclosed', 'a\nno"quote\n']) assert.throws(() => parse(value), code('INVALID_CSV'));
  assert.throws(() => readCsv(Uint8Array.from([0xff])), code('INVALID_CSV'));
  const data = parse('value\n1\n');
  assert.throws(() => filterDataset(data, { field: 'missing', operator: 'equals', value: 'x' }), code('FIELD_MISSING'));
  assert.throws(() => filterDataset(data, { field: 'value', operator: 'greater_than', value: 0 }), code('TYPE_MISMATCH'));
  assert.throws(() => mapDataset(data, [{ source: 'missing', target: 'x' }]), code('FIELD_MISSING'));
  assert.throws(() => readCsv(Buffer.alloc(10 * 1024 * 1024 + 1)), code('RESOURCE_LIMIT'));
  assert.throws(() => parse('x\n' + '1\n'.repeat(10001)), code('RESOURCE_LIMIT'));
});

test('headerless CSV uses deterministic generated column names and preserves dangerous names as data', () => {
  const data = readCsv(Buffer.from('a;b\nc;d\n'), { delimiter: ';', header: false });
  assert.deepEqual(data.schema.map(field => field.name), ['column_1', 'column_2']);
  const special = parse('__proto__,constructor\nx,y\n');
  assert.equal(special.records[0].__proto__, 'x');
  assert.equal(special.records[0].constructor, 'y');
});
