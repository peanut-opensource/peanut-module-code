import type {
  ShellLayout,
  ShellNavigationMode,
  ShellThemeInput,
} from '@peanut-admin/ui-vue';

/** Presentation only; menus, action bindings and identity remain host-owned. */
export interface ShellPresentation {
  preset: 'admin' | 'workspace';
  layout: ShellLayout;
  navigationMode: ShellNavigationMode;
  theme: ShellThemeInput;
  brand?: { name?: string; logo?: string };
  breadcrumb: boolean;
}

export const shellPresets = {
  admin: {
    preset: 'admin',
    layout: 'admin',
    navigationMode: 'tree',
    theme: {},
    breadcrumb: true,
  },
  workspace: {
    preset: 'workspace',
    layout: 'workspace',
    navigationMode: 'grouped',
    theme: { density: 'compact', colors: { primary: '#5367d8' } },
    breadcrumb: true,
  },
} satisfies Record<string, ShellPresentation>;

/** Exact application-approved choices; a single value is fixed and has no selector. */
export interface ShellUserOptions {
  presets: readonly ('admin' | 'workspace')[];
  layouts: readonly ShellLayout[];
  navigationModes: readonly ShellNavigationMode[];
  densities: readonly ('comfortable' | 'compact')[];
  modes: readonly ('light' | 'dark')[];
}
