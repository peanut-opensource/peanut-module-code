import axios from 'axios';

export interface PolicyOperation {
  resource_key: string;
  operation: string;
  access_mode: string;
  target_cardinality: string;
}
export interface PolicyConditionOption {
  condition_key: string;
  target_mode: string;
  selector_resource_key: string | null;
}
export interface PolicyCondition {
  condition_key: string;
  target_set: {
    name: string;
    target_resource_key: string;
    targets: { target_id: string }[];
  } | null;
}
export interface DataPolicy {
  status: 'active' | 'disabled';
  reason: string | null;
  valid_from: string | null;
  valid_until: string | null;
  groups: { name: string; conditions: PolicyCondition[] }[];
}
export interface PolicyLocation {
  role_id: number;
  resource_key: string;
  operation: string;
}
export function getPolicyOperations(page: number) {
  return axios.get<{ items: PolicyOperation[]; total: number }>(
    '/adminapi/role/data-policy/catalog',
    { params: { page_no: page, page_size: 20 } }
  );
}
export function getPolicyConditions(location: PolicyLocation) {
  return axios.get<{ conditions: PolicyConditionOption[] }>(
    '/adminapi/role/data-policy/catalog',
    {
      params: {
        resource_key: location.resource_key,
        operation: location.operation,
      },
    }
  );
}
export function getDataPolicy(location: PolicyLocation) {
  return axios.get<DataPolicy & { revision: number | string }>(
    '/adminapi/role/data-policy',
    { params: location }
  );
}
export function replaceDataPolicy(
  location: PolicyLocation,
  policy: DataPolicy,
  revision: number | null
) {
  return axios.put<DataPolicy & { revision: number | string }>(
    '/adminapi/role/data-policy',
    { ...location, policy, expected_revision: revision }
  );
}
