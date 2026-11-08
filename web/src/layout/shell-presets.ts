import type {
  ShellLayout,
  ShellNavigationMode,
  ShellThemeInput,
} from '@peanut-admin/ui-vue';

export type ThemeColorPresetName = 'enterprise' | 'technology' | 'operations';

/** Presentation only; menus, action bindings and identity remain host-owned. */
export interface ShellPresentation {
  preset: 'admin' | 'workspace';
  layout: ShellLayout;
  navigationMode: ShellNavigationMode;
  theme: ShellThemeInput;
  colorPreset?: ThemeColorPresetName;
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
  colorPresets?: readonly ThemeColorPresetName[];
  presets: readonly ('admin' | 'workspace')[];
  layouts: readonly ShellLayout[];
  navigationModes: readonly ShellNavigationMode[];
  densities: readonly ('comfortable' | 'compact' | 'spacious')[];
  modes: readonly ('light' | 'dark')[];
}

export const defaultShellUserOptions = {
  colorPresets: ['enterprise', 'technology', 'operations'],
  presets: ['admin', 'workspace'],
  layouts: ['admin', 'workspace'],
  navigationModes: ['tree', 'grouped', 'top'],
  densities: ['comfortable', 'compact', 'spacious'],
  modes: ['light', 'dark'],
} satisfies ShellUserOptions;

export const themeTones = [
  'primary',
  'success',
  'warning',
  'danger',
  'info',
] as const;
export type ThemeTone = (typeof themeTones)[number];
type SurfaceTone = 'background' | 'surface' | 'text' | 'mutedText' | 'border';

export const themeColorPresets = {
  enterprise: {
    colors: {
      primary: '#165dff',
      success: '#15803d',
      warning: '#b45309',
      danger: '#b91c1c',
      info: '#475569',
    },
    light: {
      background: '#edf2f8',
      surface: '#ffffff',
      text: '#1f2937',
      mutedText: '#64748b',
      border: '#d2dbe8',
    },
    dark: {
      background: '#101722',
      surface: '#1a2433',
      text: '#e2e8f0',
      mutedText: '#9aa9bf',
      border: '#3c4a60',
    },
  },
  technology: {
    colors: {
      primary: '#4f46e5',
      success: '#047857',
      warning: '#c2410c',
      danger: '#be123c',
      info: '#525d7d',
    },
    light: {
      background: '#f0f0f8',
      surface: '#ffffff',
      text: '#25253d',
      mutedText: '#67677c',
      border: '#d8d8e8',
    },
    dark: {
      background: '#151526',
      surface: '#222238',
      text: '#e9e9f4',
      mutedText: '#a6a6c0',
      border: '#44445d',
    },
  },
  operations: {
    colors: {
      primary: '#047857',
      success: '#15803d',
      warning: '#a16207',
      danger: '#b91c1c',
      info: '#526476',
    },
    light: {
      background: '#eef5f3',
      surface: '#ffffff',
      text: '#1f3530',
      mutedText: '#61736d',
      border: '#d4e1dc',
    },
    dark: {
      background: '#101e1a',
      surface: '#1a2c25',
      text: '#e3f0e9',
      mutedText: '#9eb6aa',
      border: '#3b5649',
    },
  },
} satisfies Record<
  ThemeColorPresetName,
  {
    colors: Record<ThemeTone, string>;
    light: Record<SurfaceTone, string>;
    dark: Record<SurfaceTone, string>;
  }
>;
