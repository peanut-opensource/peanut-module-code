import axios from 'axios';

export interface TenantLicenseSummary {
  plan_name: string;
  plan_code: string;
  effective_at: string;
  expires_at: string | null;
  effective: boolean;
  state: 'active' | 'suspended' | 'upcoming' | 'expired';
}

/** Only the fields displayed by the tenant shell cross this response boundary. */
export async function getTenantLicense(
  signal?: AbortSignal
): Promise<TenantLicenseSummary | null> {
  const envelope = await axios.get<unknown, unknown>(
    '/adminapi/core.tenant.license',
    {
      signal,
    }
  );
  if (!envelope || typeof envelope !== 'object' || !('data' in envelope))
    throw new Error('许可证响应格式异常');
  const { data } = envelope;
  if (!data || typeof data !== 'object' || !('license' in data))
    throw new Error('许可证响应格式异常');
  if (data.license === null) return null;
  const row = data.license;
  if (
    !row ||
    typeof row !== 'object' ||
    !('plan_name' in row) ||
    typeof row.plan_name !== 'string' ||
    !('plan_code' in row) ||
    typeof row.plan_code !== 'string' ||
    !('effective_at' in row) ||
    typeof row.effective_at !== 'string' ||
    !('expires_at' in row) ||
    (row.expires_at !== null && typeof row.expires_at !== 'string') ||
    !('effective' in row) ||
    typeof row.effective !== 'boolean' ||
    !('state' in row) ||
    (row.state !== 'active' &&
      row.state !== 'suspended' &&
      row.state !== 'upcoming' &&
      row.state !== 'expired')
  )
    throw new Error('许可证字段格式异常');
  return {
    plan_name: row.plan_name,
    plan_code: row.plan_code,
    effective_at: row.effective_at,
    expires_at: row.expires_at,
    effective: row.effective,
    state: row.state,
  };
}
