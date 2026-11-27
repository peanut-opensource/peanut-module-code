<template>
  <section v-loading="loading">
    <el-alert
      title="套餐定义可选模块与权限组合的权益上限；人员仍须单独授权。停售不撤销已引用套餐的许可证。"
      type="info"
      :closable="false"
    />
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-alert
      v-if="(canCreate || canUpdate) && !canReadCatalog"
      title="编辑套餐需要读取模块和权限包目录，请联系管理员配置相应读取权限。"
      type="info"
      :closable="false"
    />
    <div class="toolbar">
      <el-button @click="load">刷新</el-button>
      <el-button v-if="error" :disabled="loading" @click="retryPage"
        >重试</el-button
      >
      <el-button
        v-if="canCreate && canReadCatalog"
        type="primary"
        @click="open()"
        >{{ platformPlanActions.create.label }}</el-button
      >
    </div>
    <el-table
      :data="plans"
      :empty-text="
        error ? '套餐列表加载失败' : loading ? '正在加载套餐…' : '尚未创建套餐'
      "
    >
      <el-table-column prop="code" label="编码" />
      <el-table-column prop="name" label="名称" />
      <el-table-column label="状态"
        ><template #default="{ row }">{{
          row.status === 'active' ? '在售' : '停售'
        }}</template></el-table-column
      >
      <el-table-column label="模块"
        ><template #default="{ row }">{{
          row.module_keys.map((key: string) => moduleLabel(key)).join('、') ||
          '无可选模块'
        }}</template></el-table-column
      >
      <el-table-column label="权限组合"
        ><template #default="{ row }">{{
          row.permission_keys.join('、') || '无'
        }}</template></el-table-column
      >
      <el-table-column v-if="canUpdate && canReadCatalog" label="操作"
        ><template #default="{ row }"
          ><el-button link type="primary" @click="open(row)"
            >{{ platformPlanActions.update.label }}</el-button
          ></template
        ></el-table-column
      >
    </el-table>
    <el-pagination
      :current-page="page"
      :page-size="100"
      :total="total"
      layout="prev, pager, next, total"
      @current-change="changePage"
    />
    <el-dialog
      v-model="visible"
      :title="editing ? '编辑套餐' : '新建套餐'"
      width="min(760px, 94vw)"
      :close-on-click-modal="false"
    >
      <el-alert
        v-if="formError"
        :title="formError"
        type="error"
        :closable="false"
      />
      <el-form
        v-loading="catalogLoading"
        label-position="top"
        @submit.prevent="save"
      >
        <el-form-item label="编码"
          ><el-input v-model="form.code" :disabled="!!editing" maxlength="64"
        /></el-form-item>
        <el-form-item label="名称"
          ><el-input v-model="form.name" maxlength="160"
        /></el-form-item>
        <el-form-item v-if="editing" label="销售状态"
          ><el-select v-model="form.status"
            ><el-option label="在售" value="active" /><el-option
              label="停售（现有许可证继续按期限生效）"
              value="inactive" /></el-select
        ></el-form-item>
        <el-form-item label="可选租户模块">
          <el-select
            v-model="form.module_keys"
            multiple
            filterable
            style="width: 100%"
          >
            <el-option
              v-for="item in optionalModules"
              :key="item.module_key"
              :value="item.module_key"
              :label="moduleLabel(item.module_key, item.name)"
              :disabled="item.status !== 'active'"
            />
          </el-select>
        </el-form-item>
        <p>必需底座（无需购买）：{{
          requiredModules.map((key) => moduleLabel(key)).join('、') || '无'
        }}</p>
        <p v-for="item in selectedModules" :key="item.module_key"
          >{{ moduleLabel(item.module_key, item.name) }} 依赖：{{
            item.tenant.requires.map((key) => moduleLabel(key)).join('、') || '无'
          }}</p
        >
        <el-alert
          v-if="missingDependencies.length"
          :title="`请同时选择依赖模块：${missingDependencies.map((key) => moduleLabel(key)).join('、')}`"
          type="warning"
          :closable="false"
        />
        <el-form-item label="租户权限组合">
          <el-select
            v-model="form.permission_keys"
            multiple
            filterable
            style="width: 100%"
          >
            <el-option
              v-for="item in bundles"
              :key="item.key"
              :value="item.key"
              :label="`${item.name} (${item.key}) · ${moduleLabel(item.module_key)}`"
              :disabled="
                item.status !== 'active' ||
                !entitledModules.includes(item.module_key)
              "
            />
          </el-select>
        </el-form-item>
        <p
          >权限组合仅配置权益；不会自动授予所有成员或所有者。编辑套餐会影响引用它的当前许可证。</p
        >
        <el-form-item label="变更原因"
          ><el-input
            v-model="form.change_reason"
            type="textarea"
            maxlength="500"
        /></el-form-item>
      </el-form>
      <template #footer
        ><el-button :disabled="saving" @click="visible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="saving"
          :disabled="
            loading ||
            catalogLoading ||
            !!catalogError ||
            !!missingDependencies.length
          "
          @click="save"
          >保存</el-button
        ></template
      >
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
  import { useAsyncAction, useAsyncList } from '@peanut-admin/vue';
  import { toRef } from 'vue';
  import { computed, onMounted, reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import { api } from '../api/platform';
  import type { Plan, PlanModule } from '../api/tenant-license';
  import type { PermissionBundle } from '../api/permission-bundles';
  import { platformDeveloperRuntimeRead, platformPlanActions, platformPlanCatalogReads } from '../core/platform-shell-definitions';

  const props = defineProps<{
    canApi: (method: string, path: string) => boolean;
  }>();
  const canCreate = computed(() => props.canApi(...platformPlanActions.create.endpoints[0]));
  const canUpdate = computed(() => props.canApi(...platformPlanActions.update.endpoints[0]));
  const canReadCatalog = computed(
    () =>
      platformPlanCatalogReads.every(([method, path]) => props.canApi(method, path))
  );
  const modules = ref<PlanModule[]>([]);
  const moduleNames = ref(new Map<string, string>());
  const canReadModuleNames = computed(() =>
    props.canApi(...platformDeveloperRuntimeRead.endpoints[0])
  );
  function moduleLabel(key: string, sourceName?: string): string {
    const name = sourceName ?? moduleNames.value.get(key);
    return name && name !== key ? `${name}（${key}）` : key;
  }
  async function loadModuleNames(): Promise<void> {
    if (!canReadModuleNames.value) return;
    try {
      const first = await api.planModules(1);
      const rows = [...first.lists];
      const pages = Math.ceil(first.count / 100);
      for (let page = 2; page <= pages; page += 1) {
        const next = await api.planModules(page);
        if (next.count !== first.count || next.lists.length === 0)
          throw new Error('模块目录读取期间发生变化');
        rows.push(...next.lists);
      }
      if (rows.length !== first.count ||
          new Set(rows.map((row) => row.module_key)).size !== rows.length)
        throw new Error('模块目录不完整');
      moduleNames.value = new Map(rows.map(({ module_key, name }) => [module_key, name]));
    } catch {
      // This is a display-only read. Never turn a missing catalog privilege
      // into a blocking plan read or derive names from another tenant.
      moduleNames.value = new Map();
    }
  }
  const bundles = ref<PermissionBundle[]>([]);
  const formError = ref('');
  const visible = ref(false);
  const editing = ref<Plan | null>(null);
  const form = reactive({
    code: '',
    name: '',
    status: 'active' as Plan['status'],
    module_keys: [] as string[],
    permission_keys: [] as string[],
    change_reason: '',
  });
  const optionalModules = computed(() =>
    modules.value.filter(
      (item) => item.tenant.enableable && !item.tenant.required
    )
  );
  const requiredModules = computed(() =>
    modules.value
      .filter((item) => item.tenant.required)
      .map((item) => item.module_key)
  );
  const entitledModules = computed(() => [
    ...requiredModules.value,
    ...form.module_keys,
  ]);
  const selectedModules = computed(() =>
    modules.value.filter((item) => form.module_keys.includes(item.module_key))
  );
  const missingDependencies = computed(() => [
    ...new Set(
      selectedModules.value
        .flatMap((item) => item.tenant.requires)
        .filter((key) => !entitledModules.value.includes(key))
    ),
  ]);
  function message(cause: unknown) {
    return cause instanceof Error ? cause.message : '请求失败';
  }
  const {
    items: plans,
    pagination,
    loading,
    error,
    load: loadPage,
    reload: load,
  } = useAsyncList({
    initialFilters: {},
    initialPageSize: 100,
    errorMessage: message,
    load: async ({ page, pageSize }) => {
      const result = await api.plans(page);
      return { items: result.lists, total: result.count, page, pageSize };
    },
  });
  const page = toRef(pagination, 'page');
  const total = toRef(pagination, 'total');
  const { loading: saving, run: runSave } = useAsyncAction(message);

  const {
    loading: catalogLoading,
    error: catalogError,
    run: runCatalog,
    cancel: cancelCatalog,
  } = useAsyncAction(message);
  async function loadCatalog() {
    cancelCatalog();
    modules.value = [];
    bundles.value = [];
    await runCatalog(async ({ signal }) => {
      const isCurrent = () => !signal.aborted && canReadCatalog.value;
      const loadAllPages = async <T>(
        fetchPage: (page: number) => Promise<{ rows: T[]; total: number }>,
        driftMessage: string
      ): Promise<T[] | null> => {
        const first = await fetchPage(1);
        if (!isCurrent()) return null;
        const pages = Math.min(100, Math.max(1, Math.ceil(first.total / 100)));
        if (pages === 1) return [...first.rows];
        const buckets: T[][] = new Array(pages);
        buckets[0] = first.rows;
        let nextPage = 2;
        const worker = async () => {
          while (nextPage <= pages) {
            if (!isCurrent()) return;
            const current = nextPage++;
            const result = await fetchPage(current);
            if (!isCurrent()) return;
            if (result.total !== first.total || !result.rows.length)
              throw new Error(driftMessage);
            buckets[current - 1] = result.rows;
          }
        };
        await Promise.all(
          Array.from({ length: Math.min(4, pages - 1) }, () => worker())
        );
        if (!isCurrent()) return null;
        return buckets.flat();
      };

      const [moduleRows, bundleRows] = await Promise.all([
        loadAllPages(async (page) => {
          const res = await api.planModules(page);
          return { rows: res.lists, total: res.count };
        }, '模块目录在读取期间变化，请重试'),
        loadAllPages(async (page) => {
          const res = await api.permissionBundles('tenant', page);
          return { rows: res.items, total: res.total };
        }, '权限组合目录在读取期间变化，请重试'),
      ]);
      if (moduleRows === null || bundleRows === null || !isCurrent()) return;
      if (
        new Set(moduleRows.map((item) => item.module_key)).size !==
        moduleRows.length
      )
        throw new Error('模块目录分页不完整');
      if (
        new Set(bundleRows.map((item) => item.key)).size !==
          bundleRows.length ||
        bundleRows.some((item) => item.audience !== 'tenant')
      )
        throw new Error('权限组合目录分页不完整');
      modules.value = moduleRows;
      moduleNames.value = new Map(moduleRows.map(({ module_key, name }) => [module_key, name]));
      bundles.value = bundleRows;
    });
  }
  async function open(plan?: Plan) {
    if (!canReadCatalog.value || (plan ? !canUpdate.value : !canCreate.value))
      return;
    editing.value = plan || null;
    Object.assign(form, {
      code: plan?.code || '',
      name: plan?.name || '',
      status: plan?.status || 'active',
      module_keys: [...(plan?.module_keys || [])],
      permission_keys: [...(plan?.permission_keys || [])],
      change_reason: '',
    });
    formError.value = '';
    visible.value = true;
    await loadCatalog();
    formError.value = catalogError.value ?? '';
  }
  async function save() {
    if (
      !canReadCatalog.value ||
      (editing.value ? !canUpdate.value : !canCreate.value)
    )
      return;
    if (
      saving.value ||
      catalogLoading.value ||
      catalogError.value ||
      missingDependencies.value.length
    )
      return;
    if (!form.code.trim() || !form.name.trim() || !form.change_reason.trim()) {
      formError.value = '请填写编码、名称及变更原因';
      return;
    }
    formError.value = '';
    const result = await runSave(async () => {
      const details = {
        name: form.name.trim(),
        module_keys: [...form.module_keys],
        permission_keys: [...form.permission_keys],
        change_reason: form.change_reason.trim(),
      };
      if (editing.value)
        await api.updatePlan({
          ...details,
          id: editing.value.id,
          expected_revision: editing.value.revision,
          status: form.status,
        });
      else await api.createPlan({ ...details, code: form.code.trim() });
      visible.value = false;
      ElMessage.success('套餐已保存');
      await load();
    });
    if (result.status === 'failed') formError.value = result.error;
  }
  function changePage(value: number) {
    void loadPage(value);
  }
  function retryPage() {
    void loadPage(pagination.page);
  }
  onMounted(() => {
    void load();
    void loadModuleNames();
  });
</script>
