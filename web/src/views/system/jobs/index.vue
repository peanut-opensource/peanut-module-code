<template>
  <PageContent>
    <PageHeader><h1>{{ $t('menu.system.jobs') }}</h1></PageHeader>
    <el-card class="general-card">
      <PageToolbar :label="$t('systemJobs.form.search')">
        <el-form
          class="pa-page-filters"
          :model="formModel"
          label-position="left"
          label-width="80px"
          @submit.prevent="search"
        >
          <el-form-item prop="name" :label="$t('systemJobs.form.name')">
            <el-input
              v-model="formModel.name"
              clearable
              :placeholder="$t('systemJobs.form.name.placeholder')"
              @keyup.enter="search"
            />
          </el-form-item>
          <el-form-item prop="code" :label="$t('systemJobs.form.code')">
            <el-input
              v-model="formModel.code"
              clearable
              :placeholder="$t('systemJobs.form.code.placeholder')"
              @keyup.enter="search"
            />
          </el-form-item>
          <el-form-item prop="status" :label="$t('systemJobs.form.status')">
            <el-select
              v-model="formModel.status"
              clearable
              :placeholder="$t('systemJobs.form.status.placeholder')"
            >
              <el-option
                v-for="option in statusOptions"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </el-select>
          </el-form-item>
          <el-form-item class="pa-page-filters__actions" label-width="0">
            <el-space>
              <el-button type="primary" @click="search">
                <template #icon><Search /></template>
                {{ $t('systemJobs.form.search') }}
              </el-button>
              <el-button @click="reset">
                <template #icon><Refresh /></template>
                {{ $t('systemJobs.form.reset') }}
              </el-button>
            </el-space>
          </el-form-item>
        </el-form>
      </PageToolbar>
      <el-divider />
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-space>
            <el-button
              v-permission="['core.job.create']"
              type="primary"
              @click="handleAdd"
            >
              <template #icon><Plus /></template>
              {{ $t('systemJobs.operation.create') }}
            </el-button>
            <el-button @click="openExport">
              <template #icon><Download /></template>
              {{ $t('systemJobs.operation.export') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>
      <el-table
        class="pa-page-table"
        row-key="id"
        v-loading="loading"
        :data="renderData"
        border
      >
        <el-table-column
          prop="id"
          :label="$t('systemJobs.columns.id')"
          width="80"
        />
        <el-table-column
          prop="name"
          :label="$t('systemJobs.columns.name')"
          min-width="180"
        />
        <el-table-column
          prop="code"
          :label="$t('systemJobs.columns.code')"
          width="160"
        />
        <el-table-column
          prop="sort"
          :label="$t('systemJobs.columns.sort')"
          width="80"
        />
        <el-table-column
          prop="remark"
          :label="$t('systemJobs.columns.remark')"
          min-width="220"
        />
        <el-table-column
          prop="create_time"
          :label="$t('systemJobs.columns.createTime')"
          width="180"
        />
        <el-table-column :label="$t('systemJobs.columns.status')" width="90"
          ><template #default="{ row }"
            ><el-switch
              v-permission="['core.job.update']"
              :model-value="row.status === 1"
              @change="(v: string | number | boolean) => handleStatus(row, v as boolean)" /></template
        ></el-table-column>
        <el-table-column
          :label="$t('systemJobs.columns.operations')"
          width="160"
          ><template #default="{ row }"
            ><el-space
              ><el-button
                v-permission="['core.job.update']"
                link
                size="small"
                @click="handleEdit(row)"
                >{{ $t('systemJobs.operation.edit') }}</el-button
              ><el-popconfirm
                :title="$t('systemJobs.delete.confirm')"
                @confirm="handleDelete(row)"
                ><template #reference
                  ><el-button
                    v-permission="['core.job.archive']"
                    link
                    type="danger"
                    size="small"
                    >{{ $t('systemJobs.operation.delete') }}</el-button
                  ></template
                ></el-popconfirm
              ></el-space
            ></template
          ></el-table-column
        >
      </el-table>
      <el-pagination
        :current-page="pagination.page"
        :page-size="pagination.pageSize"
        :total="pagination.total"
        layout="total, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="fetchList"
      />
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="
        isEdit
          ? $t('systemJobs.modal.editTitle')
          : $t('systemJobs.modal.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="name" :label="$t('systemJobs.field.name')">
          <el-input
            v-model="form.name"
            :placeholder="$t('systemJobs.field.name.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="code" :label="$t('systemJobs.field.code')">
          <el-input
            v-model="form.code"
            :placeholder="$t('systemJobs.field.code.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="sort" :label="$t('systemJobs.field.sort')">
          <el-input-number
            v-model="form.sort"
            :min="0"
            :max="9999"
            style="width: 160px"
          />
        </el-form-item>
        <el-form-item prop="remark" :label="$t('systemJobs.field.remark')">
          <el-input
            type="textarea"
            v-model="form.remark"
            :placeholder="$t('systemJobs.field.remark.placeholder')"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
        <el-form-item prop="status" :label="$t('systemJobs.field.status')">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
          />
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
      :title="$t('systemJobs.export.title')"
      :close-on-click-modal="false"
      width="540px"
    >
      <div v-loading="exportInfoLoading" style="width: 100%">
        <el-form :model="exportForm" label-position="top">
          <el-alert type="info" style="margin-bottom: 16px">
            {{
              $t('systemJobs.export.summary', {
                count: exportInfo.count,
                pages: exportInfo.sum_page,
                size: exportInfo.page_size,
              })
            }}
            <br />
            {{
              $t('systemJobs.export.limit', {
                pages: exportInfo.max_page,
                count: exportInfo.all_max_size,
              })
            }}
          </el-alert>
          <el-form-item prop="page_type" :label="$t('systemJobs.export.range')">
            <el-radio-group v-model="exportForm.page_type">
              <el-radio :value="0" label="0">{{
                $t('systemJobs.export.all')
              }}</el-radio>
              <el-radio :value="1" label="1">{{
                $t('systemJobs.export.pages')
              }}</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item
            v-if="exportForm.page_type === 1"
            :label="$t('systemJobs.export.pageRange')"
          >
            <el-space>
              <el-input-number
                v-model="exportForm.page_start"
                :min="1"
                :max="exportInfo.sum_page || 1"
              />
              <span>{{ $t('systemJobs.export.to') }}</span>
              <el-input-number
                v-model="exportForm.page_end"
                :min="exportForm.page_start"
                :max="exportInfo.sum_page || 1"
              />
            </el-space>
          </el-form-item>
          <el-form-item
            prop="file_name"
            :label="$t('systemJobs.export.fileName')"
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
          >{{ $t('systemJobs.export.confirm') }}</el-button
        ></template
      >
    </el-dialog>
  </PageContent>
</template>

<script lang="ts" setup>
  import { PageContent, PageHeader, PageToolbar } from '@peanut-admin/ui-vue';
  import { computed, ref, reactive } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useRoute } from 'vue-router';
  import { ElMessage } from 'element-plus';
  import { Download, Plus, Refresh, Search } from '@element-plus/icons-vue';
  import { hasPermission } from '@/hooks/permission';
  import useCrudModal from '@/hooks/useCrudModal';
  import useCrudTable from '@/hooks/useCrudTable';
  import {
    getJobsList,
    getJobsExportInfo,
    exportJobs,
    addJobs,
    editJobs,
    deleteJobs,
    updateJobsStatus,
    type JobsRecord,
    type JobsForm,
    type JobsListParams,
    type JobsExportInfo,
  } from '@/api/system/jobs';

  const { t } = useI18n();
  const route = useRoute();
  const canAccess = (permission: string) => hasPermission(permission, route.path);

  const initialFilters = {
    name: '',
    code: '',
    status: '' as number | '',
  };
  const {
    data: records,
    loading,
    pagination,
    searchForm: formModel,
    fetchList,
    handleSearch: search,
    handleReset: reset,
    handleDelete: remove,
  } = useCrudTable<JobsRecord, typeof initialFilters>({
    initialFilters,
    initialPageSize: 15,
    load: async ({ filters, page, pageSize, signal }) => {
      const params = { ...filters, page_no: page, page_size: pageSize };
      const { data } = await getJobsList(params, signal);
      return { items: reactive(data.lists), total: data.count, page: data.pageNo, pageSize };
    },
    deleteRecord: (record, signal) => deleteJobs(record.id, signal),
    errorMessage: (error) => error instanceof Error ? error.message : String(error),
  });
  const renderData = computed(() => [...records.value]);
  fetchList();

  const statusOptions = computed(() => [
    { label: t('systemJobs.status.normal'), value: 1 },
    { label: t('systemJobs.status.stopped'), value: 0 },
  ]);

  // ---- 弹窗 & 表单 ----
  const defaultForm = (): JobsForm => ({
    id: undefined,
    name: '',
    code: '',
    sort: 0,
    status: 1,
    remark: '',
  });
  const {
    create: handleAdd,
    edit: handleEdit,
    submit: handleSubmit,
    visible: modalVisible,
    form,
    formRef,
    mode,
    submitLoading,
  } = useCrudModal<JobsForm, JobsRecord>({
    canOpen: (intent) => {
      if (intent === 'view') return false;
      return canAccess(intent === 'create' ? 'core.job.create' : 'core.job.update');
    },
    createForm: defaultForm,
    editForm: (record) => ({ ...defaultForm(), ...record }),
    save: async (values, intent, signal) => {
      const { name, code, sort, status, remark, id } = values;
      const payload = { name, code, sort, status, remark };
      if (intent === 'edit') await editJobs({ ...payload, id }, signal);
      else await addJobs(payload, signal);
    },
    onSaved: async () => {
      ElMessage.success(t('systemJobs.tip.success'));
      await fetchList();
    },
  });
  const isEdit = computed(() => mode.value === 'edit');

  const rules = {
    name: [
      { required: true, message: t('systemJobs.field.name.required') },
      {
        min: 1,
        max: 50,
        message: t('systemJobs.field.name.length'),
      },
    ],
    code: [{ required: true, message: t('systemJobs.field.code.required') }],
  };

  const handleDelete = async (record: JobsRecord) => {
    if (canAccess('core.job.archive') && await remove(record)) {
      ElMessage.success(t('systemJobs.tip.success'));
    }
  };

  const handleStatus = async (record: JobsRecord, enabled: boolean) => {
    await updateJobsStatus(record.id, enabled ? 1 : 0);
    record.status = enabled ? 1 : 0;
    record.status_desc = enabled
      ? t('systemJobs.status.normal')
      : t('systemJobs.status.stopped');
    record.is_disable = enabled ? 0 : 1;
    ElMessage.success(t('systemJobs.tip.success'));
  };

  // ---- 两阶段导出 ----
  const listParams = (page = pagination.page): JobsListParams => ({
    name: formModel.value.name || undefined,
    code: formModel.value.code || undefined,
    status: formModel.value.status === '' ? undefined : formModel.value.status,
    page_no: page,
    page_size: pagination.pageSize,
  });
  const emptyExportInfo = (): JobsExportInfo => ({
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
  const exportInfo = reactive<JobsExportInfo>(emptyExportInfo());
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
      const { data } = await getJobsExportInfo(listParams(1));
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
      ElMessage.error(t('systemJobs.export.invalidRange'));
      return false;
    }
    exportLoading.value = true;
    try {
      const { data } = await exportJobs({
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
      ElMessage.success(t('systemJobs.export.success'));
      exportVisible.value = false;
      return true;
    } finally {
      exportLoading.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemJobs',
  };
</script>

<style scoped lang="less">
</style>
