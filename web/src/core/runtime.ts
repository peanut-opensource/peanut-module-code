import {
  createAdminOverrideRegistry,
  defineAdminOverrideSlot,
  permissionEvaluatorSlot,
  PERMISSION_EVALUATOR_OVERRIDE_KEY,
  type AdminOverride,
} from '@peanut-admin/vue';
import type { Component } from 'vue';
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
export const shellColorPresetOptions =
  shellUserOptions.colorPresets ?? defaultShellUserOptions.colorPresets;

export { PERMISSION_EVALUATOR_OVERRIDE_KEY } from '@peanut-admin/vue';
export type { PermissionEvaluator } from '@peanut-admin/vue';

// These slots are also in newer Core Web builds. The published 5.1.2 lock
// needs the same definitions locally until the host adopts that build.
const optionalComponentSlot = <Key extends string>(key: Key) =>
  defineAdminOverrideSlot({
    key,
    kind: 'component',
    contractVersion: '1.0.0',
    defaultValue: null as Component | null,
    validate: (value): value is Component | null => {
      if (value === null || typeof value === 'function') return true;
      if (typeof value !== 'object') return false;
      const component = value as Record<string, unknown>;
      return (
        typeof component.setup === 'function' ||
        typeof component.render === 'function' ||
        typeof component.template === 'string' ||
        typeof component.__asyncLoader === 'function'
      );
    },
  });

const hostComponentSlots = [
  optionalComponentSlot('peanut.shell.component.login-extra'),
  optionalComponentSlot('peanut.shell.component.navbar-actions'),
  optionalComponentSlot('peanut.shell.component.workbench-extra'),
  optionalComponentSlot('peanut.shell.component.footer'),
] as const;
const hostComponentKeys = new Set<string>(hostComponentSlots.map(({ key }) => key));

const registry = createAdminOverrideRegistry({
  slots: [
    permissionEvaluatorSlot,
    ...ADMIN_SHELL_OVERRIDE_SLOTS.filter(({ key }) => !hostComponentKeys.has(key)),
    ...hostComponentSlots,
  ] as const,
  presets: application.PEANUT_ADMIN_PRESETS ?? [],
  overrides: PEANUT_ADMIN_OVERRIDES,
});

export const permissionEvaluator = registry.get(
  PERMISSION_EVALUATOR_OVERRIDE_KEY
);

export const coreOverrideDiagnostics = registry.diagnostics;
export const workspaceShell = resolveWorkspaceShell(registry, 'tenant');
export const loginExtra = registry.get('peanut.shell.component.login-extra');
export const navbarActions = registry.get('peanut.shell.component.navbar-actions');
export const workbenchExtra = registry.get('peanut.shell.component.workbench-extra');
export const footerOverride = registry.get('peanut.shell.component.footer');
