import {
  createAdminOverrideRegistry,
  type AdminOverride,
} from '@peanut-admin/vue';
import {
  ADMIN_SHELL_OVERRIDE_SLOTS,
  resolveWorkspaceShell,
  type ShellThemeInput,
  type ShellLayout,
  type ShellNavigationMode,
} from '@peanut-admin/ui-vue';
import { reactive } from 'vue';

type PlatformPreset = 'admin' | 'workspace';
interface PlatformUserOptions {
  presets: readonly PlatformPreset[];
  layouts: readonly ShellLayout[];
  navigationModes: readonly ShellNavigationMode[];
  densities: readonly ('comfortable' | 'compact')[];
  modes: readonly ('light' | 'dark')[];
}
interface PlatformPresentation {
  preset: PlatformPreset;
  brand: string;
  theme: ShellThemeInput;
  layout: ShellLayout;
  navigationMode: ShellNavigationMode;
}
interface PlatformApplicationOverrides {
  platformPresets?: Record<
    PlatformPreset,
    {
      layout: ShellLayout;
      navigationMode: ShellNavigationMode;
      density: 'comfortable' | 'compact';
    }
  >;
  platformUserOptions?: PlatformUserOptions;
  platformPresentation?: PlatformPresentation;
  platformShell?: ReturnType<typeof resolveWorkspaceShell>;
  selectPlatformPreset?: (preset: PlatformPreset) => void;
  PLATFORM_PRESETS?: readonly AdminOverride[];
  PLATFORM_OVERRIDES?: readonly AdminOverride[];
}

// Earlier installed applications have no platform override entry. Keep their source
// untouched and use the managed defaults; an existing entry keeps its explicit choices.
const application =
  Object.values(
    import.meta.glob<PlatformApplicationOverrides>('../peanut.overrides.ts', {
      eager: true,
    })
  )[0] ?? {};

export const platformUserOptions: PlatformUserOptions =
  application.platformUserOptions ?? {
    presets: ['admin', 'workspace'],
    layouts: ['admin', 'workspace'],
    navigationModes: ['tree', 'grouped', 'top'],
    densities: ['comfortable', 'compact'],
    modes: ['light', 'dark'],
  };

const platformPresets =
  application.platformPresets ??
  ({
    admin: { layout: 'admin', navigationMode: 'tree', density: 'comfortable' },
    workspace: {
      layout: 'workspace',
      navigationMode: 'grouped',
      density: 'compact',
    },
  } as const);

export const platformPresentation = reactive<PlatformPresentation>(
  application.platformPresentation ?? {
    preset: platformUserOptions.presets[0] ?? 'admin',
    brand: 'PEANUT 平台',
    theme: {
      mode: platformUserOptions.modes[0] ?? 'light',
      density: platformUserOptions.densities[0] ?? 'comfortable',
    },
    layout: platformUserOptions.layouts[0] ?? 'admin',
    navigationMode: platformUserOptions.navigationModes[0] ?? 'tree',
  }
);

export function selectPlatformPreset(preset: PlatformPreset) {
  if (!platformUserOptions.presets.includes(preset)) return;
  if (application.selectPlatformPreset) {
    application.selectPlatformPreset(preset);
    return;
  }
  const selected = platformPresets[preset];
  platformPresentation.preset = preset;
  if (platformUserOptions.layouts.includes(selected.layout))
    platformPresentation.layout = selected.layout;
  if (platformUserOptions.navigationModes.includes(selected.navigationMode))
    platformPresentation.navigationMode = selected.navigationMode;
  if (platformUserOptions.densities.includes(selected.density))
    platformPresentation.theme.density = selected.density;
}

selectPlatformPreset(platformPresentation.preset);

export const platformShell =
  application.platformShell ??
  resolveWorkspaceShell(
    createAdminOverrideRegistry({
      slots: ADMIN_SHELL_OVERRIDE_SLOTS,
      presets: application.PLATFORM_PRESETS ?? [],
      overrides: application.PLATFORM_OVERRIDES ?? [],
    }),
    'platform'
  );
