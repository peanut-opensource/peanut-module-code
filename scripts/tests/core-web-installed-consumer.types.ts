// Copy into the same task-owned native consumer as the runtime probe.
// Compile with the installed TypeScript compiler and NodeNext resolution.
import { defineShellHostConfig, tabFromRoute } from '@peanut-admin/ui-vue';
import type { ShellHostConfigInput, ShellTab } from '@peanut-admin/ui-vue';

const input: ShellHostConfigInput = {
  brand: { name: 'Fixture', mark: 'F' },
  audiences: { tenant: { label: 'Tenant' }, platform: { label: 'Platform' } },
  commands: { switchTenantLabel: 'Switch', logoutLabel: 'Exit' },
};
const config = defineShellHostConfig(input);
const tab: ShellTab = tabFromRoute({ name: 'fixture', fullPath: '/fixture' });
const title: string = tab.title;

// These must remain errors: unresolved declaration imports must not become any.
// @ts-expect-error the exported tab name is a string
const invalidName: number = tab.name;
// @ts-expect-error the exported path input is a string
tabFromRoute({ name: 'fixture', fullPath: 42 });
// @ts-expect-error normalized host configuration is readonly
config.brand.name = 'changed';

void [title, invalidName];
