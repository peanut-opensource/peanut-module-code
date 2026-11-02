import {
  createAdminOverrideRegistry,
  permissionEvaluatorSlot,
  PERMISSION_EVALUATOR_OVERRIDE_KEY,
  type AdminOverride,
} from '@peanut-admin/vue';
import {
  ADMIN_SHELL_OVERRIDE_SLOTS,
  resolveWorkspaceShell,
} from '@peanut-admin/ui-vue';
import PEANUT_ADMIN_OVERRIDES, * as applicationOverrides from '@/peanut.overrides';
import {
  defaultShellUserOptions,
  shellPresets,
  type ShellPresentation,
  type ShellUserOptions,
} from '@/layout/shell-presets';

// Application-owned entries survive upgrades; new presentation exports are optional.
const application = applicationOverrides as {
  default: readonly AdminOverride[];
  PEANUT_ADMIN_PRESETS?: readonly AdminOverride[];
  shellPresentation?: ShellPresentation;
  shellUserOptions?: ShellUserOptions;
};
export const shellPresentation: ShellPresentation =
  application.shellPresentation ?? shellPresets.admin;
export const shellUserOptions =
  application.shellUserOptions ?? defaultShellUserOptions;

export { PERMISSION_EVALUATOR_OVERRIDE_KEY } from '@peanut-admin/vue';
export type { PermissionEvaluator } from '@peanut-admin/vue';

const registry = createAdminOverrideRegistry({
  slots: [permissionEvaluatorSlot, ...ADMIN_SHELL_OVERRIDE_SLOTS] as const,
  presets: application.PEANUT_ADMIN_PRESETS ?? [],
  overrides: PEANUT_ADMIN_OVERRIDES,
});

export const permissionEvaluator = registry.get(
  PERMISSION_EVALUATOR_OVERRIDE_KEY
);

export const coreOverrideDiagnostics = registry.diagnostics;
export const workspaceShell = resolveWorkspaceShell(registry, 'tenant');
