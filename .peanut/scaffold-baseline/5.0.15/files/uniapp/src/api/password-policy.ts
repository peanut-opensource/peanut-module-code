import { http } from '@/utils/request';

export interface PasswordPolicy {
  minimum_length: number;
  maximum_length: number;
  length_unit: 'utf8_bytes';
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
    policy.length_unit !== 'utf8_bytes'
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
  return length >= policy.minimum_length && length <= policy.maximum_length;
}
