import { installationClient } from './install';

export interface PasswordPolicy {
  minimum_length: number;
  maximum_length: number;
  length_unit: 'utf8_bytes';
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
    policy.length_unit !== 'utf8_bytes'
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
  return length >= policy.minimum_length && length <= policy.maximum_length;
}
