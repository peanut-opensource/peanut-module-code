import type { PluginFrontendContribution } from '@peanut-admin/vue';

export interface ModuleFrontendContribution extends PluginFrontendContribution {
  locales?: Partial<Record<'en-US' | 'zh-CN', Record<string, string>>>;
}
