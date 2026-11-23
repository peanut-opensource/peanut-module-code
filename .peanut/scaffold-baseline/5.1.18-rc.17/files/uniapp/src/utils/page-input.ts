/** URL values are input, not typed business data. */
export function policyKind(value: unknown): 'privacy' | 'service' {
  if (value === undefined || value === '') return 'service';
  if (value === 'privacy' || value === 'service') return value;
  throw new Error('POLICY_KIND_INVALID');
}

export function positivePageId(value: unknown): number {
  if (typeof value !== 'string' || !/^[1-9][0-9]*$/.test(value)) {
    throw new Error('PAGE_ID_INVALID');
  }
  const id = Number(value);
  if (!Number.isSafeInteger(id)) throw new Error('PAGE_ID_INVALID');
  return id;
}
