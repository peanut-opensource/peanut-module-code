import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import axios from 'axios';
import * as vue from 'vue';
import { compileScript, parse } from 'vue/compiler-sfc';
import * as peanutClient from '@peanut-admin/client';
import * as peanutVue from '@peanut-admin/vue';
import { transformWithEsbuild } from 'vite';

const path = '../src/components/RoleAuthorizationPreview.vue';
const filename = fileURLToPath(new URL(path, import.meta.url));
const { descriptor, errors } = parse(readFileSync(filename, 'utf8'), {
  filename,
});
assert.deepEqual(errors, []);

const modules = [
  'platform-shell-definitions',
  'tenant-license',
  'permission-bundles',
  'role-authorization-preview',
  'platform',
].map((name) => {
  const directory = name === 'platform-shell-definitions' ? 'core' : 'api';
  const file = fileURLToPath(new URL(`../src/${directory}/${name}.ts`, import.meta.url));
  return [name, readFileSync(file, 'utf8'), file];
});
modules.push(['preview-panel', compileScript(descriptor, { id: 'g18' }).content, filename]);
const transpiled = await Promise.all(
  modules.map(async ([name, source, file]) => {
    const { code } = await transformWithEsbuild(source, file, {
      loader: 'ts',
      format: 'cjs',
      define: {
        'import.meta.env.VITE_API_BASE_URL': 'undefined',
        'import.meta.hot': 'undefined',
      },
    });
    return [name, code, file];
  })
);

const previewPath = '/platformapi/roles/permissions/preview';
const catalogAuditPath = '/platformapi/roles/permissions/catalog-audit';
const legacyPath = '/platformapi/roles/permissions/replace';
const operatorsPath = '/platformapi/operators';
const createOperatorPath = '/platformapi/operators/create';
const rolesPath = '/platformapi/roles';
const createRolePath = '/platformapi/roles/create';
const moduleTaskPath = '/platformapi/instance-tools/modules/tasks/{task_key}';
const tenantsPath = '/platformapi/tenants';
const tenantProvisionPath = '/platformapi/tenants/provision';
const tenantDetailPath = '/platformapi/tenants/detail';
const auditPath = '/platformapi/audit';
const invitationsPath = tenantsPath + '/invitations';
const tenantOwnerPath = tenantsPath + '/owner';
const tenantLicensePath = tenantsPath + '/license';
const tenantModulesPath = tenantsPath + '/modules';
const entryBindingsPath = '/platformapi/tenant-entry-bindings';
const plansPath = '/platformapi/plans';
const developerCatalogPath = '/platformapi/developer-center/catalog';
const instanceModulesPath = '/platformapi/instance-tools/modules';
const storagePath = '/platformapi/infrastructure/storage';
const fileOwner = 'peanut.file';
const storageReads = [
  ['platform.storage.account.list', 'accounts'],
  ['platform.storage.space.list', 'spaces'],
  ['platform.storage.route.list', 'routes'],
];
const opsPath = '/platformapi/v1/ops';
const opsReadPages = [
  ['platform.ops.console', 'ops', 'status'],
  ['platform.ops.maintenance', 'maintenance', 'maintenance'],
  ['platform.ops.backups', 'backups', 'backups'],
  ['platform.ops.providers', 'providers', 'providers'],
];
const opsAlternatePages = [
  ['platform.upgrade.status', 'upgrades', [
    ['platform.upgrade.readiness.read', 'upgrade-readiness'],
    ['platform.upgrade.history.read', 'upgrades'],
  ]],
  ['platform.ops.diagnostics', 'diagnostics', [
    ['platform.ops.logs.read', 'logs'],
    ['platform.ops.diagnostics.download', 'diagnostics'],
  ]],
];
const permissionCatalogPath = '/platformapi/permissions';
const permissionBundlesPath = '/platformapi/permission/bundles';
const permissionApisPath = '/platformapi/permission/apis';
const permissionMenusPath = '/platformapi/permission/menus';
const permissionApiBindingsPath = permissionApisPath + '/bindings';
const permissionMenuBindingPath = permissionMenusPath + '/binding';
const rolePermissionAssignNode = [
  'platform.role.permission.assign', 'platform.governance.workbench', 'action',
  [['POST', legacyPath], ['GET', permissionCatalogPath]],
];
const permissionApiBindingNodes = [
  ['platform.permission.api.binding.read', 'platform.permission.api.list', 'operation',
    [['GET', permissionApiBindingsPath], ['GET', permissionBundlesPath]]],
  ['platform.permission.api.binding.update', 'platform.permission.api.list', 'action',
    [['PUT', permissionApiBindingsPath], ['GET', permissionApiBindingsPath], ['GET', permissionBundlesPath]]],
];
const permissionMenuBindingNode = [
  'platform.permission.menu.binding.update', 'platform.permission.menu.list', 'action',
  [['PUT', permissionMenuBindingPath], ['GET', permissionBundlesPath]],
];
const sharedPreviewNode = [
  'platform.role.authorization.preview', null, 'operation',
  [['POST', previewPath], ['GET', operatorsPath], ['GET', rolesPath], ['GET', permissionApisPath]],
];
const sharedCatalogNode = [
  'platform.role.authorization.catalog-audit', null, 'operation',
  [['POST', catalogAuditPath], ['GET', operatorsPath], ['GET', rolesPath], ['GET', permissionApisPath]],
];
const opsMaintenanceActions = [
  ['platform.ops.maintenance.schedule', 'platform.ops.maintenance', 'action',
    [['PUT', opsPath + '/maintenance'], ['GET', opsPath + '/maintenance']]],
  ['platform.ops.maintenance.close', 'platform.ops.maintenance', 'action',
    [['POST', opsPath + '/maintenance/{maintenance_key}/close'], ['GET', opsPath + '/maintenance']]],
];
const opsBackupActions = [
  ['platform.ops.backup.create', 'platform.ops.backups', 'action', [['POST', opsPath + '/tasks/backup']]],
  ['platform.ops.restore.verify', 'platform.ops.backups', 'action', [['POST', opsPath + '/tasks/restore']]],
  ['platform.ops.task.read', 'platform.ops.backups', 'operation', [['GET', opsPath + '/tasks/{task_key}']]],
];
const opsUpgradeExecuteNode = [
  'platform.ops.upgrade.execute', 'platform.upgrade.status', 'action',
  [['POST', opsPath + '/tasks/upgrade'], ['GET', opsPath + '/upgrade-readiness'], ['GET', opsPath + '/upgrades']],
];
const storageActionNodes = [
  ['platform.storage.account.create', 'platform.storage.account.list', 'action',
    [['POST', storagePath + '/account']]],
  ...['fine', 'legacy'].map((variant) => [
    'platform.storage.space.create.' + variant, 'platform.storage.space.list', 'action',
    [['POST', storagePath + '/space'], ['GET', variant === 'fine' ? storagePath + '/accounts' : storagePath]],
  ]),
  ...['fine', 'legacy'].map((variant) => [
    'platform.storage.route.update.' + variant, 'platform.storage.route.list', 'action',
    [['POST', storagePath + '/route'], ['GET', variant === 'fine' ? storagePath + '/spaces' : storagePath]],
  ]),
  ['platform.storage.legacy.read', 'platform.storage', 'operation', [['GET', storagePath]]],
];
const ownerInvitationsPage = 'platform.tenant.owner-invitation.list';
const entryBindingsPage = 'platform.tenant.entry-binding.list';
const entitlementsPage = 'platform.tenant.entitlements';
const plansPage = 'platform.plan.list';
const developerPage = 'platform.developer.catalog';
const tenancyNodes = [
  [ownerInvitationsPage, 'platform.tenancy', 'page', [['GET', tenantsPath], ['GET', invitationsPath]]],
  ['platform.tenant.owner.read', ownerInvitationsPage, 'operation', [['GET', tenantOwnerPath]]],
  ...['create', 'resend', 'revoke'].map((action) => [
    'platform.tenant.owner-invitation.' + action, ownerInvitationsPage, 'action',
    [['POST', action === 'create' ? invitationsPath : invitationsPath + '/' + action]],
  ]),
  [entryBindingsPage, 'platform.tenancy', 'page', [['GET', tenantsPath], ['GET', entryBindingsPath]]],
  ...['enable', 'disable'].map((action) => [
    'platform.tenant.entry-binding.' + action, entryBindingsPage, 'action',
    [['POST', entryBindingsPath + '/' + action]],
  ]),
  [entitlementsPage, 'platform.tenancy', 'page',
    [['GET', tenantsPath], ['GET', tenantLicensePath], ['GET', tenantModulesPath]]],
  ['platform.tenant.license.update', entitlementsPage, 'action', [['PUT', tenantLicensePath], ['GET', plansPath]]],
  [plansPage, 'platform.tenancy', 'page', [['GET', plansPath]]],
  ...[['create', 'POST'], ['update', 'PUT']].map(([action, method]) => [
    'platform.plan.' + action, plansPage, 'action',
    [[method, plansPath], ['GET', instanceModulesPath], ['GET', permissionBundlesPath]],
  ]),
];
const developerNodes = [
  ['platform.developer.tools', null, 'group', []],
  [developerPage, 'platform.developer.tools', 'page', [['GET', developerCatalogPath]]],
  ['platform.developer.runtime.read', developerPage, 'operation', [['GET', instanceModulesPath]]],
  ['platform.developer.official-updates.read', developerPage, 'operation', [['GET', instanceModulesPath + '/updates']]],
  ...[['upload-install', 'install'], ['upload-update', 'update'],
    ['official-install', 'official-install'], ['official-update', 'official-update']].map(([key, path]) => [
    'platform.developer.module.' + key, developerPage, 'action', [['POST', instanceModulesPath + '/' + path]],
  ]),
];
const updateOperatorPath = '/platformapi/operators/update';
const replaceOperatorRolesPath = '/platformapi/operators/roles/replace';
const updateRolePath = '/platformapi/roles/update';
const archiveRolePath = '/platformapi/roles/archive';
const operatorTransitions = ['activate', 'suspend', 'close'];
const apiId = 'a'.repeat(64);
const group = {
  key: 'platform.candidate',
  parent_key: null,
  type: 'group',
  api_ids: [],
};
const page = {
  key: 'platform.candidate.page',
  parent_key: group.key,
  type: 'page',
  api_ids: [apiId],
};
const result = {
  operator_id: '1',
  capability_equal: true,
  relation_coverage_complete: false,
  owner_dynamic: true,
  added: [],
  removed: [],
  non_http_permission_keys: ['platform.local.operation'],
  roles: [{
    role_id: '2',
    relation_equal: false,
    owner_dynamic: true,
    added: [],
    removed: [apiId],
  }],
  unresolved: [{ code: 'role_assignment_missing', role_id: '3' }],
};

const plain = (data) => JSON.parse(JSON.stringify(data));
function registeredPlatformApi(row) {
  assert.equal(row.application, 'platform');
  assert.equal(row.audience, 'platform');
  assert.equal(row.auth.mode, 'platform_permission');
  assert.equal(row.contract_quality, 'complete');
  return {
    id: row.api_id,
    method: row.method,
    path: row.path,
    audience: row.audience,
    module_key: row.owner.key,
    access_mode: row.auth.mode,
    status: 'active',
    default_permission_keys: row.default_permission_keys,
    prerequisite_permission_keys: row.prerequisite_permission_keys,
  };
}

function nativePlatformApis() {
  const source = process.env.PEANUT_PLATFORM_PREVIEW_API_CATALOG ||
    new URL('../../server/generated/api-catalog.json', import.meta.url);
  const { endpoints } = JSON.parse(readFileSync(source, 'utf8'));
  const references = [
    ['GET', rolesPath],
    ['POST', createRolePath],
    ['GET', moduleTaskPath],
    ['GET', operatorsPath],
    ['POST', createOperatorPath],
    ['GET', permissionCatalogPath],
    ['GET', permissionBundlesPath],
    ['POST', permissionBundlesPath],
    ['PUT', permissionBundlesPath],
    ['GET', permissionApisPath],
    ['GET', permissionMenusPath],
    ['POST', updateOperatorPath],
    ['POST', replaceOperatorRolesPath],
    ...operatorTransitions.map((action) => ['POST', operatorsPath + '/' + action]),
    ['POST', updateRolePath],
    ['POST', archiveRolePath],
    ['GET', tenantsPath],
    ['GET', tenantDetailPath],
    ['POST', tenantProvisionPath],
    ...['activate', 'suspend', 'close'].map((action) => ['POST', tenantsPath + '/' + action]),
    ['GET', auditPath],
    ...opsReadPages.map(([, , endpoint]) => ['GET', opsPath + '/' + endpoint]),
    ...opsAlternatePages.flatMap(([, , alternatives]) =>
      alternatives.map(([, endpoint]) => ['GET', opsPath + '/' + endpoint])
    ),
    ...[...tenancyNodes, ...developerNodes].flatMap((node) => node[3]),
    ...[rolePermissionAssignNode, ...permissionApiBindingNodes, permissionMenuBindingNode, sharedPreviewNode, sharedCatalogNode,
      ...opsMaintenanceActions, ...opsBackupActions, opsUpgradeExecuteNode].flatMap((node) => node[3]),
  ];
  return [...new Map(references.map(([method, path]) => [method + ' ' + path, [method, path]])).values()]
    .map(([method, path]) => {
      const matches = endpoints.filter((row) => row.path === path && row.method === method);
      assert.equal(matches.length, 1, method + ' ' + path);
      return registeredPlatformApi(matches[0]);
    });
}

function fileCatalogApis() {
  const source = process.env.PEANUT_PLATFORM_PREVIEW_FILE_API_CATALOG;
  if (source) {
    const { endpoints } = JSON.parse(readFileSync(source, 'utf8'));
    return endpoints
      .filter((row) => row.audience === 'platform' && row.owner.key === fileOwner)
      .map((row) => {
        assert.equal(row.owner.type, 'module');
        return registeredPlatformApi(row);
      });
  }

  // A standalone Code checkout can run the behavior checks without an assembled Module catalog.
  return [
    ...storageReads.map(([, section]) => ['GET', storagePath + '/' + section]),
    ['GET', storagePath],
    ['POST', storagePath + '/account'],
    ['POST', storagePath + '/space'],
    ['POST', storagePath + '/route'],
  ].map(([method, path], index) => ({
    id: (index + 1).toString(16).padStart(64, '0'),
    method,
    path,
    audience: 'platform',
    module_key: fileOwner,
    access_mode: 'platform_permission',
    status: 'active',
    default_permission_keys: [],
    prerequisite_permission_keys: [],
  }));
}

const reply = (config, data, code = 20000, status = 200, msg = '') => ({
  config,
  status,
  headers: {},
  data: { code, msg, data },
});

function fixture(t, adapter) {
  const storage = new Map();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key) => storage.get(key) ?? null,
      setItem: (key, value) => storage.set(key, value),
      removeItem: (key) => storage.delete(key),
    },
  });
  Object.defineProperty(navigator, 'locks', {
    configurable: true,
    value: { request: (_name, operation) => operation() },
  });
  t.after(() => {
    delete globalThis.localStorage;
    delete navigator.locks;
  });

  let start;
  const dependencies = {
    axios: { ...axios, create: (config) => axios.create({ ...config, adapter }) },
    vue: { ...vue, onMounted: (callback) => { start = callback; } },
    '@peanut-admin/client': peanutClient,
    '@peanut-admin/vue': peanutVue,
  };
  for (const [name, code, filename] of transpiled) {
    const module = { exports: {} };
    runInNewContext(code, {
      module,
      Error,
      Headers,
      TextDecoder,
      URLSearchParams,
      crypto,
      require(dependency) {
        if (dependency === '../api/platform') return dependencies['./platform'];
        if (dependency === '../core/platform-shell-definitions')
          return dependencies['./platform-shell-definitions'];
        if (dependency === '../api/role-authorization-preview')
          return dependencies['./role-authorization-preview'];
        assert.ok(Object.hasOwn(dependencies, dependency), dependency);
        return dependencies[dependency];
      },
    }, { filename });
    dependencies['./' + name] = module.exports;
  }

  let allowed = true;
  const platform = dependencies['./platform'];
  const contract = dependencies['./role-authorization-preview'];
  const canPreview = () => allowed;
  return {
    api: platform.api,
    createPlatformOpsTransport: platform.createPlatformOpsTransport,
    session: platform.getPlatformSessionSnapshot,
    onSessionChange: platform.onPlatformSessionChange,
    contract,
    revoke: () => { allowed = false; },
    async mount(useOfficialDirectory = false, mode = 'manual') {
      const scope = vue.effectScope();
      const state = scope.run(() =>
        dependencies['./preview-panel'].default.setup({
          canPreview,
          canCatalogAudit: () => allowed,
          loadDirectory: useOfficialDirectory
            ? (signal) => contract.readRoleAuthorizationPreviewDirectory(platform.api, canPreview, signal)
            : async () => ({
            operators: [
              { id: '1', role_keys: ['editor', 'reviewer'] },
              { id: '4', role_keys: ['reviewer'] },
            ],
            roles: [
              { id: '2', key: 'editor', name: 'Editor', revision: 1, status: 'active' },
              { id: '3', key: 'reviewer', name: 'Reviewer', revision: 3, status: 'active' },
            ],
            apis: [],
          }),
        }, { emit: (event) => { if (event === 'close') scope.stop(); }, expose: () => {} })
      );
      t.after(() => scope.stop());
      await start();
      if (!useOfficialDirectory) {
        state.operatorId.value = '1';
        state.roleId.value = '2';
      }
      if (mode === 'manual') {
        state.candidateMode.value = mode;
        state.candidateText.value = JSON.stringify([group, page]);
      }
      return state;
    },
  };
}

test('the default preview projects the real Platform Shell branches and a no-menu operation', async (t) => {
  const apis = nativePlatformApis();
  const node = (key, parent_key, type, endpoints = []) => ({
    key,
    parent_key,
    type,
    api_ids: endpoints.map(([method, path]) => {
      const api = apis.find((row) => row.method === method && row.path === path);
      assert.ok(api, method + ' ' + path);
      return api.id;
    }).sort(),
  });
  const previewCalls = [];
  const taskKey = 'synthetic/key?x=1';
  const taskUrl = moduleTaskPath.replace('{task_key}', encodeURIComponent(taskKey));
  const roleInput = { key: 'platform.synthetic', name: 'Synthetic' };
  const operatorInput = { email: 'operator@example.test', display_name: 'Synthetic' };
  const bundleInput = { key: 'platform.synthetic', name: 'Synthetic', audience: 'platform' };
  const bundleUpdate = {
    permission_key: bundleInput.key,
    name: bundleInput.name,
    status: 'active',
    expected_revision: 'r1',
  };
  const bundle = {
    ...bundleInput,
    module_key: 'platform',
    status: 'active',
    revision: 'r1',
    api_ids: [],
    configured: false,
    source: 'custom',
  };
  const { api, mount } = fixture(t, async (config) => {
    if (config.method === 'get') {
      if (config.url === operatorsPath)
        return reply(config, {
          count: 1,
          lists: [{ id: '1', display_name: 'Operator', role_keys: ['editor', 'reviewer'] }],
        });
      if (config.url === rolesPath)
        return reply(config, {
          count: 2,
          lists: [
            { id: '2', key: 'editor', name: 'Editor', revision: 1, status: 'active' },
            { id: '3', key: 'reviewer', name: 'Reviewer', revision: 3, status: 'active' },
          ],
        });
      if (config.url === taskUrl) return reply(config, { task_key: taskKey });
      if (config.url === permissionCatalogPath) {
        assert.deepEqual(plain(config.params), { page: 2, page_size: 20 });
        return reply(config, { lists: [], count: 0 });
      }
      if (config.url === permissionBundlesPath) {
        assert.deepEqual(plain(config.params), { audience: 'platform', page_no: 2, page_size: 100 });
        return reply(config, { items: [bundle], total: 1 });
      }
      assert.equal(config.url, permissionApisPath);
      assert.equal(config.params.audience, 'platform');
      return reply(config, { items: apis, total: apis.length });
    }
    if (config.url === permissionBundlesPath) {
      assert.ok(config.method === 'post' || config.method === 'put');
      assert.deepEqual(JSON.parse(config.data), config.method === 'post' ? bundleInput : bundleUpdate);
      return reply(config, bundle);
    }
    assert.equal(config.method, 'post');
    if (config.url === createRolePath) {
      assert.deepEqual(JSON.parse(config.data), roleInput);
      return reply(config, null);
    }
    if (config.url === createOperatorPath) {
      assert.deepEqual(JSON.parse(config.data), operatorInput);
      return reply(config, null);
    }
    assert.equal(config.url, previewPath);
    previewCalls.push(JSON.parse(config.data));
    return reply(config, result);
  });
  const panel = await mount(true, 'shell');
  assert.equal(panel.candidateMode.value, 'shell');
  assert.equal(panel.candidateText.value, '');
  assert.match(panel.candidate.value.notice, /当前 Platform API 目录未见 File 存储来源/);
  const [tenancy, groupNode, previewOperation, catalogAuditOperation, operations, developerTools, operation] =
    panel.candidate.value.data.tree;
  const [tenantsPage] = tenancy.children;
  for (const [key, view] of opsReadPages) {
    const opsPage = operations.children.find((child) => child.key === key);
    assert.ok(opsPage, key);
    assert.ok(opsPage.source.endsWith('#/' + view));
  }
  for (const [key, view, alternatives] of opsAlternatePages) {
    const opsPage = operations.children.find((child) => child.key === key);
    assert.ok(opsPage, key);
    assert.match(opsPage.source, /入口择一，显式选择下方读取能力/);
    assert.ok(opsPage.source.includes('#/' + view));
    assert.equal(opsPage.disabled, true);
    assert.deepEqual(plain(opsPage.api_ids), []);
    const readOptions = opsPage.children.filter((child) => child.type === 'operation');
    assert.deepEqual(plain(readOptions.map((child) => child.key)), alternatives.map(([key]) => key));
    assert.ok(readOptions.every((child) => !child.disabled));
  }
  const auditPage = operations.children.find((child) => child.key === 'platform.audit.list');
  assert.equal(tenancy.label, '租户运营');
  assert.match(tenantsPage.source, /#\/tenants$/);
  assert.deepEqual(
    plain(tenantsPage.children.map((child) => child.label)),
    ['新建租户', '详情', '启用', '暂停', '关闭']
  );
  assert.ok(tenantsPage.children.every((child) => child.source.endsWith(tenantsPage.label)));
  assert.deepEqual(plain(tenancy.children.map((child) => child.key)), [
    'platform.tenant.list', ownerInvitationsPage, entryBindingsPage, entitlementsPage, plansPage,
  ]);
  assert.equal(operations.label, '运维与审计');
  assert.match(auditPage.source, /#\/audit$/);
  assert.equal(developerTools.key, 'platform.developer.tools');
  assert.equal(developerTools.label, '开发者工具');
  const developer = developerTools.children.find((child) => child.key === developerPage);
  assert.match(developer.source, /#\/developer$/);
  assert.deepEqual(plain(developer.children.map((child) => child.key)),
    developerNodes.slice(2).map(([key]) => key));
  const [operatorsNode, pageNode, workbench, bundlesPage, apisPage, menusPage] =
    groupNode.children;
  const [operatorAction] = operatorsNode.children;
  const [action] = pageNode.children;
  assert.equal(groupNode.label, '组织与权限');
  assert.match(groupNode.source, /侧栏分组/);
  assert.equal(groupNode.disabled, true);
  assert.equal(operatorsNode.label, '实例平台操作员');
  assert.match(operatorsNode.source, /#\/operators$/);
  assert.equal(operatorAction.label, '创建操作员');
  assert.match(operatorAction.source, /实例平台操作员$/);
  assert.equal(pageNode.label, '平台角色');
  assert.match(pageNode.source, /#\/roles$/);
  assert.equal(action.label, '创建角色');
  assert.match(action.source, /平台角色$/);
  assert.match(workbench.source, /#\/role-permissions$/);
  assert.equal(workbench.api_ids.length, 2);
  assert.deepEqual(plain(bundlesPage.children.map((child) => child.label)), ['创建权限包', '编辑']);
  assert.match(apisPage.source, /#\/permission-apis$/);
  assert.match(menusPage.source, /#\/permission-menus$/);
  assert.deepEqual(plain(menusPage.children.map((child) => child.key)), [permissionMenuBindingNode[0]]);
  assert.equal(previewOperation.label, '只读候选预览');
  assert.equal(previewOperation.api_ids.length, 4);
  assert.equal(catalogAuditOperation.label, '全角色目录只读审计');
  assert.equal(catalogAuditOperation.api_ids.length, 4);
  assert.equal(operation.label, '读取模块操作任务');
  assert.match(operation.source, /开发者中心.*模块任务回执/);
  assert.equal(panel.describeApis(operation.api_ids), 'GET ' + moduleTaskPath);
  assert.equal(previewCalls.length, 0);

  panel.operatorId.value = '1';
  panel.roleId.value = '2';
  panel.setRoleSelection([]);
  await panel.runPreview();
  assert.deepEqual(previewCalls, [{
    operator_id: '1',
    nodes: [
      node('platform.tenancy', null, 'group'),
      node('platform.tenant.list', 'platform.tenancy', 'page', [['GET', tenantsPath]]),
      node('platform.tenant.create', 'platform.tenant.list', 'action', [['POST', tenantProvisionPath]]),
      node('platform.tenant.detail', 'platform.tenant.list', 'action', [['GET', tenantDetailPath]]),
      ...['activate', 'suspend', 'close'].map((action) => node(
        'platform.tenant.' + action,
        'platform.tenant.list',
        'action',
        [['POST', tenantsPath + '/' + action]]
      )),
      ...tenancyNodes.map((definition) => node(...definition)),
      node('platform.governance', null, 'group'),
      node('platform.operator.list', 'platform.governance', 'page', [['GET', operatorsPath]]),
      node('platform.operator.create', 'platform.operator.list', 'action', [['POST', createOperatorPath]]),
      node('platform.operator.update', 'platform.operator.list', 'action', [['POST', updateOperatorPath]]),
      node('platform.operator.role.assign', 'platform.operator.list', 'action', [['GET', rolesPath], ['POST', replaceOperatorRolesPath]]),
      ...operatorTransitions.map((action) => node(
        'platform.operator.' + action,
        'platform.operator.list',
        'action',
        [['POST', operatorsPath + '/' + action]]
      )),
      node('platform.role.list', 'platform.governance', 'page', [['GET', rolesPath]]),
      node('platform.role.create', 'platform.role.list', 'action', [['POST', createRolePath]]),
      node('platform.role.update', 'platform.role.list', 'action', [['POST', updateRolePath]]),
      node('platform.role.archive', 'platform.role.list', 'action', [['POST', archiveRolePath]]),
      node('platform.governance.workbench', 'platform.governance', 'page', [['GET', rolesPath], ['GET', permissionCatalogPath]]),
      node(...rolePermissionAssignNode),
      node('platform.permission.bundle.list', 'platform.governance', 'page', [['GET', permissionBundlesPath]]),
      node('platform.permission.bundle.create', 'platform.permission.bundle.list', 'action', [['POST', permissionBundlesPath]]),
      node('platform.permission.bundle.update', 'platform.permission.bundle.list', 'action', [['PUT', permissionBundlesPath]]),
      node('platform.permission.api.list', 'platform.governance', 'page', [['GET', permissionApisPath]]),
      ...permissionApiBindingNodes.map((definition) => node(...definition)),
      node('platform.permission.menu.list', 'platform.governance', 'page', [['GET', permissionMenusPath]]),
      node(...permissionMenuBindingNode),
      node(...sharedPreviewNode),
      node(...sharedCatalogNode),
      node('platform.operations', null, 'group'),
      ...opsReadPages.slice(0, 3).map(([key, , endpoint]) => node(
        key, 'platform.operations', 'page', [['GET', opsPath + '/' + endpoint]]
      )).flatMap((page) => [
        page,
        ...[...opsMaintenanceActions, ...opsBackupActions]
          .filter((definition) => definition[1] === page.key)
          .map((definition) => node(...definition)),
      ]),
      ...opsAlternatePages.flatMap(([pageKey, , alternatives]) => [
        node(pageKey, 'platform.operations', 'page'),
        ...alternatives.map(([key, endpoint]) => node(
          key, pageKey, 'operation', [['GET', opsPath + '/' + endpoint]]
        )),
        ...(pageKey === opsUpgradeExecuteNode[1] ? [node(...opsUpgradeExecuteNode)] : []),
      ]),
      node('platform.ops.providers', 'platform.operations', 'page', [['GET', opsPath + '/providers']]),
      ...developerNodes.map((definition) => node(...definition)),
      node('platform.audit.list', 'platform.operations', 'page', [['GET', auditPath]]),
      node('platform.module.operation-task.read', null, 'operation', [['GET', moduleTaskPath]]),
    ],
    role_assignments: [{ role_id: '2', node_keys: [] }],
  }]);
  assert.equal(panel.result.value.capability_equal, true);
  assert.equal(panel.result.value.relation_coverage_complete, false);
  for (const [pageKey, , alternatives] of opsAlternatePages) {
    for (const [key, endpoint] of alternatives) {
      panel.changeCheckedNodes({}, { checkedKeys: [key] });
      await panel.runPreview();
      const payload = previewCalls.at(-1);
      assert.deepEqual(payload.role_assignments, [{ role_id: '2', node_keys: [key] }]);
      assert.deepEqual(
        payload.nodes.filter((item) => item.key === pageKey || item.key === key),
        [node(pageKey, 'platform.operations', 'page'), node(key, pageKey, 'operation', [['GET', opsPath + '/' + endpoint]])]
      );
    }
  }
  await api.createRole(roleInput);
  await api.createOperator(operatorInput);
  assert.deepEqual(await api.permissions(2, 20), { lists: [], count: 0 });
  assert.deepEqual(plain(await api.permissionBundles('platform', 2)), { items: [bundle], total: 1 });
  assert.deepEqual(plain(await api.createPermissionBundle(bundleInput)), bundle);
  assert.deepEqual(plain(await api.updatePermissionBundle(bundleUpdate)), bundle);
  assert.deepEqual(await api.moduleOperationTask(taskKey), { task_key: taskKey });
});

test('existing Platform management actions retain methods, data, and revisions', async (t) => {
  const calls = [];
  const { api } = fixture(t, async (config) => {
    calls.push([config.method, config.url, config.data ? JSON.parse(config.data) : null]);
    return reply(config, config.url === permissionMenusPath ? { items: [], total: 0 } : null);
  });
  const role = { id: 41, revision: 7 };
  const operator = { id: 43, security_revision: 8 };
  const roleChange = { role_id: 41, expected_revision: 7, change_reason: 'synthetic' };
  const operatorChange = { operator_id: 43, expected_revision: 8, change_reason: 'synthetic' };
  await api.updateRole(role, 'Updated role', 'Description', 'synthetic');
  await api.archiveRole(role, 'synthetic');
  await api.updateOperator(operator, 'Display name', 'synthetic');
  await api.replaceOperatorRoles(operator, [41], 'synthetic');
  for (const action of operatorTransitions)
    await api.transitionOperator(action, operator, 'synthetic');
  await api.permissionMenus();
  assert.deepEqual(calls, [
    ['post', updateRolePath, { ...roleChange, name: 'Updated role', description: 'Description' }],
    ['post', archiveRolePath, roleChange],
    ['post', updateOperatorPath, { ...operatorChange, display_name: 'Display name' }],
    ['post', replaceOperatorRolesPath, { ...operatorChange, role_ids: [41] }],
    ...operatorTransitions.map((action) => ['post', operatorsPath + '/' + action, operatorChange]),
    ['get', permissionMenusPath, null],
  ]);
});

test('the tenant lifecycle and audit consumers retain their request contracts', async (t) => {
  const calls = [];
  const invitation = { id: 91, status: 'pending' };
  const { api } = fixture(t, async (config) => {
    calls.push([
      config.method,
      config.url,
      config.params ? plain(config.params) : null,
      config.data ? JSON.parse(config.data) : null,
    ]);
    return reply(config, config.url === tenantProvisionPath ? invitation : null);
  });
  const provision = {
    tenant_code: 'synthetic',
    tenant_name: 'Synthetic tenant',
    owner_email: 'owner@example.test',
    owner_display_name: 'Synthetic owner',
    expires_in_hours: 72,
  };
  const tenant = { id: 42, revision: 7 };
  const change = { tenant_id: 42, expected_revision: 7, change_reason: 'synthetic' };
  await api.tenants(2, 30);
  await api.tenantDetail(tenant.id);
  assert.deepEqual(await api.provision(provision), invitation);
  for (const action of ['activate', 'suspend', 'close'])
    await api.transition(action, tenant, 'synthetic');
  await api.audit(3, 20);
  assert.deepEqual(calls, [
    ['get', tenantsPath, { page: 2, page_size: 30 }, null],
    ['get', tenantDetailPath, { id: 42 }, null],
    ['post', tenantProvisionPath, null, provision],
    ['post', tenantsPath + '/activate', null, change],
    ['post', tenantsPath + '/suspend', null, change],
    ['post', tenantsPath + '/close', null, change],
    ['get', auditPath, { page: 3, page_size: 20 }, null],
  ]);
});

test('the single-read Ops pages preserve host request and response contracts', async (t) => {
  const calls = [];
  const { api, createPlatformOpsTransport } = fixture(t, async (config) => {
    calls.push([
      config.method,
      config.url,
      config.params ? plain(config.params) : null,
      config.signal ?? null,
    ]);
    return {
      ...reply(config, { source: config.url }),
      headers: { 'x-request-id': 'synthetic-ops-request' },
    };
  });
  const transport = createPlatformOpsTransport();
  const { signal } = new AbortController();
  for (const [read, path] of [
    [transport.overview, opsPath + '/status'],
    [transport.maintenance, opsPath + '/maintenance'],
  ]) {
    const result = await read(signal);
    assert.equal(result.status, 200);
    assert.equal(result.headers.get('x-request-id'), 'synthetic-ops-request');
    assert.deepEqual(plain(result.body), {
      data: { source: path },
      meta: { request_id: 'synthetic-ops-request' },
    });
  }
  assert.deepEqual(await api.backupCenter(3), { source: opsPath + '/backups' });
  assert.deepEqual(await api.providerQualifications(4), { source: opsPath + '/providers' });
  assert.deepEqual(calls, [
    ['get', opsPath + '/status', null, signal],
    ['get', opsPath + '/maintenance', null, signal],
    ['get', opsPath + '/backups', { page: 3, page_size: 20 }, null],
    ['get', opsPath + '/providers', { page: 4, page_size: 20 }, null],
  ]);
});

test('alternate Ops reads keep each route, request options, and independent transport behavior', async (t) => {
  const calls = [];
  const { api, createPlatformOpsTransport } = fixture(t, async (config) => {
    calls.push([
      config.method,
      config.url,
      config.params ? plain(config.params) : null,
      config.responseType ?? null,
      config.signal ?? null,
    ]);
    if (config.url === opsPath + '/diagnostics')
      return {
        config,
        status: 200,
        headers: {},
        data: new TextEncoder().encode('{"msg":"synthetic diagnostic refusal"}').buffer,
      };
    return reply(config, { route: config.url });
  });
  const transport = createPlatformOpsTransport();
  const { signal } = new AbortController();
  await api.upgradeReadiness();
  await api.upgradeCenter();
  await transport.logs('platform.audit', 'warn', 'progress 7', 20, signal);
  await assert.rejects(api.downloadDiagnostics(360), /synthetic diagnostic refusal/);
  const taskKey = 'synthetic/task?x=1';
  await transport.task(taskKey, signal);
  assert.deepEqual(calls, [
    ['get', opsPath + '/upgrade-readiness', null, null, null],
    ['get', opsPath + '/upgrades', null, null, null],
    ['get', opsPath + '/logs?source=platform.audit&severity=warn&page_size=20&cursor=progress+7', null, null, signal],
    ['get', opsPath + '/diagnostics', { window_minutes: 360 }, 'arraybuffer', null],
    ['get', opsPath + '/tasks/' + encodeURIComponent(taskKey), null, null, signal],
  ]);
});

test('tenant invitations, binding, license, and plan callers preserve their exact request contracts', async (t) => {
  const calls = [];
  const tenantId = 5;
  const plan = {
    id: 7, code: 'synthetic', name: 'Synthetic', status: 'active', revision: 9,
    module_keys: ['peanut.synthetic'], permission_keys: ['synthetic.read'],
  };
  const { api } = fixture(t, async (config) => {
    calls.push([
      config.method, config.url, config.params ? plain(config.params) : null,
      config.data ? JSON.parse(config.data) : null,
    ]);
    if (config.url === tenantLicensePath)
      return reply(config, { tenant_id: tenantId, license: null });
    if (config.url === plansPath)
      return reply(config, config.method === 'get' ? { lists: [plan], count: 1 } : plan);
    if (config.url === permissionBundlesPath)
      return reply(config, { items: [], total: 0 });
    return reply(config, { lists: [], count: 0 });
  });
  const invite = { tenant_id: tenantId, owner_email: 'owner@example.test', expires_in_hours: 48 };
  const enable = { tenant_id: tenantId, host: 'synthetic.example.test', client_key: 'admin-web', change_reason: 'synthetic' };
  const license = {
    tenant_id: tenantId, plan_id: 7, status: 'active', effective_at: '2026-11-01T00:00:00Z',
    expires_at: null, expected_revision: 16, change_reason: 'synthetic',
  };
  const planDetails = {
    name: plan.name, module_keys: plan.module_keys, permission_keys: plan.permission_keys,
    change_reason: 'synthetic',
  };
  const create = { ...planDetails, code: plan.code };
  const update = { ...planDetails, id: 7, expected_revision: 9, status: 'inactive' };
  await api.invitations(tenantId);
  await api.tenantOwner(tenantId);
  await api.inviteOwner(invite);
  await api.resendInvitation(8);
  await api.revokeInvitation(8);
  await api.entryBindings();
  await api.entryBindings(tenantId);
  await api.enableEntryBinding(enable);
  await api.disableEntryBinding(11, 'synthetic disable');
  await api.tenantLicense(tenantId);
  await api.moduleStates(tenantId);
  await api.saveTenantLicense(license);
  await api.plans(2);
  await api.planModules(3);
  await api.permissionBundles('tenant', 4);
  await api.createPlan(create);
  await api.updatePlan(update);
  assert.deepEqual(calls, [
    ['get', invitationsPath, { tenant_id: tenantId, page: 1, page_size: 100 }, null],
    ['get', tenantOwnerPath, { tenant_id: tenantId }, null],
    ['post', invitationsPath, null, invite],
    ['post', invitationsPath + '/resend', null, { invitation_id: 8, expires_in_hours: 72 }],
    ['post', invitationsPath + '/revoke', null, { invitation_id: 8 }],
    ['get', entryBindingsPath, {}, null],
    ['get', entryBindingsPath, { tenant_id: tenantId }, null],
    ['post', entryBindingsPath + '/enable', null, enable],
    ['post', entryBindingsPath + '/disable', null, { binding_id: 11, change_reason: 'synthetic disable' }],
    ['get', tenantLicensePath, { tenant_id: tenantId }, null],
    ['get', tenantModulesPath, { tenant_id: tenantId, page: 1, page_size: 100 }, null],
    ['put', tenantLicensePath, null, license],
    ['get', plansPath, { page: 2, page_size: 100 }, null],
    ['get', instanceModulesPath, { page: 3, page_size: 100 }, null],
    ['get', permissionBundlesPath, { audience: 'tenant', page_no: 4, page_size: 100 }, null],
    ['post', plansPath, null, create],
    ['put', plansPath, null, update],
  ]);
});

test('the File catalog keeps fine and aggregate action variants independent', async (t) => {
  const hostApis = nativePlatformApis();
  const fileApis = fileCatalogApis();
  const fineApis = storageReads.map(([, section]) => {
    const matches = fileApis.filter((row) => row.method === 'GET' && row.path === storagePath + '/' + section);
    assert.equal(matches.length, 1, section);
    return matches[0];
  });
  assert.ok(fileApis.some((row) => row.method === 'GET' && row.path === storagePath));
  assert.ok(fileApis.some((row) => row.method === 'POST'));
  const storageCalls = [];
  const previewCalls = [];
  let fileRows = fileApis;
  const { api, contract, mount } = fixture(t, async (config) => {
    if (config.url === operatorsPath)
      return reply(config, {
        count: 1,
        lists: [{ id: '1', display_name: 'Operator', role_keys: ['editor'] }],
      });
    if (config.url === rolesPath)
      return reply(config, { count: 1, lists: [{ id: '2', key: 'editor', name: 'Editor', revision: 1, status: 'active' }] });
    if (config.url === permissionApisPath) {
      const items = [...hostApis, ...fileRows];
      return reply(config, { items, total: items.length });
    }
    if (config.url === previewPath) {
      assert.equal(config.method, 'post');
      previewCalls.push(JSON.parse(config.data));
      return reply(config, result);
    }
    assert.ok(config.url.startsWith(storagePath));
    storageCalls.push([
      config.method, config.url, config.data ? JSON.parse(config.data) : null,
    ]);
    return reply(config, {});
  });
  const panel = await mount(true, 'shell');
  assert.equal(panel.candidate.value.notice, '');
  const storage = panel.candidate.value.data.tree.find((node) => node.key === 'platform.storage');
  assert.equal(storage.label, '存储管理');
  assert.equal(storage.disabled, true);
  assert.deepEqual(plain(storage.api_ids), []);
  for (const [index, [key, section]] of storageReads.entries()) {
    const child = storage.children[index];
    assert.equal(child.key, key);
    assert.deepEqual(plain(child.api_ids), [fineApis[index].id]);
    assert.match(child.source, new RegExp('#/storage-' + section + '.*peanut\\.file'));
  }
  panel.operatorId.value = '1';
  panel.roleId.value = '2';
  panel.changeCheckedNodes({}, { checkedKeys: [storageReads[0][0]] });
  await panel.runPreview();
  assert.deepEqual(previewCalls[0].role_assignments, [{ role_id: '2', node_keys: [storageReads[0][0]] }]);
  assert.deepEqual(previewCalls[0].nodes.filter((node) => node.parent_key === 'platform.storage' && node.type === 'page'),
    storageReads.map(([key], index) => ({
      key, parent_key: 'platform.storage', type: 'page', api_ids: [fineApis[index].id],
    })));
  for (const [key, parent, type, endpoints] of storageActionNodes) {
    const expected = endpoints.map(([method, path]) => {
      const matching = fileApis.filter((row) => row.method === method && row.path === path);
      assert.equal(matching.length, 1, method + ' ' + path);
      return matching[0].id;
    }).sort();
    assert.deepEqual(previewCalls[0].nodes.find((item) => item.key === key), {
      key, parent_key: parent, type, api_ids: expected,
    });
    if (key.endsWith('.fine') || key.endsWith('.legacy')) {
      panel.changeCheckedNodes({}, { checkedKeys: [key] });
      await panel.runPreview();
      const payload = previewCalls.at(-1);
      assert.deepEqual(payload.role_assignments, [{ role_id: '2', node_keys: [key] }]);
      assert.deepEqual(payload.nodes.find((item) => item.key === key).api_ids, expected);
    }
  }
  const selectedIds = new Set(previewCalls[0].nodes.flatMap((node) => node.api_ids));
  for (const row of fileApis)
    assert.equal(selectedIds.has(row.id), fineApis.includes(row) ||
      storageActionNodes.some(([, , , endpoints]) =>
        endpoints.some(([method, path]) => row.method === method && row.path === path)
      ), row.method + ' ' + row.path);

  const [account, space, route] = fineApis;
  const invalidSources = [
    [[account, space], /\/storage\/routes：API 未登记/],
    [[{ ...account, module_key: 'platform' }], /目录来源为 platform，需要 peanut.file/],
    [[account, { ...space, status: 'retired' }, route], /目录状态为 retired/],
    [[account, { ...space, audience: 'tenant' }, route], /受众不是 Platform/],
    [[account, { ...space, access_mode: 'platform_bearer' }, route], /授权模式为 platform_bearer/],
    [[account, space, route, { ...space, id: apiId }], /存在多个目录条目/],
  ];
  for (const [rows, expected] of invalidSources)
    assert.throws(() => contract.buildPlatformShellCandidate([...hostApis, ...rows]), expected);
  fileRows = [account, space];
  await panel.loadDirectory();
  assert.equal(panel.candidate.value.data, null);
  assert.match(panel.candidate.value.error, /\/storage\/routes：API 未登记/);
  await panel.runPreview();
  assert.equal(previewCalls.length, 5);

  await api.storageAccounts();
  await api.storageSpaces();
  await api.storageRoutes();
  await api.storageSnapshot();
  const accountInput = { account_key: 'synthetic', driver: 'local', name: 'Synthetic', credentials: {} };
  const spaceInput = { account_id: 17, space_key: 'synthetic', access_type: 'private' };
  const routeInput = { route_key: 'default', access_type: 'private', space_id: 21 };
  await api.createStorageAccount(accountInput);
  await api.createStorageSpace(spaceInput);
  await api.setStorageRoute(routeInput);
  assert.deepEqual(storageCalls, [
    ['get', storagePath + '/accounts', null],
    ['get', storagePath + '/spaces', null],
    ['get', storagePath + '/routes', null],
    ['get', storagePath, null],
    ['post', storagePath + '/account', accountInput],
    ['post', storagePath + '/space', spaceInput],
    ['post', storagePath + '/route', routeInput],
  ]);
});

test('governance binding consumers preserve tenant filter, revisions and explicit empty API links', async (t) => {
  const calls = [];
  const bundle = {
    key: 'tenant.synthetic', name: 'Synthetic', module_key: 'synthetic',
    audience: 'tenant', status: 'active', revision: 'bundle-rev', api_ids: [],
    configured: true, source: 'custom',
  };
  const menu = {
    menu_key: 'tenant.synthetic', parent_key: null, type: 'A', name: 'Synthetic',
    page_path: '/synthetic', action_key: 'synthetic.run', permission_key: bundle.key,
    default_permission_key: bundle.key, revision: 'menu-rev',
  };
  const menuChange = {
    menu_key: menu.menu_key, permission_key: null,
    action_key: 'synthetic.run', expected_revision: menu.revision,
  };
  const { api } = fixture(t, async (config) => {
    calls.push([
      config.method, config.url, config.params ? plain(config.params) : null,
      config.data ? JSON.parse(config.data) : null,
    ]);
    if (config.url === permissionBundlesPath)
      return reply(config, { items: [bundle], total: 1 });
    return reply(config, config.url === permissionMenuBindingPath ? menu : bundle);
  });
  await api.permissionBundles('tenant', 2);
  await api.permissionBindings(bundle.key);
  await api.replacePermissionBindings(bundle.key, bundle.revision, []);
  await api.replacePermissionMenuBinding(menuChange);
  assert.deepEqual(calls, [
    ['get', permissionBundlesPath, { audience: 'tenant', page_no: 2, page_size: 100 }, null],
    ['get', permissionApiBindingsPath, { permission_key: bundle.key }, null],
    ['put', permissionApiBindingsPath, null, {
      permission_key: bundle.key, expected_revision: bundle.revision, api_ids: [],
    }],
    ['put', permissionMenuBindingPath, null, menuChange],
  ]);
});

test('Ops control requests preserve their exact methods, state headers, task identifier and signals', async (t) => {
  const calls = [];
  const { api, createPlatformOpsTransport } = fixture(t, async (config) => {
    calls.push([
      config.method, config.url, config.data ? JSON.parse(config.data) : null,
      config.headers?.['If-Match'] ?? null, config.headers?.['Idempotency-Key'] ?? null,
      config.signal ?? null,
    ]);
    return {
      ...reply(config, { source: config.url }),
      headers: { 'x-request-id': 'synthetic-ops-control' },
    };
  });
  const transport = createPlatformOpsTransport();
  const { signal } = new AbortController();
  await transport.submitBackup('synthetic', 'backup-intent', signal);
  await transport.submitRestore('synthetic', 'backup-synthetic', 'isolated', 'restore-intent', signal);
  await transport.task('synthetic/task', signal);
  await transport.scheduleMaintenance(
    { reasonKey: 'planned-upgrade', startsAt: '2026-11-01T00:00:00Z', endsAt: '2026-11-01T01:00:00Z' },
    17, 'schedule-intent', signal
  );
  await transport.closeMaintenance('synthetic/window', 18, 'close-intent', signal);
  await api.submitUpgrade();
  assert.deepEqual(calls.slice(0, 5), [
    ['post', opsPath + '/tasks/backup', { provider_key: 'synthetic' }, null, 'backup-intent', signal],
    ['post', opsPath + '/tasks/restore', {
      provider_key: 'synthetic', backup_reference_key: 'backup-synthetic', target_key: 'isolated',
    }, null, 'restore-intent', signal],
    ['get', opsPath + '/tasks/synthetic%2Ftask', null, null, null, signal],
    ['put', opsPath + '/maintenance', {
      reason_key: 'planned-upgrade', starts_at: '2026-11-01T00:00:00Z', ends_at: '2026-11-01T01:00:00Z',
    }, '"rev-17"', 'schedule-intent', signal],
    ['post', opsPath + '/maintenance/synthetic%2Fwindow/close', {}, '"rev-18"', 'close-intent', signal],
  ]);
  assert.deepEqual(calls[5].slice(0, 4), ['post', opsPath + '/tasks/upgrade', {}, null]);
  assert.match(calls[5][4], /^platform-upgrade-/);
  assert.equal(calls[5][5], null);
});

test('formal candidates stop on missing, inactive, incompatible or ambiguous API rows', async (t) => {
  const apis = nativePlatformApis();
  const [roles, ...otherApis] = apis;
  const { contract, mount } = fixture(t, () => assert.fail('unexpected request'));
  const diagnostics = [
    [otherApis, /GET \/platformapi\/roles：API 未登记/],
    [[{ ...roles, status: 'retired' }, ...otherApis], /目录状态为 retired/],
    [[{ ...roles, audience: 'tenant' }, ...otherApis], /受众不是 Platform/],
    [[{ ...roles, access_mode: 'platform_bearer' }, ...otherApis], /授权模式为 platform_bearer/],
    [[...apis, { ...roles, id: apiId }], /存在多个目录条目/],
  ];
  for (const [rows, error] of diagnostics)
    assert.throws(() => contract.buildPlatformShellCandidate(rows), error);

  const panel = await mount(false, 'shell');
  panel.operatorId.value = '1';
  assert.match(panel.candidate.value.error, /API 未登记/);
  assert.equal(panel.candidate.value.data, null);
  await panel.runPreview();
});

test('preview access requires the exact POST and all three read endpoints', (t) => {
  const { contract } = fixture(t, () => assert.fail('unexpected request'));
  const grants = new Set([
    'POST ' + previewPath,
    'GET /platformapi/operators',
    'GET /platformapi/roles',
    'GET /platformapi/permission/apis',
  ]);
  const canApi = (method, path) => grants.has(method + ' ' + path);
  assert.equal(contract.allowsRoleAuthorizationPreview(canApi), true);
  for (const grant of grants) {
    const allowed = (method, path) =>
      canApi(method, path) && method + ' ' + path !== grant;
    assert.equal(contract.allowsRoleAuthorizationPreview(allowed), false, grant);
  }
});

test('preview sends explicit empty but omits untouched roles and preserves both outcomes', async (t) => {
  const calls = [];
  const { mount, contract } = fixture(t, async (config) => {
    calls.push([config.method, config.url, JSON.parse(config.data)]);
    return reply(config, result);
  });
  const panel = await mount();
  assert.equal(calls.length, 0);
  assert.equal(panel.candidate.value.data.tree[0].disabled, true);

  panel.setRoleSelection([]);
  assert.equal(panel.selectionLabel('2'), '明确空选择');
  assert.equal(panel.selectionLabel('3'), '未提供选择');
  await panel.runPreview();
  assert.deepEqual(calls, [['post', previewPath, {
    operator_id: '1',
    nodes: [group, page],
    role_assignments: [{ role_id: '2', node_keys: [] }],
  }]]);
  assert.deepEqual(plain(panel.result.value), result);

  panel.roleId.value = '3';
  assert.equal(panel.result.value, undefined);
  panel.changeCheckedNodes(page, { checkedKeys: [page.key] });
  await panel.runPreview();
  assert.deepEqual(calls[1][2].role_assignments, [
    { role_id: '2', node_keys: [] },
    { role_id: '3', node_keys: [page.key] },
  ]);

  panel.candidateText.value = JSON.stringify([{
    key: page.key, parent_key: null, type: 'page',
  }]);
  assert.match(panel.candidate.value.error, /缺少 api_ids/);
  await panel.runPreview();
  assert.equal(calls.length, 2);
  assert.equal(panel.result.value, undefined);
  assert.equal(panel.selectionLabel('2'), '未提供选择');
  assert.throws(
    () => contract.readCandidateNodes(JSON.stringify([group, page, { ...page, api_ids: [] }])),
    /定义冲突/
  );
});

test('reopening reloads all paginated directories and preserves large textual IDs and diagnostics', async (t) => {
  const operatorId = '9007199254740993';
  const editorId = '9007199254740995';
  const reviewerId = '9007199254740997';
  const diagnosticId = 'legacy/api-id';
  let roleKey = 'editor';
  const directoryReads = [];
  const previews = [];
  const { mount } = fixture(t, async (config) => {
    const { url, params } = config;
    if (config.method === 'get') directoryReads.push([url, params]);
    if (url === '/platformapi/operators') {
      return reply(config, {
        count: 1,
        lists: [{ id: operatorId, display_name: 'Operator', role_keys: [roleKey] }],
      });
    }
    if (url === '/platformapi/roles') {
      const filler = Array.from({ length: 99 }, (_, index) => ({
        id: index + 10, key: 'other-' + index, name: 'Other ' + index, revision: 1, status: 'active',
      }));
      return reply(config, {
        count: 101,
        lists: params.page === 1
          ? [{ id: editorId, key: 'editor', name: 'Editor', revision: 1, status: 'active' }, ...filler]
          : [{ id: reviewerId, key: 'reviewer', name: 'Reviewer', revision: 3, status: 'active' }],
      });
    }
    if (url === '/platformapi/permission/apis')
      return reply(config, { items: [], total: 0 });
    assert.equal(url, previewPath);
    const candidate = JSON.parse(config.data);
    previews.push(candidate);
    return reply(config, {
      ...result,
      operator_id: operatorId,
      unresolved: [{ code: 'legacy_api_not_active_platform', api_id: diagnosticId }],
    });
  });

  const first = await mount(true);
  assert.equal(first.directoryReady.value, true);
  first.operatorId.value = operatorId;
  assert.deepEqual(plain(first.targetRoles.value.map((role) => role.id)), [editorId]);
  first.close();

  roleKey = 'reviewer';
  const second = await mount(true);
  assert.equal(second.directoryReady.value, true);
  second.operatorId.value = operatorId;
  assert.deepEqual(plain(second.targetRoles.value.map((role) => role.id)), [reviewerId]);
  second.roleId.value = reviewerId;
  second.setRoleSelection([]);
  await second.runPreview();
  assert.deepEqual(previews, [{
    operator_id: operatorId,
    nodes: [group, page],
    role_assignments: [{ role_id: reviewerId, node_keys: [] }],
  }]);
  assert.equal(second.previewError.value, null);
  assert.equal(second.result.value.unresolved[0].api_id, diagnosticId);
  assert.equal(second.result.value.capability_equal, true);

  const reads = [
    ['/platformapi/operators', { page: 1, page_size: 100 }],
    ['/platformapi/roles', { page: 1, page_size: 100 }],
    ['/platformapi/permission/apis', { audience: 'platform', page_no: 1, page_size: 100 }],
    ['/platformapi/roles', { page: 2, page_size: 100 }],
  ];
  assert.deepEqual(plain(directoryReads), [...reads, ...reads]);
});

test('unsafe numeric directory IDs fail before a preview can be sent', async (t) => {
  const { mount } = fixture(t, async (config) => {
    assert.equal(config.method, 'get');
    if (config.url === '/platformapi/operators')
      return reply(config, {
        count: 1,
        lists: [{ id: Number.MAX_SAFE_INTEGER + 1, display_name: 'Operator', role_keys: [] }],
      });
    if (config.url === '/platformapi/roles')
      return reply(config, { count: 0, lists: [] });
    assert.equal(config.url, '/platformapi/permission/apis');
    return reply(config, { total: 0, items: [] });
  });
  const panel = await mount(true);
  assert.equal(panel.directoryReady.value, false);
  assert.match(panel.directoryError.value, /无效的操作员或角色标识/);
  await panel.runPreview();
});

test('operator, candidate, source, role selection, and closing cancel or ignore stale responses', async (t) => {
  let pending;
  const { mount } = fixture(t, (config) => new Promise((resolve) => {
    pending = {
      signal: config.signal,
      finish: () => resolve(reply(config, result)),
    };
  }));
  const panel = await mount();
  const changes = [
    () => { panel.operatorId.value = '4'; },
    () => { panel.candidateText.value = '[]'; },
    () => { panel.candidateMode.value = 'shell'; },
    () => { panel.setRoleSelection([]); },
    () => { panel.close(); },
  ];
  for (const change of changes) {
    panel.candidateMode.value = 'manual';
    panel.operatorId.value = '1';
    panel.roleId.value = '2';
    panel.candidateText.value = JSON.stringify([group, page]);
    pending = undefined;
    const action = panel.runPreview();
    await new Promise((resolve) => setImmediate(resolve));
    assert.ok(pending);
    change();
    assert.equal(pending.signal.aborted, true);
    pending.finish();
    await action;
    assert.equal(panel.result.value, undefined);
    assert.equal(panel.previewError.value, null);
  }
});

test('malformed responses and refusal do not show success or retry', async (t) => {
  const calls = [];
  let errorCode;
  const { api, mount, revoke } = fixture(t, async (config) => {
    calls.push(config.url);
    if (config.url === '/platformapi/session/login')
      return reply(config, { access_token: 'synthetic' });
    if (!errorCode)
      return reply(config, { ...result, relation_coverage_complete: 'false' });
    const status = errorCode === 40300 ? 403 : 401;
    throw new axios.AxiosError('denied', 'ERR_BAD_REQUEST', config, undefined,
      reply(config, null, errorCode, status, '权限不足'));
  });
  const panel = await mount();
  await api.login('operator@example.test', 'synthetic');
  await panel.runPreview();
  assert.match(panel.previewError.value, /响应格式异常/);
  assert.equal(panel.result.value, undefined);
  for (const code of [40300, 40100]) {
    errorCode = code;
    await panel.runPreview();
    assert.match(panel.previewError.value, /权限不足/);
    assert.equal(panel.result.value, undefined);
  }
  revoke();
  await panel.runPreview();
  assert.deepEqual(calls, ['/platformapi/session/login', previewPath, previewPath, previewPath]);
});

for (const status of [200, 401]) {
  test('initial preview 40100 via HTTP ' + status + ' preserves session and draft', async (t) => {
    const calls = [];
    const { api, mount, onSessionChange, session } = fixture(t, async (config) => {
      if (config.url === '/platformapi/session/login')
        return reply(config, { access_token: 'synthetic' });
      calls.push([config.method, config.url, config.headers.Authorization]);
      assert.equal(config.url, previewPath);
      const response = reply(config, null, 40100, status, '本次预览的访问凭据已过期');
      if (status === 401)
        throw new axios.AxiosError('unauthorized', 'ERR_BAD_REQUEST', config, undefined, response);
      return response;
    });
    await api.login('operator@example.test', 'synthetic');
    const authenticated = plain(session());
    assert.equal(authenticated.token, 'synthetic');
    let sessionChanges = 0;
    t.after(onSessionChange(() => { sessionChanges += 1; }));

    const panel = await mount();
    panel.setRoleSelection([]);
    const candidate = panel.candidateText.value;
    await panel.runPreview();

    assert.deepEqual(calls, [['post', previewPath, 'Bearer synthetic']]);
    assert.deepEqual(plain(session()), authenticated);
    assert.equal(sessionChanges, 0);
    assert.equal(panel.operatorId.value, '1');
    assert.equal(panel.candidateText.value, candidate);
    assert.equal(panel.selectionLabel('2'), '明确空选择');
    assert.equal(panel.result.value, undefined);
    assert.match(panel.previewError.value, /本次预览的访问凭据已过期/);
  });
}

test('ordinary Platform requests still refresh once and clear a rejected replay', async (t) => {
  const attempts = [];
  const catalog = '/platformapi/developer-center/catalog';
  const refresh = '/platformapi/session/refresh';
  const { api, onSessionChange, session } = fixture(t, async (config) => {
    if (config.url === '/platformapi/session/login')
      return reply(config, { access_token: 'expired' });
    attempts.push([config.url, config.headers.Authorization]);
    if (config.url === refresh)
      return reply(config, { access_token: 'replacement' });
    assert.equal(config.url, catalog);
    return reply(config, null, 40100, 200, '会话已过期');
  });
  await api.login('operator@example.test', 'synthetic');
  const changes = [];
  t.after(onSessionChange((present) => changes.push(present)));
  await assert.rejects(api.developerCatalog(), /会话已过期/);
  assert.deepEqual(attempts, [
    [catalog, 'Bearer expired'],
    [refresh, 'Bearer expired'],
    [catalog, 'Bearer replacement'],
  ]);
  assert.equal(session().token, null);
  assert.deepEqual(changes, [false]);
});

test('existing role save uses its original independent contract', async (t) => {
  let request;
  const { api } = fixture(t, async (config) => {
    request = [config.method, config.url, JSON.parse(config.data)];
    return reply(config, null);
  });
  await api.replaceRolePermissions({ id: 2, revision: 7 }, ['platform.role.read'], 'synthetic');
  assert.deepEqual(request, ['post', legacyPath, {
    role_id: 2,
    permission_keys: ['platform.role.read'],
    expected_revision: 7,
    change_reason: 'synthetic',
  }]);
});

test('catalog audit uses the formal graph, includes a memberless role, and distinguishes empty from missing', async (t) => {
  const apis = nativePlatformApis();
  const roles = [
    { id: '2', key: 'platform.fixture.reader', name: 'Reader', revision: 7, status: 'active' },
    { id: '6', key: 'platform.fixture.future', name: 'Future without members', revision: 2, status: 'active' },
  ];
  const requests = [];
  const { mount, contract } = fixture(t, async (config) => {
    if (config.method === 'get') {
      if (config.url === operatorsPath)
        return reply(config, { count: 1, lists: [{ id: '3', display_name: 'Reader', role_keys: [roles[0].key] }] });
      if (config.url === rolesPath) return reply(config, { count: roles.length, lists: roles });
      assert.equal(config.url, permissionApisPath);
      return reply(config, { total: apis.length, items: apis });
    }
    assert.equal(config.method, 'post');
    assert.equal(config.url, catalogAuditPath);
    const candidate = JSON.parse(config.data);
    requests.push(candidate);
    assert.deepEqual(candidate.role_ids, ['2', '6']);
    assert.deepEqual(candidate.role_assignments, [
      { role_id: '2', expected_revision: '7', node_keys: [] },
    ]);
    assert.equal(Object.hasOwn(candidate, 'nodes'), false, 'The audit never accepts a client-owned API graph');
    const removed = [apis.find((row) => row.method === 'POST' && row.path === catalogAuditPath).id];
    return reply(config, {
      catalog_total: 2, scope_covers_catalog: true, equivalent: false,
      roles: [
        {
          role_id: '2', role_key: roles[0].key, status: 'active', member_count: 1,
          expected_revision: 7, observed_revision: 7, relation_equal: false,
          capability_equal: false, relation: { added: [], removed },
          capability: { added: [], removed }, owner_dynamic: false,
          non_http_permission_keys: [], prerequisite_permission_keys: ['platform.role.read'],
        },
        {
          role_id: '6', role_key: roles[1].key, status: 'active', member_count: 0,
          expected_revision: null, observed_revision: 2, relation_equal: false,
          capability_equal: false, relation: { added: [], removed: [] },
          capability: { added: [], removed: [] }, owner_dynamic: false,
          non_http_permission_keys: [], prerequisite_permission_keys: [],
        },
      ],
      unresolved: [{ code: 'role_assignment_missing', role_id: '6' }],
    });
  });
  assert.equal(contract.allowsRoleAuthorizationCatalogAudit(
    (method, path) => !(method === 'POST' && path === catalogAuditPath)
  ), false);
  assert.equal(contract.allowsRoleAuthorizationCatalogAudit(() => true), true);
  const panel = await mount(true, 'shell');
  panel.targetMode.value = 'catalog';
  assert.deepEqual(plain(panel.targetRoles.value.map((role) => role.id)), ['2', '6']);
  assert.equal(panel.selectionLabel('6'), '未提供选择');
  panel.roleId.value = '2';
  panel.setRoleSelection([]);
  assert.equal(panel.selectionLabel('2'), '明确空选择');
  await panel.runCatalogAudit();
  assert.equal(requests.length, 1);
  assert.equal(panel.catalogResult.value.scope_covers_catalog, true);
  assert.equal(panel.catalogResult.value.equivalent, false);
  assert.equal(panel.catalogResult.value.roles[1].member_count, 0);
  assert.deepEqual(plain(panel.catalogResult.value.unresolved),
    [{ code: 'role_assignment_missing', role_id: '6' }]);
  assert.equal(panel.result.value, undefined);
});

test('catalog audit covers more than 200 roles by bounded native batches and rejects stale directory facts', async (t) => {
  const { contract } = fixture(t, async () => { throw new Error('No HTTP needed for the pure batch contract.'); });
  const roles = Array.from({ length: 201 }, (_, index) => ({
    id: String(index + 1), key: 'platform.fixture.' + (index + 1),
    name: 'Role ' + (index + 1), revision: '1', status: 'active',
  }));
  const directory = { roles, operators: [], apis: [] };
  const selections = new Map([['1', []], ['201', ['platform.candidate.page']]]);
  const batches = contract.buildRoleCatalogAuditBatches(roles, selections);
  assert.deepEqual(plain(batches.map((row) => row.role_ids.length)), [200, 1]);
  assert.deepEqual(plain(batches[0].role_assignments), [
    { role_id: '1', expected_revision: '1', node_keys: [] },
  ]);
  assert.deepEqual(plain(batches[1].role_assignments), [
    { role_id: '201', expected_revision: '1', node_keys: ['platform.candidate.page'] },
  ]);
  let calls = 0;
  const responder = async (batch) => {
    calls += 1;
    return {
      catalog_total: 201, scope_covers_catalog: false, equivalent: false,
      roles: batch.role_ids.map((role_id) => ({
        role_id, role_key: 'platform.fixture.' + role_id, status: 'active',
        member_count: role_id === '201' ? 0 : 1,
        observed_revision: 1,
        expected_revision: selections.has(role_id) ? 1 : null,
        relation_equal: selections.has(role_id),
        capability_equal: selections.has(role_id),
        relation: { added: [], removed: [] }, capability: { added: [], removed: [] },
        owner_dynamic: false, non_http_permission_keys: [], prerequisite_permission_keys: [],
      })),
      unresolved: batch.role_ids.filter((id) => !selections.has(id))
        .map((role_id) => ({ code: 'role_assignment_missing', role_id })),
    };
  };
  const result = await contract.auditCompleteRoleCatalog(
    directory, selections, responder, new AbortController().signal
  );
  assert.equal(calls, 2);
  assert.equal(result.scope_covers_catalog, true);
  assert.equal(result.atomic_snapshot, false);
  assert.equal(result.roles.length, 201);
  assert.equal(result.unresolved.length, 199);
  assert.equal(result.equivalent, false);
  const stale = async (batch) => {
    const response = await responder(batch);
    response.roles[0].observed_revision = 2;
    return response;
  };
  await assert.rejects(
    contract.auditCompleteRoleCatalog(directory, selections, stale, new AbortController().signal),
    /角色内容或修订发生变化/
  );
});

test('native Platform role-node source is bound to the Shell definition and current generated API catalog', () => {
  const manifest = JSON.parse(readFileSync(new URL('../../server/generated/platform-role-source.json', import.meta.url), 'utf8'));
  const source = readFileSync(new URL('../src/core/platform-shell-definitions.ts', import.meta.url), 'utf8');
  const catalog = JSON.parse(readFileSync(new URL('../../server/generated/api-catalog.json', import.meta.url), 'utf8'));
  const manifestHash = createHash('sha256').update(
    readFileSync(new URL('../../server/generated/platform-role-source.json', import.meta.url))
  ).digest('hex');
  assert.equal(catalog.inputs.find((entry) =>
    entry.path === 'server/generated/platform-role-source.json')?.sha256, manifestHash);
  assert.equal(manifest.schema_version, 1);
  assert.equal(manifest.source_path, 'platform/src/core/platform-shell-definitions.ts');
  assert.equal(manifest.source_sha256, createHash('sha256').update(source).digest('hex'));
  assert.equal(manifest.nodes.length, 81);
  const developerGroup = manifest.nodes.find((node) => node.key === 'platform.developer.tools');
  assert.equal(developerGroup?.type, 'group');
  assert.equal(developerGroup?.parent_key, null);
  assert.equal(manifest.nodes.find((node) => node.key === developerPage)?.parent_key, developerGroup.key);
  assert.equal(new Set(manifest.nodes.map((node) => node.key)).size, manifest.nodes.length);
  const byKey = Object.fromEntries(manifest.nodes.map((node) => [node.key, node]));
  for (const [key, type, path, parent] of [
    ['platform.role.list', 'page', '/platformapi/roles', 'platform.governance'],
    ['platform.role.create', 'action', '/platformapi/roles/create', 'platform.role.list'],
    ['platform.role.authorization.catalog-audit', 'operation', '/platformapi/roles/permissions/catalog-audit', null],
  ]) {
    const node = byKey[key];
    assert.ok(node, key);
    assert.equal(node.type, type);
    assert.equal(node.parent_key, parent);
    const links = node.api_endpoints.filter(([method, endpoint]) => endpoint === path);
    assert.equal(links.length, 1, key);
    const matches = catalog.endpoints.filter((api) => api.method === links[0][0] && api.path === path);
    assert.equal(matches.length, 1, key + ' must have one current generated route');
    assert.equal(matches[0].audience, 'platform');
    assert.equal(matches[0].auth.mode, 'platform_permission');
    assert.equal(matches[0].contract_quality, 'complete');
    assert.deepEqual(matches[0].owner, { type: 'application', key: 'platform' });
  }
});
