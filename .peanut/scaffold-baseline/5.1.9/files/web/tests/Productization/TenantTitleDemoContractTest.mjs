import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../..'
);
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const expect = (condition, message) => {
  if (!condition) throw new Error(message);
};

const brand = read('web/src/store/modules/brand/index.ts');
expect(
  brand.includes('`${tenantName} - ${website.name}`'),
  'document title omits the current Tenant name'
);
expect(
  brand.includes('setTenantName'),
  'brand store cannot update the Tenant title'
);
expect(
  brand.includes('entryTenantName'),
  'bound entry Tenant title is not retained before login or after logout'
);
expect(
  brand.includes('this.tenantName || this.entryTenantName'),
  'current and bound entry Tenant title precedence changed'
);
expect(
  brand.includes('this.setEntryTenantName(data.tenantName)'),
  'public Host binding does not initialize the Tenant title'
);

const user = read('web/src/store/modules/user/index.ts');
expect(
  user.includes('brandStore.setTenantName(res.data.tenantName)'),
  'user info does not restore the Tenant title'
);
expect(
  user.includes('brandStore.setTenantName()'),
  'logout does not clear the Tenant title'
);
expect(
  user.includes("tenantName: ''") && user.includes('demoMode: false'),
  'Tenant and demo state do not reset deterministically'
);
const userTypes = read('web/src/store/modules/user/types.ts');
expect(
  userTypes.includes('tenantName: string;') &&
    userTypes.includes('demoMode: boolean;'),
  'Tenant and demo reset fields remain optional in the state contract'
);

const publicConfig = read('server/app/api/logic/IndexLogic.php');
expect(
  publicConfig.includes('TenantEntryBindingResolver::ADMIN_CLIENT'),
  'public brand config ignores the bound Admin Host'
);
expect(
  publicConfig.includes("'tenantName' => self::entryTenantName()"),
  'public brand config omits the bound Tenant name'
);

const login = read('web/src/views/login/components/login-form.vue');
expect(
  login.includes('brandStore.demo'),
  'demo login credentials are not sourced from explicit runtime config'
);
expect(
  login.includes('userInfo.username = demo.email'),
  'demo email is not prefilled'
);
expect(
  login.includes('userInfo.password = demo.password'),
  'demo password is not prefilled'
);

const settings = read('web/src/views/user/setting/index.vue');
expect(
  settings.includes(':disabled="userStore.demoMode"'),
  'demo password controls remain editable'
);

const loginController = read(
  'server/app/adminapi/controller/auth/LoginController.php'
);
expect(
  loginController.includes('switchable_tenant_count') &&
    loginController.includes('DemoAccountPolicy::isDemoEmail'),
  'Tenant switch or demo-account capability is not account-specific'
);

console.log('TENANT-TITLE-DEMO-CONTRACT-001 passed');
