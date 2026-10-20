export interface ExecutionEnvelope {
  message_version: 1;
  message_id: string;
  execution_id: string;
}

export function parseEnvelope(json: string): ExecutionEnvelope {
  if (Buffer.byteLength(json, 'utf8') > 512) throw new Error('Envelope exceeds size limit');
  const value: unknown = JSON.parse(json);
  if (value === null || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid envelope');
  const data = value as Record<string, unknown>;
  const keys = Object.keys(data).sort();
  if (keys.join(',') !== 'execution_id,message_id,message_version' || data.message_version !== 1 ||
      typeof data.message_id !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(data.message_id) ||
      typeof data.execution_id !== 'string' || !/^[1-9][0-9]{0,17}$/.test(data.execution_id)) throw new Error('Invalid envelope');
  return { message_version: 1, message_id: data.message_id, execution_id: data.execution_id };
}
