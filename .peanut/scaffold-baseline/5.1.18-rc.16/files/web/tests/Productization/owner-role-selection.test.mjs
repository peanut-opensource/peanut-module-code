import test from 'node:test';
import assert from 'node:assert/strict';
import { reconcileRoleSelection } from '../../src/utils/owner-role-selection.ts';

test('editing an owner can remove the owner role without changing other requested roles', () => {
  assert.deepEqual(reconcileRoleSelection([1, 2], [2], true, 1), [2]);
});

test('editing an owner allows an empty role selection', () => {
  assert.deepEqual(reconcileRoleSelection([1], [], true, 1), []);
});

test('tenant owner role set is stable and deduplicated', () => {
  assert.deepEqual(reconcileRoleSelection([1, 2], [1, 2, 1], true, 1), [1, 2]);
});

test('unknown owner key preserves existing role binding and cannot grant new ones', () => {
  assert.deepEqual(reconcileRoleSelection([1], [2], true, null), [1]);
});

test('ordinary members cannot grant the owner role through the role selector', () => {
  assert.deepEqual(reconcileRoleSelection([2], [1, 3, 3], false, 1), [3]);
});

test('ordinary members retain legitimate roles and can also be left without roles', () => {
  assert.deepEqual(reconcileRoleSelection([2], [3, 4], false, 1), [3, 4]);
  assert.deepEqual(reconcileRoleSelection([2], [], false, 1), []);
});
