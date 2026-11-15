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
  const policy = await http.get<PasswordPolicy>(
    'installapi/password-policy',
    undefined,
    false
  );
  if (
    !Number.isSafeInteger(policy?.minimum_length) ||
    !Number.isSafeInteger(policy?.maximum_length) ||
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
    throw new Error('Invalid password policy');
  }
  return policy;
}

export function utf8ByteLength(value: string): number {
  let length = 0;
  for (let index = 0; index < value.length; index++) {
    const code = value.charCodeAt(index);
    if (code < 0x80) length++;
    else if (code < 0x800) length += 2;
    else if (
      code >= 0xd800 &&
      code <= 0xdbff &&
      index + 1 < value.length &&
      value.charCodeAt(index + 1) >= 0xdc00 &&
      value.charCodeAt(index + 1) <= 0xdfff
    ) {
      length += 4;
      index++;
    } else length += 3;
  }
  return length;
}

export function passwordWithinPolicy(
  value: string,
  policy: PasswordPolicy
): boolean {
  const length = utf8ByteLength(value);
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
