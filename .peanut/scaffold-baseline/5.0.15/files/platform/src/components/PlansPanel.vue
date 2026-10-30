<template>
  <section v-loading="loading">
    <el-alert
      title="套餐定义可选模块与权限组合的权益上限；人员仍须单独授权。停售不撤销已引用套餐的许可证。"
      type="info"
      :closable="false"
    />
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <div class="toolbar">
      <el-button @click="load">刷新</el-button>
      <el-button v-if="canManage" type="primary" @click="open()"
        >新建套餐</el-button
      >
    </div>
    <el-table :data="plans" empty-text="尚未创建套餐">
      <el-table-column prop="code" label="编码" />
      <el-table-column prop="name" label="名称" />
      <el-table-column label="状态"
        ><template #default="{ row }">{{
          row.status === 'active' ? '在售' : '停售'
        }}</template></el-table-column
      >
      <el-table-column label="模块"
        ><template #default="{ row }">{{
          row.module_keys.join('、') || '无可选模块'
        }}</template></el-table-column
      >
      <el-table-column label="权限组合"
        ><template #default="{ row }">{{
          row.permission_keys.join('、') || '无'
        }}</template></el-table-column
      >
      <el-table-column v-if="canManage" label="操作"
        ><template #default="{ row }"
          ><el-button link type="primary" @click="open(row)"
            >编辑</el-button
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
      <el-form label-position="top" @submit.prevent="save">
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
              :label="item.module_key"
              :disabled="item.status !== 'active'"
            />
          </el-select>
        </el-form-item>
        <p>必需底座（无需购买）：{{ requiredModules.join('、') || '无' }}</p>
        <p v-for="item in selectedModules" :key="item.module_key"
          >{{ item.module_key }} 依赖：{{
            item.tenant.requires.join('、') || '无'
          }}</p
        >
        <el-alert
          v-if="missingDependencies.length"
          :title="`请同时选择依赖模块：${missingDependencies.join('、')}`"
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
              :label="`${item.name} (${item.key}) · ${item.module_key}`"
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
          :disabled="loading || !!catalogError || !!missingDependencies.length"
          @click="save"
          >保存</el-button
        ></template
      >
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
  import { computed, onMounted, reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import { api } from '../api/platform';
  import type { Plan, PlanModule } from '../api/tenant-license';
  import type { PermissionBundle } from '../api/permission-bundles';

  defineProps<{ canManage: boolean }>();
  const plans = ref<Plan[]>([]);
  const modules = ref<PlanModule[]>([]);
  const bundles = ref<PermissionBundle[]>([]);
  const page = ref(1);
  const total = ref(0);
  const loading = ref(false);
  const saving = ref(false);
  const error = ref('');
  const catalogError = ref('');
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
  async function load() {
    loading.value = true;
    error.value = '';
    try {
      const result = await api.plans(page.value);
      plans.value = result.lists;
      total.value = result.count;
    } catch (cause) {
      error.value = message(cause);
    } finally {
      loading.value = false;
    }
  }
  async function loadCatalog() {
    catalogError.value = '';
    try {
      const moduleRows: PlanModule[] = [];
      for (let current = 1; ; current += 1) {
        const result = await api.planModules(current);
        moduleRows.push(...result.lists);
        if (moduleRows.length >= result.count) break;
        if (!result.lists.length) throw new Error('模块目录分页不完整');
      }
      const bundleRows: PermissionBundle[] = [];
      for (let current = 1; ; current += 1) {
        const result = await api.permissionBundles('tenant', current);
        bundleRows.push(...result.items);
        if (bundleRows.length >= result.total) break;
        if (!result.items.length) throw new Error('权限组合目录分页不完整');
      }
      modules.value = moduleRows;
      bundles.value = bundleRows.filter((item) => item.audience === 'tenant');
    } catch (cause) {
      catalogError.value = message(cause);
    }
  }
  async function open(plan?: Plan) {
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
    formError.value = catalogError.value;
  }
  async function save() {
    if (saving.value || catalogError.value || missingDependencies.value.length)
      return;
    if (!form.code.trim() || !form.name.trim() || !form.change_reason.trim()) {
      formError.value = '请填写编码、名称及变更原因';
      return;
    }
    saving.value = true;
    formError.value = '';
    try {
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
    } catch (cause) {
      formError.value = message(cause);
    } finally {
      saving.value = false;
    }
  }
  function changePage(value: number) {
    page.value = value;
    void load();
  }
  onMounted(load);
</script>
