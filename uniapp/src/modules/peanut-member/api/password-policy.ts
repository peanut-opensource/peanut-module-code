import { http } from '@/utils/request';

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
  const response = await http.get<unknown>('installapi/password-policy', {}, false);
  if (!response || typeof response !== 'object') {
    throw new Error('无法读取密码要求');
  }
  const policy = response as Partial<PasswordPolicy>;
  if (
    typeof policy.minimum_length !== 'number' ||
    typeof policy.maximum_length !== 'number' ||
    !Number.isSafeInteger(policy.minimum_length) ||
    !Number.isSafeInteger(policy.maximum_length) ||
    policy.minimum_length < 1 ||
    policy.maximum_length < policy.minimum_length ||
    policy.length_unit !== 'utf8_bytes' ||
    typeof policy.require_uppercase !== 'boolean' ||
    typeof policy.require_lowercase !== 'boolean' ||
    typeof policy.require_digit !== 'boolean' ||
    typeof policy.require_special !== 'boolean'
  ) {
    throw new Error('无法读取密码要求');
  }
  return policy as PasswordPolicy;
}

export function passwordWithinPolicy(value: string, policy: PasswordPolicy): boolean {
  let bytes = 0;
  for (const character of value) {
    const point = character.codePointAt(0)!;
    if (point < 0x80) bytes += 1;
    else if (point < 0x800) bytes += 2;
    else if (point < 0x10000) bytes += 3;
    else bytes += 4;
  }
  return (
    bytes >= policy.minimum_length &&
    bytes <= policy.maximum_length &&
    (!policy.require_uppercase || /[A-Z]/.test(value)) &&
    (!policy.require_lowercase || /[a-z]/.test(value)) &&
    (!policy.require_digit || /[0-9]/.test(value)) &&
    (!policy.require_special || /[\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/.test(value))
  );
}

export function passwordRequirementsHint(policy: PasswordPolicy): string {
  const required = [
    policy.require_uppercase && 'A-Z',
    policy.require_lowercase && 'a-z',
    policy.require_digit && '0-9',
    policy.require_special && 'ASCII 标点（不含空格）',
  ].filter(Boolean);
  return required.length ? '；须包含：' + required.join('、') : '';
}
