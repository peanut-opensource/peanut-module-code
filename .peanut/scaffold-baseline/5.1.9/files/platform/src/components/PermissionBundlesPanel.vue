<template>
  <el-card>
    <template #header>{{ panelTitles[section] }}</template>
    <p>{{ panelDescriptions[section] }}</p>
    <el-space wrap>
      <el-select
        v-if="section !== 'menus'"
        v-model="audience"
        aria-label="权限安全域"
        style="width: 160px"
        :disabled="saving"
      >
        <el-option label="租户业务权限" value="tenant" />
        <el-option label="平台权限" value="platform" />
      </el-select>
      <el-input
        v-if="section === 'bundles'"
        v-model="search"
        placeholder="搜索权限包"
        clearable
      />
      <el-button :loading="loading" :disabled="saving" @click="load"
        >刷新</el-button
      >
      <el-button
        v-if="section === 'bundles' && canCreate"
        type="primary"
        :disabled="saving || loading || !!error"
        @click="create"
        >创建权限包</el-button
      >
    </el-space>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table
      v-if="section === 'bundles'"
      v-loading="loading"
      :data="visibleBundles"
      max-height="440"
      row-key="key"
      empty-text="暂无权限包"
    >
      <el-table-column prop="name" label="权限包" />
      <el-table-column prop="key" label="标识" />
      <el-table-column prop="module_key" label="模块" />
      <el-table-column prop="status" label="状态" width="90" />
      <el-table-column label="关联 API" width="100"
        ><template #default="{ row }">{{
          row.api_ids.length
        }}</template></el-table-column
      >
      <el-table-column label="来源" width="130"
        ><template #default="{ row }">{{
          sourceLabel(row)
        }}</template></el-table-column
      >
      <el-table-column label="操作" width="180"
        ><template #default="{ row }"
          ><el-button
            v-if="canUpdate && row.source === 'custom'"
            link
            :disabled="saving"
            @click="edit(row)"
            >编辑</el-button
          ></template
        ></el-table-column
      >
    </el-table>
    <section v-if="section === 'apis'">
      <el-input
        v-model="apiSearch"
        placeholder="搜索 API 方法、路径或模块"
        clearable
      />
      <el-table
        v-loading="loading"
        :data="visibleApis"
        row-key="id"
        empty-text="暂无 API"
      >
        <el-table-column prop="method" label="方法" width="90" />
        <el-table-column prop="path" label="接口路径" min-width="260" />
        <el-table-column prop="module_key" label="所属模块" min-width="150" />
        <el-table-column label="访问类型"
          ><template #default="{ row }">{{
            accessModeLabel(row.access_mode)
          }}</template></el-table-column
        >
        <el-table-column label="源默认权限"
          ><template #default="{ row }">{{
            row.default_permission_keys.join('、') || '无'
          }}</template></el-table-column
        >
        <el-table-column label="同时需要"
          ><template #default="{ row }">{{
            row.prerequisite_permission_keys.join('、') || '无'
          }}</template></el-table-column
        >
      </el-table>
      <el-card v-if="canReadBundles && canReadBindings" class="binding-card">
        <template #header>按权限包查看 API 关联</template>
        <p
          >选择权限包，查看或调整它允许访问的
          API；源默认权限与本实例保存的关联分别展示。</p
        >
        <el-select
          :model-value="selected?.key"
          filterable
          placeholder="选择权限包"
          :disabled="loading || saving"
          @change="chooseBundle"
        >
          <el-option
            v-for="bundle in visibleBundles"
            :key="bundle.key"
            :value="bundle.key"
            :label="bundle.name + '（' + bundle.key + '）'"
          />
        </el-select>
      </el-card>
    </section>
    <el-dialog
      v-model="editing"
      :title="editingBundle ? '编辑自建权限包' : '创建权限包'"
      width="min(520px, 94vw)"
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
    >
      <el-form label-position="top">
        <el-form-item label="标识"
          ><el-input
            v-model="definition.key"
            :disabled="!!editingBundle || saving"
            :placeholder="
              audience === 'platform'
                ? '如 platform.custom.orders，创建后不可修改'
                : '如 custom.orders，创建后不可修改'
            "
            maxlength="100"
        /></el-form-item>
        <el-form-item label="名称"
          ><el-input
            v-model="definition.name"
            :disabled="saving"
            maxlength="120"
        /></el-form-item>
        <el-form-item label="安全域"
          ><el-input
            :model-value="audience === 'tenant' ? '租户业务权限' : '平台权限'"
            disabled
        /></el-form-item>
        <el-form-item v-if="editingBundle" label="状态"
          ><el-select v-model="definition.status" :disabled="saving"
            ><el-option label="启用" value="active" /><el-option
              label="停用"
              value="retired" /></el-select
        ></el-form-item>
      </el-form>
      <el-alert
        v-if="definitionError"
        :title="definitionError"
        type="error"
        :closable="false"
      />
      <template #footer
        ><el-button :disabled="saving" @click="editing = false">取消</el-button
        ><el-button
          type="primary"
          :loading="saving"
          :disabled="!definition.key.trim() || !definition.name.trim()"
          @click="saveDefinition"
          >保存</el-button
        ></template
      >
    </el-dialog>
    <el-dialog
      v-model="visible"
      title="权限包关联 API"
      width="min(850px, 94vw)"
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
    >
      <div v-loading="detailLoading">
        <p>{{ selected?.name }} · {{ selected?.key }}</p>
        <p>同一 API 可被多个权限包引用。公共与上下文接口仅供查阅，不能绑定。</p>
        <el-input
          v-model="apiSearch"
          placeholder="搜索 API 方法、路径或模块"
          clearable
        />
        <el-checkbox-group
          v-model="selection"
          :disabled="
            !canManage ||
            saving ||
            detailLoading ||
            selected?.status !== 'active'
          "
        >
          <div
            v-for="item in visibleApis"
            :key="item.id"
            class="permission-api-row"
          >
            <el-checkbox :value="item.id" :disabled="!bindable(item)">
              {{ item.method }} {{ item.path }}
              <small
                >{{ item.module_key }} · {{ item.access_mode }} ·
                {{ item.status }}</small
              >
            </el-checkbox>
            <p v-if="item.prerequisite_permission_keys.length">
              前置权限：{{
                item.prerequisite_permission_keys.join('、')
              }}（仍须同时满足）
            </p>
          </div>
        </el-checkbox-group>
        <el-alert
          v-if="detailError"
          :title="detailError"
          type="error"
          :closable="false"
        />
      </div>
      <template #footer>
        <el-button :disabled="saving" @click="visible = false">关闭</el-button>
        <el-button
          v-if="canManage"
          type="primary"
          :disabled="
            detailLoading || !!detailError || selected?.status !== 'active'
          "
          :loading="saving"
          @click="save"
          >保存关联</el-button
        >
      </template>
    </el-dialog>
    <section v-if="section === 'menus'">
      <el-divider />
      <h3>租户菜单与按钮引用</h3>
      <p
        >选择已有权限包作为菜单或按钮的访问条件；角色授权和 API
        关联分别保存。页面及已有动作标识保持原身份。</p
      >
      <el-space wrap>
        <el-input
          v-model="menuSearch"
          placeholder="搜索名称、页面或动作"
          clearable
        />
        <el-button
          :loading="menuLoading"
          :disabled="saving || loading"
          @click="loadMenuBindings(generation)"
          >刷新引用</el-button
        >
      </el-space>
      <el-alert
        v-if="menuError"
        :title="menuError"
        type="error"
        :closable="false"
      />
      <el-table
        v-loading="menuLoading"
        :data="visibleMenuBindings"
        max-height="440"
        row-key="menu_key"
        empty-text="暂无菜单或按钮"
      >
        <el-table-column prop="name" label="名称" />
        <el-table-column label="类型" width="80"
          ><template #default="{ row }">{{
            row.type === 'A' ? '按钮' : '菜单'
          }}</template></el-table-column
        >
        <el-table-column prop="page_path" label="所属页面" />
        <el-table-column prop="action_key" label="动作标识" />
        <el-table-column prop="permission_key" label="当前权限包" />
        <el-table-column
          v-if="canManage && canReadBundles"
          label="操作"
          width="100"
          ><template #default="{ row }"
            ><el-button
              link
              :disabled="
                saving || loading || menuLoading || !!error || !!menuError
              "
              @click="openMenuBinding(row)"
              >编辑引用</el-button
            ></template
          ></el-table-column
        >
      </el-table>
    </section>
    <el-dialog
      v-model="menuVisible"
      title="菜单与按钮权限包引用"
      width="min(620px, 94vw)"
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
    >
      <el-form v-if="selectedMenu" label-position="top">
        <el-form-item label="菜单 / 按钮"
          ><el-input
            :model-value="`${selectedMenu.name}（${selectedMenu.menu_key}）`"
            disabled
        /></el-form-item>
        <el-form-item label="所属页面"
          ><el-input :model-value="selectedMenu.page_path" disabled
        /></el-form-item>
        <el-form-item v-if="selectedMenu.type === 'A'" label="页面动作标识">
          <el-input
            v-model="menuActionKey"
            :disabled="saving || !!selectedMenu.action_key"
            maxlength="160"
            placeholder="与页面 v-permission 的动作标识一致，如 role/add"
          />
          <p
            >已有动作标识不可修改；尚未配置的按钮可填写一次。动作仅在所属页面使用。</p
          >
        </el-form-item>
        <el-form-item label="引用权限包">
          <el-select
            v-model="menuPermissionKey"
            :disabled="saving"
            :clearable="selectedMenu.default_permission_key !== null"
            filterable
            placeholder="留空恢复源默认权限包"
            style="width: 100%"
          >
            <el-option
              v-if="
                menuPermissionKey &&
                !activeTenantBundles.some(
                  (item) => item.key === menuPermissionKey
                )
              "
              :value="menuPermissionKey"
              :label="`${menuPermissionKey}（当前引用，已不可选）`"
              disabled
            />
            <el-option
              v-for="bundle in activeTenantBundles"
              :key="bundle.key"
              :value="bundle.key"
              :label="`${bundle.name}（${bundle.key}）`"
            />
          </el-select>
          <p
            >源默认：{{
              selectedMenu.default_permission_key === null
                ? '未登记，不能恢复'
                : selectedMenu.default_permission_key || '无需权限包'
            }}。清空并保存后恢复源默认。</p
          >
        </el-form-item>
      </el-form>
      <el-alert
        v-if="menuSaveError"
        :title="menuSaveError"
        type="error"
        :closable="false"
      />
      <template #footer>
        <el-button :disabled="saving" @click="menuVisible = false"
          >关闭</el-button
        >
        <el-button
          v-if="canManage"
          type="primary"
          :loading="saving"
          :disabled="!canSaveMenuBinding"
          @click="saveMenuBinding"
          >保存引用</el-button
        >
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue';
  import { ElMessage } from 'element-plus';
  import { api } from '../api/platform';
  import type {
    PermissionApi,
    PermissionAudience,
    PermissionBundle,
    PermissionMenuBinding,
  } from '../api/permission-bundles';

  const props = defineProps<{
    section: 'bundles' | 'apis' | 'menus';
    canApi: (method: string, path: string) => boolean;
  }>();
  const panelTitles = {
    bundles: '权限包管理',
    apis: 'API 目录与关联',
    menus: '菜单与按钮权限',
  };
  const panelDescriptions = {
    bundles:
      '维护权限包的名称、状态和来源。角色授权、API 关联与菜单引用分别在各自页面管理。',
    apis: '查看接口目录、源默认权限和前置条件；按权限包维护本实例的 API 关联。',
    menus:
      '维护租户菜单和按钮引用的权限包；更改导航显示不会自动授予角色业务权限。',
  };
  const canCreate = computed(() =>
    props.canApi('POST', '/platformapi/permission/bundles')
  );
  const canUpdate = computed(() =>
    props.canApi('PUT', '/platformapi/permission/bundles')
  );
  const canReadBundles = computed(() =>
    props.canApi('GET', '/platformapi/permission/bundles')
  );
  const canReadBindings = computed(() =>
    props.canApi('GET', '/platformapi/permission/apis/bindings')
  );
  const canManage = computed(() =>
    props.section === 'menus'
      ? props.canApi('PUT', '/platformapi/permission/menus/binding')
      : props.canApi('PUT', '/platformapi/permission/apis/bindings')
  );
  const accessModeLabel = (mode: string) =>
    ((
      {
        public: '公开',
        authenticated: '登录后可用',
        context: '上下文接口',
        platform_permission: '平台权限',
        tenant_permission: '租户权限',
      } as Record<string, string>
    )[mode] || mode);
  function chooseBundle(key: string) {
    const bundle = bundles.value.find((item) => item.key === key);
    if (bundle) void open(bundle);
  }
  const audience = ref<PermissionAudience>('tenant');
  const bundles = ref<PermissionBundle[]>([]);
  const apis = ref<PermissionApi[]>([]);
  const loading = ref(false);
  const detailLoading = ref(false);
  const saving = ref(false);
  const error = ref('');
  const detailError = ref('');
  const search = ref('');
  const apiSearch = ref('');
  const visible = ref(false);
  const selected = ref<PermissionBundle | null>(null);
  const editing = ref(false);
  const editingBundle = ref<PermissionBundle | null>(null);
  const definition = ref({
    key: '',
    name: '',
    status: 'active' as 'active' | 'retired',
  });
  const definitionError = ref('');
  const sourceLabel = (bundle: PermissionBundle) =>
    ({ system: '系统内置', module: '模块声明', custom: '后台自建' }[
      bundle.source
    ]);
  const selection = ref<string[]>([]);
  const menuBindings = ref<PermissionMenuBinding[]>([]);
  const menuLoading = ref(false);
  const menuError = ref('');
  const menuSearch = ref('');
  const menuVisible = ref(false);
  const selectedMenu = ref<PermissionMenuBinding | null>(null);
  const menuPermissionKey = ref('');
  const menuActionKey = ref('');
  const menuSaveError = ref('');
  let generation = 0;
  let detailGeneration = 0;
  const message = (cause: unknown) =>
    cause instanceof Error ? cause.message : '权限目录加载失败';
  const visibleBundles = computed(() =>
    bundles.value.filter((item) =>
      `${item.name} ${item.key} ${item.module_key}`
        .toLowerCase()
        .includes(search.value.toLowerCase().trim())
    )
  );
  const visibleApis = computed(() =>
    apis.value.filter((item) =>
      `${item.method} ${item.path} ${item.module_key}`
        .toLowerCase()
        .includes(apiSearch.value.toLowerCase().trim())
    )
  );
  const activeTenantBundles = computed(() =>
    bundles.value.filter(
      (item) => item.audience === 'tenant' && item.status === 'active'
    )
  );
  const visibleMenuBindings = computed(() =>
    menuBindings.value.filter((item) =>
      `${item.name} ${item.page_path} ${item.action_key} ${item.permission_key}`
        .toLowerCase()
        .includes(menuSearch.value.toLowerCase().trim())
    )
  );
  const canSaveMenuBinding = computed(() => {
    const row = selectedMenu.value;
    return (
      !!row &&
      canManage.value &&
      !saving.value &&
      !menuSaveError.value &&
      !menuLoading.value &&
      !menuError.value &&
      !error.value &&
      (row.type !== 'A' || (!!row.page_path && !!menuActionKey.value.trim())) &&
      (menuPermissionKey.value
        ? activeTenantBundles.value.some(
            (item) => item.key === menuPermissionKey.value
          )
        : row.default_permission_key !== null)
    );
  });
  const bindable = (item: PermissionApi) =>
    item.access_mode === `${audience.value}_permission` &&
    (item.status === 'active' || selected.value?.api_ids.includes(item.id));

  async function readAll<T>(
    read: (page: number) => Promise<{ items: T[]; total: number }>
  ): Promise<T[]> {
    const first = await read(1);
    const items = [...first.items];
    const pages = Math.ceil(first.total / 100);
    for (let page = 2; page <= pages; page += 1) {
      const next = await read(page);
      if (next.total !== first.total || !next.items.length)
        throw new Error('权限目录在读取期间变化，请刷新后重试');
      items.push(...next.items);
    }
    if (items.length !== first.total) throw new Error('权限目录未完整加载');
    return items;
  }

  async function load() {
    const request = ++generation;
    detailGeneration += 1;
    visible.value = false;
    editing.value = false;
    selected.value = null;
    menuVisible.value = false;
    menuBindings.value = [];
    menuError.value = '';
    bundles.value = [];
    apis.value = [];
    error.value = '';
    loading.value = true;
    const scope = audience.value;
    try {
      if (props.section === 'menus') {
        await loadMenuBindings(request);
        if (canReadBundles.value) {
          const rows = await readAll((page) =>
            api.permissionBundles('tenant', page)
          );
          if (request === generation) bundles.value = rows;
        }
      } else if (props.section === 'bundles') {
        const rows = await readAll((page) =>
          api.permissionBundles(scope, page)
        );
        if (request === generation) bundles.value = rows;
      } else {
        const rows = await readAll((page) => api.permissionApis(scope, page));
        if (request === generation) apis.value = rows;
        if (canReadBundles.value && canReadBindings.value) {
          const bundleRows = await readAll((page) =>
            api.permissionBundles(scope, page)
          );
          if (request === generation) bundles.value = bundleRows;
        }
      }
      if (request !== generation) return;
      if (
        bundles.value.some((row) => row.audience !== scope) ||
        apis.value.some((row) => row.audience !== scope)
      )
        throw new Error('权限目录安全域不一致');
    } catch (cause) {
      if (request === generation) error.value = message(cause);
    } finally {
      if (request === generation) loading.value = false;
    }
  }

  async function loadMenuBindings(request: number) {
    menuVisible.value = false;
    selectedMenu.value = null;
    menuError.value = '';
    menuLoading.value = true;
    try {
      const result = await api.permissionMenus();
      if (request !== generation || audience.value !== 'tenant') return;
      if (result.total !== result.items.length)
        throw new Error('菜单引用未完整加载');
      menuBindings.value = result.items;
    } catch (cause) {
      if (request === generation) {
        menuBindings.value = [];
        menuError.value = message(cause);
      }
    } finally {
      if (request === generation) menuLoading.value = false;
    }
  }

  function openMenuBinding(row: PermissionMenuBinding) {
    if (!canManage.value || !canReadBundles.value) return;
    selectedMenu.value = row;
    menuPermissionKey.value = row.permission_key;
    menuActionKey.value = row.action_key;
    menuSaveError.value = '';
    menuVisible.value = true;
  }

  async function saveMenuBinding() {
    if (!canSaveMenuBinding.value || !selectedMenu.value) return;
    const selected = selectedMenu.value;
    saving.value = true;
    try {
      const row = await api.replacePermissionMenuBinding({
        menu_key: selected.menu_key,
        permission_key: menuPermissionKey.value || null,
        action_key:
          selected.type === 'A'
            ? selected.action_key || menuActionKey.value.trim()
            : null,
        expected_revision: selected.revision,
      });
      if (row.menu_key !== selected.menu_key)
        throw new Error('菜单引用响应目标不一致');
      menuBindings.value = menuBindings.value.map((item) =>
        item.menu_key === row.menu_key ? row : item
      );
      menuVisible.value = false;
      ElMessage.success('菜单与按钮引用已保存');
    } catch (cause) {
      menuSaveError.value = `${message(
        cause
      )}。请关闭并刷新引用，核对最新状态后再操作。`;
      menuError.value = menuSaveError.value;
    } finally {
      saving.value = false;
    }
  }

  function create() {
    if (!canCreate.value) return;
    editingBundle.value = null;
    definition.value = { key: '', name: '', status: 'active' };
    definitionError.value = '';
    editing.value = true;
  }

  function edit(bundle: PermissionBundle) {
    if (!canUpdate.value || bundle.source !== 'custom') return;
    editingBundle.value = bundle;
    definition.value = {
      key: bundle.key,
      name: bundle.name,
      status: bundle.status === 'active' ? 'active' : 'retired',
    };
    definitionError.value = '';
    editing.value = true;
  }

  async function saveDefinition() {
    if (
      (editingBundle.value ? !canUpdate.value : !canCreate.value) ||
      saving.value ||
      !definition.value.key.trim() ||
      !definition.value.name.trim()
    )
      return;
    saving.value = true;
    definitionError.value = '';
    try {
      const bundle = editingBundle.value;
      const row = bundle
        ? await api.updatePermissionBundle({
            permission_key: bundle.key,
            name: definition.value.name.trim(),
            status: definition.value.status,
            expected_revision: bundle.revision,
          })
        : await api.createPermissionBundle({
            key: definition.value.key.trim(),
            name: definition.value.name.trim(),
            audience: audience.value,
          });
      if (row.audience !== audience.value || (bundle && row.key !== bundle.key))
        throw new Error('权限包响应目标不一致');
      bundles.value = [
        ...bundles.value.filter((item) => item.key !== row.key),
        row,
      ];
      editing.value = false;
      ElMessage.success('权限包已保存');
    } catch (cause) {
      definitionError.value = message(cause);
    } finally {
      saving.value = false;
    }
  }

  async function open(bundle: PermissionBundle) {
    if (!canReadBindings.value || props.section !== 'apis') return;
    const request = ++detailGeneration;
    visible.value = true;
    detailLoading.value = true;
    detailError.value = '';
    selected.value = null;
    selection.value = [];
    apiSearch.value = '';
    try {
      const row = await api.permissionBindings(bundle.key);
      if (request !== detailGeneration || !visible.value) return;
      if (row.key !== bundle.key || row.audience !== audience.value)
        throw new Error('权限包响应目标不一致');
      if (row.api_ids.some((id) => !apis.value.some((item) => item.id === id)))
        throw new Error('关联 API 未完整包含于目录，不能编辑');
      selected.value = row;
      selection.value = [...row.api_ids];
    } catch (cause) {
      if (request === detailGeneration) detailError.value = message(cause);
    } finally {
      if (request === detailGeneration) detailLoading.value = false;
    }
  }

  async function save() {
    if (
      !canManage.value ||
      selected.value?.status !== 'active' ||
      !selected.value ||
      saving.value ||
      detailLoading.value ||
      detailError.value
    )
      return;
    saving.value = true;
    const bundle = selected.value;
    try {
      const row = await api.replacePermissionBindings(
        bundle.key,
        bundle.revision,
        [...selection.value]
      );
      if (row.key !== bundle.key || row.audience !== audience.value)
        throw new Error('权限包响应目标不一致');
      selected.value = row;
      bundles.value = bundles.value.map((item) =>
        item.key === row.key ? row : item
      );
      ElMessage.success('API 关联已保存');
      visible.value = false;
    } catch (cause) {
      ElMessage.error(message(cause));
    } finally {
      saving.value = false;
    }
  }
  watch(audience, load);
  watch(visible, (value) => {
    if (!value) detailGeneration += 1;
  });
  onBeforeUnmount(() => {
    generation += 1;
    detailGeneration += 1;
  });
  load();
</script>

<style scoped>
  .binding-card {
    margin-top: 18px;
  }
  .el-select {
    min-width: min(320px, 80vw);
  }
  .permission-api-row {
    margin: 8px 0;
  }
  small {
    margin-left: 8px;
  }
</style>
