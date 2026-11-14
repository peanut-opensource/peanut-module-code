import type {
  AdminModuleShellSlotContribution,
  ModuleFrontendContribution as BaseModuleFrontendContribution,
} from '@peanut-admin/vue';
import type { FileProvider } from '@/api/file-provider';

export interface ModuleFrontendContribution extends BaseModuleFrontendContribution {
  shellSlots?: readonly AdminModuleShellSlotContribution[];
  locales?: Partial<Record<'en-US' | 'zh-CN', Record<string, string>>>;
  fileProvider?: FileProvider;
}
