import type {
  AdminModuleShellSlotContribution,
  PluginFrontendContribution,
} from '@peanut-admin/vue';

export interface ModuleFrontendContribution extends PluginFrontendContribution {
  shellSlots?: readonly AdminModuleShellSlotContribution[];
  locales?: Partial<Record<'en-US' | 'zh-CN', Record<string, string>>>;
}
