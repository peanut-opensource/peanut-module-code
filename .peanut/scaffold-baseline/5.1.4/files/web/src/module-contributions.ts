import type {
  AdminModuleShellSlotContribution,
  ModuleFrontendContribution as BaseModuleFrontendContribution,
} from '@peanut-admin/vue';

export interface ModuleFrontendContribution extends BaseModuleFrontendContribution {
  shellSlots?: readonly AdminModuleShellSlotContribution[];
  locales?: Partial<Record<'en-US' | 'zh-CN', Record<string, string>>>;
}
