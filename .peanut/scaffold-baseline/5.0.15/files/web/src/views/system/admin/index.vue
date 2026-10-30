<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.admin']" />

    <el-card class="general-card search-card">
      <el-form :model="queryParams" inline>
        <el-form-item prop="account" :label="$t('systemAdmin.search.account')">
          <el-input
            v-model="queryParams.account"
            clearable
            :placeholder="$t('systemAdmin.search.account.placeholder')"
            @keyup.enter="search"
          />
        </el-form-item>
        <el-form-item prop="name" :label="$t('systemAdmin.search.name')">
          <el-input
            v-model="queryParams.name"
            clearable
            :placeholder="$t('systemAdmin.search.name.placeholder')"
            @keyup.enter="search"
          />
        </el-form-item>
        <el-form-item prop="role_id" :label="$t('systemAdmin.search.role')">
          <el-select
            v-model="queryParams.role_id"
            :disabled="!canReadRoles"
            clearable
            :placeholder="$t('systemAdmin.search.role.all')"
            style="width: 180px"
            ><el-option
              v-for="option in roleOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
          /></el-select>
        </el-form-item>
        <el-form-item>
          <el-space>
            <el-button type="primary" @click="search">
              <template #icon><Search /></template>
              {{ $t('systemAdmin.operation.search') }}
            </el-button>
            <el-button @click="resetQuery">
              <template #icon><Refresh /></template>
              {{ $t('systemAdmin.operation.reset') }}
            </el-button>
            <el-button @click="openExport">
              <template #icon><Download /></template>
              {{ $t('systemAdmin.operation.export') }}
            </el-button>
          </el-space>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="general-card list-card">
      <template #header>{{ $t('menu.system.admin') }}</template>
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-button
            v-permission="['core.member.create']"
            :disabled="!canAssignRoles || !canReadRoles"
            type="primary"
            @click="handleAdd"
          >
            <template #icon><Plus /></template>
            {{ $t('systemAdmin.operation.create') }}
          </el-button>
        </el-col>
      </el-row>

      <el-table row-key="id" :loading="loading" :data="renderData" border>
        <el-table-column
          prop="id"
          :label="$t('systemAdmin.columns.id')"
          width="70"
        />
        <el-table-column :label="$t('systemAdmin.columns.avatar')" width="80"
          ><template #default="{ row }"
            ><el-avatar :size="42" :src="row.avatar">{{
              row.name?.slice(0, 1)
            }}</el-avatar></template
          ></el-table-column
        >
        <el-table-column
          prop="account"
          :label="$t('systemAdmin.columns.account')"
          width="130"
        />
        <el-table-column
          prop="name"
          :label="$t('systemAdmin.columns.name')"
          width="130"
        />
        <el-table-column
          prop="role_name"
          :label="$t('systemAdmin.columns.roles')"
          width="150"
          show-overflow-tooltip
        />
        <el-table-column
          prop="dept_name"
          :label="$t('systemAdmin.columns.dept')"
          width="150"
          show-overflow-tooltip
        />
        <el-table-column
          prop="create_time"
          :label="$t('systemAdmin.columns.createTime')"
          width="180"
        />
        <el-table-column
          :label="$t('systemAdmin.columns.loginTime')"
          width="180"
          ><template #default="{ row }">{{
            formatLoginTime(row.login_time)
          }}</template></el-table-column
        >
        <el-table-column :label="$t('systemAdmin.columns.loginIp')" width="150"
          ><template #default="{ row }">{{
            row.login_ip || '-'
          }}</template></el-table-column
        >
        <el-table-column :label="$t('systemAdmin.columns.status')" width="130"
          ><template #default="{ row }"
            ><el-switch
              v-permission="['core.member.update']"
              :model-value="row.disable === 0"
              :disabled="isProtected(row) || !canChangeStatus(row.disable)"
              @change="(value: string | number | boolean) => handleStatus(row, value as boolean)"
            /><span>{{ row.disable_desc }}</span
            ><el-tag
              v-if="row.root === 1"
              type="warning"
              size="small"
              style="margin-left: 6px"
              >{{ $t('systemAdmin.root.yes') }}</el-tag
            ></template
          ></el-table-column
        >
        <el-table-column
          :label="$t('systemAdmin.columns.multipoint')"
          width="120"
          ><template #default="{ row }"
            ><el-tag :type="row.multipoint_login === 1 ? 'success' : 'info'">{{
              row.multipoint_login === 1
                ? $t('systemAdmin.common.yes')
                : $t('systemAdmin.common.no')
            }}</el-tag></template
          ></el-table-column
        >
        <el-table-column
          :label="$t('systemAdmin.columns.operations')"
          width="150"
          fixed="right"
          ><template #default="{ row }"
            ><el-space
              ><el-button
                v-permission="['core.member.update']"
                link
                size="small"
                @click="handleEdit(row)"
                >{{ $t('systemAdmin.operation.edit') }}</el-button
              ><el-popconfirm
                v-if="!isProtected(row)"
                :title="$t('systemAdmin.delete.confirm')"
                @confirm="handleDelete(row)"
                ><template #reference
                  ><el-button
                    v-permission="['core.member.leave']"
                    link
                    type="danger"
                    size="small"
                    >{{ $t('systemAdmin.operation.delete') }}</el-button
                  ></template
                ></el-popconfirm
              ></el-space
            ></template
          ></el-table-column
        >
      </el-table>
      <el-pagination
        v-model:current-page="pagination.current"
        v-model:page-size="pagination.pageSize"
        :total="pagination.total"
        :page-sizes="[10, 15, 30, 50]"
        layout="total, sizes, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="onPageChange"
        @size-change="onPageSizeChange"
      />
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="
        isEdit
          ? $t('systemAdmin.modal.editTitle')
          : $t('systemAdmin.modal.addTitle')
      "
      :close-on-click-modal="false"
      width="620px"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="account" :label="$t('systemAdmin.field.account')">
          <el-input
            v-model="form.account"
            :disabled="isEdit || form.root === 1"
            maxlength="255"
            :placeholder="$t('systemAdmin.field.account.placeholder')"
          />
        </el-form-item>

        <p class="form-tip">头像由账号本人在个人设置维护。</p>

        <el-form-item prop="name" :label="$t('systemAdmin.field.name')">
          <el-input
            v-model="form.name"
            maxlength="120"
            :placeholder="$t('systemAdmin.field.name.placeholder')"
          />
        </el-form-item>

        <el-form-item prop="dept_id" :label="$t('systemAdmin.field.dept')">
          <DepartmentPicker
            v-model="form.dept_id"
            :key="departmentPickerRevision"
            :root-label="$t('systemAdmin.field.dept.unassigned')"
          />
        </el-form-item>

        <el-form-item prop="jobs_id" :label="$t('systemAdmin.field.jobs')">
          <el-select
            v-model="form.jobs_id"
            :disabled="!canReadJobs"
            multiple
            clearable
            :placeholder="$t('systemAdmin.field.jobs.placeholder')"
            ><el-option
              v-for="option in jobsOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
          /></el-select>
        </el-form-item>

        <el-form-item
          v-if="form.root !== 1"
          prop="role_id"
          :label="$t('systemAdmin.field.roles')"
        >
          <el-select
            v-model="form.role_id"
            :disabled="!canReadRoles || !canAssignRoles"
            multiple
            clearable
            :placeholder="$t('systemAdmin.field.roles.placeholder')"
            ><el-option
              v-for="option in roleOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
          /></el-select>
        </el-form-item>

        <el-form-item prop="password" :label="$t('systemAdmin.field.password')">
          <el-input
            type="password"
            show-password
            v-model="form.password"
            clearable
            :placeholder="
              isEdit
                ? $t('systemAdmin.field.password.editPlaceholder')
                : passwordHint
            "
          />
        </el-form-item>

        <el-form-item
          prop="password_confirm"
          :label="$t('systemAdmin.field.passwordConfirm')"
        >
          <el-input
            type="password"
            show-password
            v-model="form.password_confirm"
            clearable
            :placeholder="$t('systemAdmin.field.passwordConfirm.placeholder')"
          />
        </el-form-item>

        <el-form-item
          v-if="form.root !== 1"
          prop="disable"
          :label="$t('systemAdmin.field.status')"
        >
          <el-switch
            v-model="form.disable"
            :disabled="!canEditStatus"
            :active-value="0"
            :inactive-value="1"
          />
        </el-form-item>

        <el-form-item
          prop="multipoint_login"
          :label="$t('systemAdmin.field.multipoint')"
        >
          <div>
            <el-switch
              v-model="form.multipoint_login"
              :active-value="1"
              :inactive-value="0"
            />
            <div class="form-tip">
              {{ $t('systemAdmin.field.multipoint.tip') }}
            </div>
          </div>
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="modalVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="submitLoading"
          @click="handleSubmit"
          >保存</el-button
        ></template
      >
    </el-dialog>

    <el-dialog
      v-model="exportVisible"
      :title="$t('systemAdmin.export.title')"
      :close-on-click-modal="false"
      width="540px"
    >
      <div v-loading="exportInfoLoading" style="width: 100%">
        <el-form ref="exportFormRef" :model="exportForm" label-position="top">
          <el-alert type="info" style="margin-bottom: 16px">
            {{
              $t('systemAdmin.export.summary', {
                count: exportInfo.count,
                pages: exportInfo.sum_page,
                size: exportInfo.page_size,
              })
            }}
            <br />
            {{
              $t('systemAdmin.export.limit', {
                pages: exportInfo.max_page,
                count: exportInfo.all_max_size,
              })
            }}
          </el-alert>
          <el-form-item
            prop="page_type"
            :label="$t('systemAdmin.export.range')"
          >
            <el-radio-group v-model="exportForm.page_type">
              <el-radio :value="0" label="0">{{
                $t('systemAdmin.export.all')
              }}</el-radio>
              <el-radio :value="1" label="1">{{
                $t('systemAdmin.export.pages')
              }}</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item
            v-if="exportForm.page_type === 1"
            :label="$t('systemAdmin.export.pageRange')"
          >
            <el-space>
              <el-input-number
                v-model="exportForm.page_start"
                :min="1"
                :max="exportInfo.sum_page || 1"
              />
              <span>{{ $t('systemAdmin.export.to') }}</span>
              <el-input-number
                v-model="exportForm.page_end"
                :min="exportForm.page_start"
                :max="exportInfo.sum_page || 1"
              />
            </el-space>
          </el-form-item>
          <el-form-item
            prop="file_name"
            :label="$t('systemAdmin.export.fileName')"
          >
            <el-input v-model="exportForm.file_name" maxlength="100" />
          </el-form-item>
        </el-form>
      </div>
      <template #footer
        ><el-button @click="exportVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="exportLoading"
          @click="handleExport"
          >{{ $t('systemAdmin.export.confirm') }}</el-button
        ></template
      >
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, nextTick, reactive, ref } from 'vue';
  import { useRoute } from 'vue-router';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import { Download, Plus, Refresh, Search } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import { hasPermission } from '@/hooks/permission';
  import { useUserStore } from '@/store';
  import {
    getPasswordPolicy,
    passwordWithinPolicy,
    type PasswordPolicy,
  } from '@/api/password-policy';
  import { getRoleAll } from '@/api/system/role';
  import { getJobsAll } from '@/api/system/jobs';
  import {
    addAdmin,
    deleteAdmin,
    editAdmin,
    exportAdmins,
    getAdminDetail,
    getAdminExportInfo,
    getAdminList,
    updateAdminStatus,
    type AdminExportInfo,
    type AdminForm,
    type AdminListParams,
    type AdminRecord,
  } from '@/api/system/admin';
  import { DepartmentPicker } from '../dept/parent-options';

  const { t } = useI18n();
  const userStore = useUserStore();
  const route = useRoute();
  const canAssignRoles = computed(() =>
    hasPermission('core.member.role.assign', route.path)
  );
  const canChangeStatus = (disabled: number) =>
    hasPermission(
      disabled === 0 ? 'core.member.suspend' : 'core.member.activate',
      route.path
    );
  const canReadRoles = computed(() =>
    hasPermission('core.role.read', route.path)
  );
  const canReadJobs = computed(() =>
    hasPermission('core.job.read', route.path)
  );
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<AdminRecord[]>([]);
  const formatLoginTime = (value: string): string => {
    if (!value) return '-';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? '-'
      : date.toLocaleString('zh-CN', { hour12: false });
  };
  const roleOptions = ref<{ value: number; label: string }[]>([]);
  const jobsOptions = ref<{ value: number; label: string }[]>([]);
  const departmentPickerRevision = ref(0);

  const queryParams = reactive({
    account: '',
    name: '',
    role_id: '' as number | '',
  });

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
    showPageSize: true,
  });

  const listParams = (page = pagination.current): AdminListParams => ({
    account: queryParams.account || undefined,
    name: queryParams.name || undefined,
    role_id: queryParams.role_id === '' ? undefined : queryParams.role_id,
    page_no: page,
    page_size: pagination.pageSize,
  });

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const { data } = await getAdminList(listParams(page));
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };

  const loadOptions = async () => {
    if (canReadRoles.value) {
      const { data } = await getRoleAll();
      roleOptions.value = data.map((item) => ({
        value: item.id,
        label: item.name,
      }));
    }
    if (canReadJobs.value) {
      const { data } = await getJobsAll();
      jobsOptions.value = data.map((item) => ({
        value: item.id,
        label: item.name,
      }));
    }
  };

  const search = () => fetchData(1);
  const resetQuery = () => {
    queryParams.account = '';
    queryParams.name = '';
    queryParams.role_id = '';
    fetchData(1);
  };
  const onPageChange = (current: number) => fetchData(current);
  const onPageSizeChange = (pageSize: number) => {
    pagination.pageSize = pageSize;
    fetchData(1);
  };

  const isProtected = (record: AdminRecord) =>
    record.root === 1 || record.id === userStore.id;

  // ---- 新增 / 编辑 ----
  const modalVisible = ref(false);
  const passwordPolicy = ref<PasswordPolicy | null>(null);
  const passwordPolicyError = ref('');
  const passwordHint = computed(() =>
    passwordPolicy.value
      ? t('systemAdmin.field.password.length', {
          min: passwordPolicy.value.minimum_length,
          max: passwordPolicy.value.maximum_length,
        })
      : passwordPolicyError.value ||
        t('systemAdmin.field.password.policyLoading')
  );
  async function loadPasswordPolicy() {
    try {
      passwordPolicy.value = await getPasswordPolicy();
      passwordPolicyError.value = '';
    } catch {
      passwordPolicy.value = null;
      passwordPolicyError.value = t(
        'systemAdmin.field.password.policyUnavailable'
      );
    }
  }
  const isEdit = ref(false);
  const originalDisable = ref(1);
  const canEditStatus = computed(() =>
    isEdit.value
      ? canChangeStatus(originalDisable.value)
      : hasPermission('core.member.activate', route.path)
  );
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();

  type AdminEditorForm = Omit<AdminForm, 'dept_id'> & { dept_id: number };

  const defaultForm = (): AdminEditorForm => ({
    id: undefined,
    account: '',
    name: '',
    dept_id: 0,
    jobs_id: [],
    role_id: [],
    password: '',
    password_confirm: '',
    disable: hasPermission('core.member.activate', route.path) ? 0 : 1,
    multipoint_login: 1,
    root: 0,
  });
  const form = reactive<AdminEditorForm>(defaultForm());

  const rules = computed(() => ({
    account: [
      { required: true, message: t('systemAdmin.field.account.required') },
      { type: 'email', message: t('systemAdmin.field.account.email') },
      {
        min: 1,
        max: 255,
        message: t('systemAdmin.field.account.length'),
      },
    ],
    name: [
      { required: true, message: t('systemAdmin.field.name.required') },
      {
        min: 1,
        max: 120,
        message: t('systemAdmin.field.name.length'),
      },
    ],
    role_id: [
      {
        validator: (
          _rule: unknown,
          value: number[],
          callback: (message?: string | Error) => void
        ) => {
          if (form.root !== 1 && (!value || value.length === 0)) {
            callback(new Error(t('systemAdmin.field.roles.required')));
            return;
          }
          callback();
        },
      },
    ],
    password: isEdit.value
      ? [
          {
            validator: (
              _rule: unknown,
              value: string,
              callback: (message?: string | Error) => void
            ) => {
              if (
                !passwordPolicy.value ||
                (value && !passwordWithinPolicy(value, passwordPolicy.value))
              ) {
                callback(new Error(passwordHint.value));
                return;
              }
              callback();
            },
          },
        ]
      : [
          { required: true, message: t('systemAdmin.field.password.required') },
          {
            validator: (
              _rule: unknown,
              value: string,
              callback: (error?: Error) => void
            ) =>
              callback(
                passwordPolicy.value &&
                  passwordWithinPolicy(value, passwordPolicy.value)
                  ? undefined
                  : new Error(passwordHint.value)
              ),
          },
        ],
    password_confirm: [
      {
        validator: (
          _rule: unknown,
          value: string,
          callback: (message?: string | Error) => void
        ) => {
          if (form.password && !value) {
            callback(
              new Error(t('systemAdmin.field.passwordConfirm.required'))
            );
            return;
          }
          if ((value || '') !== (form.password || '')) {
            callback(
              new Error(t('systemAdmin.field.passwordConfirm.mismatch'))
            );
            return;
          }
          callback();
        },
      },
    ],
  }));

  const resetForm = (patch: Partial<AdminEditorForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
    nextTick(() => formRef.value?.clearValidate());
  };

  const handleAdd = () => {
    departmentPickerRevision.value += 1;
    loadPasswordPolicy();
    isEdit.value = false;
    resetForm();
    modalVisible.value = true;
  };

  const handleEdit = async (record: AdminRecord) => {
    await loadPasswordPolicy();
    isEdit.value = true;
    const { data } = await getAdminDetail(record.id);
    originalDisable.value = data.disable;
    departmentPickerRevision.value += 1;
    resetForm({
      id: data.id,
      account: data.account,
      name: data.name,
      dept_id: data.dept_id[0] ?? 0,
      jobs_id: data.jobs_id || [],
      role_id: data.role_id || [],
      password: '',
      password_confirm: '',
      disable: data.disable,
      multipoint_login: data.multipoint_login,
      root: data.root,
    });
    modalVisible.value = true;
  };

  const handleSubmit = async () => {
    if (!passwordPolicy.value) {
      await loadPasswordPolicy();
      if (!passwordPolicy.value) {
        ElMessage.error(passwordPolicyError.value);
        return false;
      }
    }
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return false;
    submitLoading.value = true;
    try {
      const payload: AdminForm = {
        ...form,
        dept_id: form.dept_id === 0 ? [] : [form.dept_id],
        jobs_id: [...form.jobs_id],
        role_id: [...form.role_id],
      };
      if (isEdit.value) await editAdmin(payload);
      else await addAdmin(payload);
      ElMessage.success(t('systemAdmin.tip.success'));
      modalVisible.value = false;
      await fetchData(isEdit.value ? pagination.current : 1);
      return true;
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: AdminRecord) => {
    await deleteAdmin(record.id);
    ElMessage.success(t('systemAdmin.tip.success'));
    const targetPage = renderData.value.length === 1 ? 1 : pagination.current;
    await fetchData(targetPage);
  };

  const handleStatus = async (record: AdminRecord, enabled: boolean) => {
    const disable = enabled ? 0 : 1;
    await updateAdminStatus(record.id, disable);
    record.disable = disable;
    record.disable_desc =
      disable === 1
        ? t('systemAdmin.status.disabled')
        : t('systemAdmin.status.normal');
    ElMessage.success(t('systemAdmin.tip.success'));
  };

  // ---- 两阶段导出 ----
  const emptyExportInfo = (): AdminExportInfo => ({
    count: 0,
    page_size: pagination.pageSize,
    sum_page: 0,
    max_page: 0,
    all_max_size: 0,
    page_start: 1,
    page_end: 1,
    file_name: '',
  });
  const exportVisible = ref(false);
  const exportInfoLoading = ref(false);
  const exportLoading = ref(false);
  const exportInfo = reactive<AdminExportInfo>(emptyExportInfo());
  const exportFormRef = ref<FormInstance>();
  const exportForm = reactive({
    page_type: 0 as 0 | 1,
    page_start: 1,
    page_end: 1,
    file_name: '',
  });

  const openExport = async () => {
    exportVisible.value = true;
    exportInfoLoading.value = true;
    try {
      const { data } = await getAdminExportInfo(listParams(1));
      Object.assign(exportInfo, data);
      exportForm.page_type = 0;
      exportForm.page_start = data.page_start;
      exportForm.page_end = data.page_end;
      exportForm.file_name = data.file_name;
    } finally {
      exportInfoLoading.value = false;
    }
  };

  const handleExport = async () => {
    if (exportInfoLoading.value) return false;
    if (
      exportForm.page_type === 1 &&
      exportForm.page_end < exportForm.page_start
    ) {
      ElMessage.error(t('systemAdmin.export.invalidRange'));
      return false;
    }
    exportLoading.value = true;
    try {
      const { data } = await exportAdmins({
        ...listParams(1),
        ...exportForm,
      });
      const link = document.createElement('a');
      link.href = data.url;
      link.download = data.file_name;
      link.rel = 'noopener';
      document.body.appendChild(link);
      link.click();
      link.remove();
      ElMessage.success(t('systemAdmin.export.success'));
      exportVisible.value = false;
      return true;
    } finally {
      exportLoading.value = false;
    }
  };

  Promise.all([fetchData(1), loadOptions()]);
</script>

<script lang="ts">
  export default {
    name: 'SystemAdmin',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .search-card {
    margin-bottom: 16px;
  }

  .form-tip {
    margin-top: 8px;
    color: var(--color-text-3);
    font-size: 12px;
  }
</style>
