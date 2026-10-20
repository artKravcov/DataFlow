export type Value = string | number | boolean | null;
export type Field = { name: string; type: string; nullable: boolean };
export type Dataset = { schema: Field[]; records: Record<string, Value>[] };
export type ErrorCode = 'INVALID_CSV' | 'FIELD_MISSING' | 'TYPE_MISMATCH' | 'RESOURCE_LIMIT' | 'TIMEOUT';

export class RuntimeError extends Error {
  constructor(public readonly code: ErrorCode) { super(code); }
}

const MAX_FILE_BYTES = 10 * 1024 * 1024;
const MAX_ROWS = 10_000;
const MAX_COLUMNS = 256;
const MAX_DATASET_BYTES = 50 * 1024 * 1024;
const MAX_SECONDS = 300_000;

function guard(dataset: Dataset): Dataset {
  if (dataset.schema.length > MAX_COLUMNS || dataset.records.length > MAX_ROWS || Buffer.byteLength(JSON.stringify(dataset)) > MAX_DATASET_BYTES) {
    throw new RuntimeError('RESOURCE_LIMIT');
  }
  return dataset;
}

export function readCsv(input: Uint8Array, options: { delimiter?: string; header?: boolean } = {}): Dataset {
  if (input.byteLength === 0) throw new RuntimeError('INVALID_CSV');
  if (input.byteLength > MAX_FILE_BYTES) throw new RuntimeError('RESOURCE_LIMIT');
  const delimiter = options.delimiter ?? ',';
  if (![',', ';', ':', '|'].includes(delimiter)) throw new RuntimeError('INVALID_CSV');
  let text: string;
  try { text = new TextDecoder('utf-8', { fatal: true }).decode(input); }
  catch { throw new RuntimeError('INVALID_CSV'); }
  if (text.includes('\0')) throw new RuntimeError('INVALID_CSV');
  if (text.startsWith('\uFEFF')) text = text.slice(1);
  const rows: string[][] = [];
  let row: string[] = [];
  let field = '';
  let inQuotes = false;
  let closedQuote = false;
  let atFieldStart = true;
  let endedRow = false;
  const started = Date.now();
  const pushField = () => {
    row.push(field);
    if (row.length > MAX_COLUMNS) throw new RuntimeError('RESOURCE_LIMIT');
    field = ''; closedQuote = false; atFieldStart = true;
  };
  const pushRow = () => {
    pushField(); rows.push(row); row = [];
    if (rows.length > MAX_ROWS + (options.header === false ? 0 : 1)) throw new RuntimeError('RESOURCE_LIMIT');
    endedRow = true;
  };
  for (let i = 0; i < text.length; i++) {
    if (i % 65536 === 0 && Date.now() - started > MAX_SECONDS) throw new RuntimeError('TIMEOUT');
    const char = text[i];
    if (inQuotes) {
      if (char === '"') {
        if (text[i + 1] === '"') { field += '"'; i++; }
        else { inQuotes = false; closedQuote = true; }
      } else field += char;
      endedRow = false;
      continue;
    }
    if (closedQuote && char !== delimiter && char !== '\r' && char !== '\n') throw new RuntimeError('INVALID_CSV');
    if (char === '"') {
      if (!atFieldStart) throw new RuntimeError('INVALID_CSV');
      inQuotes = true; atFieldStart = false; endedRow = false;
    } else if (char === delimiter) { pushField(); endedRow = false; }
    else if (char === '\n' || char === '\r') {
      if (char === '\r' && text[i + 1] === '\n') i++;
      pushRow();
    } else { field += char; atFieldStart = false; endedRow = false; }
  }
  if (inQuotes) throw new RuntimeError('INVALID_CSV');
  if (!endedRow) pushRow();
  if (!rows.length) throw new RuntimeError('INVALID_CSV');
  const columns = options.header === false ? rows[0].map((_, index) => `column_${index + 1}`) : rows.shift()!;
  if (!columns.length || columns.some(name => !name || name.length > 128) || new Set(columns).size !== columns.length) throw new RuntimeError('INVALID_CSV');
  const records: Record<string, Value>[] = [];
  let budget = 0;
  for (const values of rows) {
    if (values.length !== columns.length) throw new RuntimeError('INVALID_CSV');
    const record: Record<string, Value> = Object.create(null);
    columns.forEach((name, i) => { record[name] = values[i]; });
    budget += Buffer.byteLength(JSON.stringify(record));
    if (budget > MAX_DATASET_BYTES) throw new RuntimeError('RESOURCE_LIMIT');
    records.push(record);
  }
  return guard({ schema: columns.map(name => ({ name, type: 'string', nullable: false })), records });
}

export function filterDataset(input: Dataset, config: { field: string; operator: string; value?: Value }): Dataset {
  guard(input);
  if (!input.schema.some(field => field.name === config.field)) throw new RuntimeError('FIELD_MISSING');
  const operators = ['equals', 'not_equals', 'is_null', 'is_not_null', 'greater_than', 'less_than'];
  if (!operators.includes(config.operator) || (!['is_null', 'is_not_null'].includes(config.operator) && !Object.hasOwn(config, 'value'))) throw new RuntimeError('TYPE_MISMATCH');
  const records = input.records.filter(record => {
    if (!Object.hasOwn(record, config.field)) throw new RuntimeError('FIELD_MISSING');
    const value = record[config.field];
    switch (config.operator) {
      case 'equals': return value === config.value;
      case 'not_equals': return value !== config.value;
      case 'is_null': return value === null;
      case 'is_not_null': return value !== null;
      case 'greater_than': case 'less_than':
        if (typeof value !== 'number' || typeof config.value !== 'number' || !Number.isFinite(value) || !Number.isFinite(config.value)) throw new RuntimeError('TYPE_MISMATCH');
        return config.operator === 'greater_than' ? value > config.value : value < config.value;
      default: throw new RuntimeError('TYPE_MISMATCH');
    }
  });
  return guard({ schema: input.schema.map(field => ({ ...field })), records });
}

export function mapDataset(input: Dataset, mappings: { source: string; target: string }[]): Dataset {
  guard(input);
  if (!Array.isArray(mappings) || !mappings.length || mappings.length > MAX_COLUMNS || new Set(mappings.map(m => m.target)).size !== mappings.length || mappings.some(m => !m.source || !m.target || m.target.length > 128)) throw new RuntimeError('TYPE_MISMATCH');
  const schema = mappings.map(mapping => {
    const field = input.schema.find(field => field.name === mapping.source);
    if (!field) throw new RuntimeError('FIELD_MISSING');
    return { ...field, name: mapping.target };
  });
  const records = input.records.map(record => {
    const mapped: Record<string, Value> = Object.create(null);
    for (const mapping of mappings) {
      if (!Object.hasOwn(record, mapping.source)) throw new RuntimeError('FIELD_MISSING');
      mapped[mapping.target] = record[mapping.source];
    }
    return mapped;
  });
  return guard({ schema, records });
}

export function writeCsv(input: Dataset): Buffer {
  guard(input);
  if (!input.schema.length) throw new RuntimeError('INVALID_CSV');
  const quote = (value: Value) => {
    const text = value === null ? '' : String(value);
    return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
  };
  const lines = [input.schema.map(field => quote(field.name)).join(',')];
  let size = Buffer.byteLength(lines[0]) + 1;
  for (const record of input.records) {
    const line = input.schema.map(field => {
      if (!Object.hasOwn(record, field.name)) throw new RuntimeError('FIELD_MISSING');
      return quote(record[field.name]);
    }).join(',');
    size += Buffer.byteLength(line) + 1;
    if (size > MAX_FILE_BYTES) throw new RuntimeError('RESOURCE_LIMIT');
    lines.push(line);
  }
  if (size > MAX_FILE_BYTES) throw new RuntimeError('RESOURCE_LIMIT');
  return Buffer.from(lines.join('\n') + '\n', 'utf8');
}
