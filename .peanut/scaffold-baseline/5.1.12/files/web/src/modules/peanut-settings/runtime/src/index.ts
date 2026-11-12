export const SETTINGS_PACKAGE =
  '@peanut-admin/peanut-settings/runtime' as const;
export const SETTINGS_VERSION = '0.1.0' as const;

export { default as SettingsPage } from './SettingsPage.vue';

export {
  createSettingsFetchTransport,
  groupSettingRecords,
  parseSettingRecord,
  parseSettingResponse,
  parseSettingsList,
  settingEditorKind,
} from './contracts';
export type {
  ReplaceSettingRequest,
  SettingEditorKind,
  SettingGroup,
  SettingPrecondition,
  SettingRecord,
  SettingScalar,
  SettingSchema,
  SettingSourceScope,
  SettingsFetchTransportOptions,
  SettingsTransport,
  SettingsTransportResult,
  UnsetSettingRequest,
} from './contracts';
export {
  createSettingsRuntime,
  SETTINGS_MANAGE_PERMISSION,
  SETTINGS_MODULE_KEY,
  SETTINGS_READ_PERMISSION,
  SETTINGS_ROUTE_NAME,
  SETTINGS_ROUTE_PATH,
  SETTINGS_STORE_KEY,
  settingsRuntimeKey,
  useSettingsRuntime,
} from './runtime';
export type {
  SettingConflictState,
  SettingFormState,
  SettingRequestError,
  SettingsRuntime,
  SettingsRuntimeOptions,
  SettingsRuntimeState,
} from './runtime';
