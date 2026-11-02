import {
  createAdminOverrideRegistry,
  permissionEvaluatorSlot,
  PERMISSION_EVALUATOR_OVERRIDE_KEY,
} from '@peanut-admin/vue';
import {
  ADMIN_SHELL_OVERRIDE_SLOTS,
  resolveWorkspaceShell,
} from '@peanut-admin/ui-vue';
import PEANUT_ADMIN_OVERRIDES, {
  PEANUT_ADMIN_PRESETS,
} from '@/peanut.overrides';

export { PERMISSION_EVALUATOR_OVERRIDE_KEY } from '@peanut-admin/vue';
export type { PermissionEvaluator } from '@peanut-admin/vue';

const registry = createAdminOverrideRegistry({
  slots: [permissionEvaluatorSlot, ...ADMIN_SHELL_OVERRIDE_SLOTS] as const,
  presets: PEANUT_ADMIN_PRESETS,
  overrides: PEANUT_ADMIN_OVERRIDES,
});

export const permissionEvaluator = registry.get(
  PERMISSION_EVALUATOR_OVERRIDE_KEY
);

export const coreOverrideDiagnostics = registry.diagnostics;
export const workspaceShell = resolveWorkspaceShell(registry, 'tenant');
