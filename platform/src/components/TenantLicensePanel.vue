<template>
  <section v-loading="loading">
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-alert
      title="每个租户只有一张当前许可证。换套餐修改当前引用，续期修改期限；暂停或到期不锁登录、安全及所有者核心管理，也不会自动回落免费套餐。"
      type="info"
      :closable="false"
    />
    <el-descriptions v-if="loaded" :column="2" border>
      <el-descriptions-item label="当前套餐">{{
        license ? `${license.plan_name} (${license.plan_code})` : '未开通'
      }}</el-descriptions-item>
      <el-descriptions-item label="许可证状态">{{
        license ? stateLabels[license.state] : '未开通'
      }}</el-descriptions-item>
      <el-descriptions-item label="生效时间">{{
        license ? displayTime(license.effective_at) : '—'
      }}</el-descriptions-item>
      <el-descriptions-item label="到期时间">{{
        license
          ? license.expires_at
            ? displayTime(license.expires_at)
            : '长期有效'
          : '—'
      }}</el-descriptions-item>
    </el-descriptions>
    <div class="toolbar"
      ><el-button :disabled="saving" @click="load">刷新许可证</el-button
      ><el-button
        v-if="canManage && loaded"
        type="primary"
        :disabled="loading"
        @click="open"
        >{{ license ? '换套餐 / 续期 / 暂停' : '开通许可证' }}</el-button
      ></div
    >
    <el-dialog
      v-model="visible"
      title="维护当前许可证"
      width="min(600px, 94vw)"
      :close-on-click-modal="false"
    >
      <el-alert
        v-if="formError"
        :title="formError"
        type="error"
        :closable="false"
      />
      <el-form label-position="top" @submit.prevent="save">
        <el-form-item label="套餐"
          ><el-select v-model="form.plan_id" filterable style="width: 100%"
            ><el-option
              v-for="item in plans"
              :key="item.id"
              :value="item.id"
              :label="`${item.name} (${item.code})${
                item.status === 'inactive' ? ' · 已停售' : ''
              }`"
              :disabled="
                item.status === 'inactive' && item.id !== license?.plan_id
              " /></el-select
        ></el-form-item>
        <el-form-item label="许可证状态"
          ><el-select v-model="form.status"
            ><el-option
              label="启用（还需在有效期内）"
              value="active" /><el-option
              label="暂停"
              value="suspended" /></el-select
        ></el-form-item>
        <el-form-item label="生效时间（当前时区）"
          ><el-date-picker v-model="form.effective_at" type="datetime"
        /></el-form-item>
        <el-form-item label="到期时间（留空为长期有效）"
          ><el-date-picker v-model="form.expires_at" type="datetime" clearable
        /></el-form-item>
        <el-form-item label="变更原因"
          ><el-input
            v-model="form.change_reason"
            type="textarea"
            maxlength="500"
        /></el-form-item>
      </el-form>
      <p
        >保存后仍需在租户后台给具体成员授权。停售套餐只能保留当前引用，不能新开通。</p
      >
      <template #footer
        ><el-button :disabled="saving" @click="visible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="saving"
          :disabled="catalogLoading || !!catalogError"
          @click="save"
          >保存许可证</el-button
        ></template
      >
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
  import { onBeforeUnmount, reactive, ref, watch } from 'vue';
  import { ElMessage } from 'element-plus';
  import { api } from '../api/platform';
  import type { Plan, TenantLicense } from '../api/tenant-license';
  const props = defineProps<{ tenantId: number; canManage: boolean }>();
  const emit = defineEmits<{ (event: 'saved'): void }>();
  const license = ref<TenantLicense | null>(null);
  const plans = ref<Plan[]>([]);
  const loading = ref(false);
  const saving = ref(false);
  const loaded = ref(false);
  const visible = ref(false);
  const catalogLoading = ref(false);
  const catalogError = ref('');
  const error = ref('');
  const formError = ref('');
  let request = 0;
  const form = reactive({
    plan_id: null as number | null,
    status: 'active' as TenantLicense['status'],
    effective_at: null as Date | null,
    expires_at: null as Date | null,
    change_reason: '',
  });
  const stateLabels = {
    active: '生效中',
    suspended: '已暂停',
    upcoming: '未生效',
    expired: '已到期',
  };
  function displayTime(value: string) {
    return new Date(value).toLocaleString();
  }
  function message(cause: unknown) {
    return cause instanceof Error ? cause.message : '请求失败';
  }
  async function load() {
    const current = ++request;
    const tenantId = props.tenantId;
    loading.value = true;
    loaded.value = false;
    error.value = '';
    try {
      const result = await api.tenantLicense(tenantId);
      if (current !== request) return;
      license.value = result.license;
      loaded.value = true;
    } catch (cause) {
      if (current === request) error.value = message(cause);
    } finally {
      if (current === request) loading.value = false;
    }
  }
  async function open() {
    const current = request;
    const row = license.value;
    Object.assign(form, {
      plan_id: row?.plan_id || null,
      status: row?.status || 'active',
      effective_at: row ? new Date(row.effective_at) : new Date(),
      expires_at: row?.expires_at ? new Date(row.expires_at) : null,
      change_reason: '',
    });
    visible.value = true;
    formError.value = '';
    catalogError.value = '';
    catalogLoading.value = true;
    try {
      const rows: Plan[] = [];
      for (let page = 1; ; page += 1) {
        const result = await api.plans(page);
        rows.push(...result.lists);
        if (rows.length >= result.count) break;
        if (!result.lists.length) throw new Error('套餐目录分页不完整');
      }
      if (current === request) plans.value = rows;
    } catch (cause) {
      if (current === request) {
        catalogError.value = message(cause);
        formError.value = catalogError.value;
      }
    } finally {
      if (current === request) catalogLoading.value = false;
    }
  }
  async function save() {
    if (saving.value || catalogLoading.value || catalogError.value) return;
    if (!form.plan_id || !form.effective_at || !form.change_reason.trim()) {
      formError.value = '请选择套餐、生效时间并填写变更原因';
      return;
    }
    if (form.expires_at && form.expires_at <= form.effective_at) {
      formError.value = '到期时间须晚于生效时间';
      return;
    }
    const current = request;
    saving.value = true;
    formError.value = '';
    try {
      const result = await api.saveTenantLicense({
        tenant_id: props.tenantId,
        plan_id: form.plan_id,
        status: form.status,
        effective_at: form.effective_at.toISOString(),
        expires_at: form.expires_at?.toISOString() || null,
        expected_revision: license.value?.revision || 0,
        change_reason: form.change_reason.trim(),
      });
      if (current !== request) return;
      license.value = result.license;
      visible.value = false;
      ElMessage.success('许可证已保存');
      emit('saved');
    } catch (cause) {
      if (current === request) formError.value = message(cause);
    } finally {
      saving.value = false;
    }
  }
  watch(
    () => props.tenantId,
    () => {
      visible.value = false;
      license.value = null;
      void load();
    },
    { immediate: true }
  );
  onBeforeUnmount(() => {
    request += 1;
  });
</script>
