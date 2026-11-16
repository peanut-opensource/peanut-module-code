import test from 'node:test';
import assert from 'node:assert/strict';
import { reconcileRoleSelection } from '../../src/utils/owner-role-selection.ts';

test('tenant owner can add a business role without losing fixed owner role', () => {
  assert.deepEqual(reconcileRoleSelection([1], [2], true, 1), [2, 1]);
});

test('tenant owner cannot remove the fixed owner role while revoking another role', () => {
  assert.deepEqual(reconcileRoleSelection([1, 2], [], true, 1), [1]);
});

test('tenant owner role set is stable and deduplicated', () => {
  assert.deepEqual(reconcileRoleSelection([1, 2], [1, 2, 1], true, 1), [1, 2]);
});

test('unknown owner key preserves existing role binding and cannot grant new ones', () => {
  assert.deepEqual(reconcileRoleSelection([1], [2], true, null), [1]);
});

test('ordinary members retain the requested role list unchanged', () => {
  assert.deepEqual(reconcileRoleSelection([2], [3, 4], false, 1), [3, 4]);
});
