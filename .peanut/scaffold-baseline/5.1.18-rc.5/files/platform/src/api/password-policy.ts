import axios from 'axios';
const installationClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || undefined,
  withCredentials: true,
});

export interface PasswordPolicy {
  minimum_length: number;
  maximum_length: number;
  length_unit: 'utf8_bytes';
  require_uppercase: boolean;
  require_lowercase: boolean;
  require_digit: boolean;
  require_special: boolean;
}

export async function getPasswordPolicy(): Promise<PasswordPolicy> {
  const response = await installationClient.get<unknown>(
    '/installapi/password-policy'
  );
  const body = response.data;
  if (
    !body ||
    typeof body !== 'object' ||
    !('code' in body) ||
    body.code !== 20000 ||
    !('data' in body)
  ) {
    throw new Error('Unable to load password policy.');
  }
  const policy = body.data;
  if (
    !policy ||
    typeof policy !== 'object' ||
    !('minimum_length' in policy) ||
    !('maximum_length' in policy) ||
    !('length_unit' in policy) ||
    typeof policy.minimum_length !== 'number' ||
    typeof policy.maximum_length !== 'number' ||
    !Number.isSafeInteger(policy.minimum_length) ||
    !Number.isSafeInteger(policy.maximum_length) ||
    policy.minimum_length < 1 ||
    policy.maximum_length < policy.minimum_length ||
    policy.length_unit !== 'utf8_bytes' ||
    ![
      'require_uppercase',
      'require_lowercase',
      'require_digit',
      'require_special',
    ].every(
      (key) =>
        typeof (policy as unknown as Record<string, unknown>)[key] === 'boolean'
    )
  ) {
    throw new Error('Invalid password policy.');
  }
  return policy as PasswordPolicy;
}

export function passwordWithinPolicy(
  value: string,
  policy: PasswordPolicy
): boolean {
  const { length } = new TextEncoder().encode(value);
  return (
    length >= policy.minimum_length &&
    length <= policy.maximum_length &&
    (!policy.require_uppercase || /[A-Z]/.test(value)) &&
    (!policy.require_lowercase || /[a-z]/.test(value)) &&
    (!policy.require_digit || /[0-9]/.test(value)) &&
    (!policy.require_special ||
      /[\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/.test(value))
  );
}

export function passwordRequirementsHint(
  policy: PasswordPolicy,
  locale = 'zh-CN'
): string {
  const english = locale.startsWith('en');
  const required = [
    policy.require_uppercase && 'A-Z',
    policy.require_lowercase && 'a-z',
    policy.require_digit && '0-9',
    policy.require_special &&
      (english
        ? 'ASCII punctuation (excluding space)'
        : 'ASCII 标点（不含空格）'),
  ].filter(Boolean);
  return required.length
    ? (english ? '; must include: ' : '；须包含：') +
        required.join(english ? ', ' : '、')
    : '';
}
