<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.log']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.log') }}</template>
      <el-row>
        <el-col :span="18">
          <el-form :model="formModel" label-position="left">
            <el-row :gutter="16">
              <el-col :span="8">
                <el-form-item
                  prop="username"
                  :label="$t('systemLog.form.username')"
                >
                  <el-input
                    v-model="formModel.username"
                    clearable
                    :placeholder="$t('systemLog.form.username.placeholder')"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item prop="uri" :label="$t('systemLog.form.uri')">
                  <el-input
                    v-model="formModel.uri"
                    clearable
                    :placeholder="$t('systemLog.form.uri.placeholder')"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item
                  prop="method"
                  :label="$t('systemLog.form.method')"
                >
                  <el-select
                    v-model="formModel.method"
                    clearable
                    :placeholder="$t('systemLog.form.method.placeholder')"
                  >
                    <el-option
                      v-for="option in methodOptions"
                      :key="option.value"
                      :label="option.label"
                      :value="option.value"
                    />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item prop="ip" :label="$t('systemLog.form.ip')">
                  <el-input
                    v-model="formModel.ip"
                    clearable
                    :placeholder="$t('systemLog.form.ip.placeholder')"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item
                  prop="timeRange"
                  :label="$t('systemLog.form.time')"
                >
                  <el-date-picker
                    v-model="formModel.timeRange"
                    type="datetimerange"
                    value-format="YYYY-MM-DD HH:mm:ss"
                    clearable
                    style="width: 100%"
                  />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-col>
        <el-divider style="height: 84px" direction="vertical" />
        <el-col :span="6" style="text-align: right">
          <el-space direction="vertical" :size="18">
            <el-button type="primary" :icon="Search" @click="search">
              {{ $t('systemLog.form.search') }}
            </el-button>
            <el-button :icon="Refresh" @click="reset">
              {{ $t('systemLog.form.reset') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>
      <el-divider style="margin-top: 0" />
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-space>
            <el-button
              v-permission="['peanut.import-export.operation-log.export']"
              plain
              :icon="Download"
              @click="openExport"
            >
              {{ $t('systemLog.operation.export') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>
      <el-table row-key="id" :loading="loading" :data="renderData" border>
        <el-table-column
          prop="id"
          :label="$t('systemLog.columns.id')"
          width="80"
        />
        <el-table-column
          prop="username"
          :label="$t('systemLog.columns.username')"
          width="120"
        />
        <el-table-column
          prop="ip"
          :label="$t('systemLog.columns.ip')"
          width="140"
        />
        <el-table-column prop="uri" :label="$t('systemLog.columns.uri')" />
        <el-table-column :label="$t('systemLog.columns.method')" width="90"
          ><template #default="{ row }"
            ><el-tag type="success">{{ row.method }}</el-tag></template
          ></el-table-column
        >
        <el-table-column
          :label="$t('systemLog.columns.params')"
          width="240"
          show-overflow-tooltip
          ><template #default="{ row }"
            ><el-text truncated>{{ row.params }}</el-text
            ><el-button link size="small" @click="copyParams(row.params)"
              >复制</el-button
            ></template
          ></el-table-column
        >
        <el-table-column :label="$t('systemLog.columns.createTime')" width="180"
          ><template #default="{ row }">{{
            formatTime(row.create_time)
          }}</template></el-table-column
        >
      </el-table>
      <el-pagination
        v-model:current-page="pagination.current"
        v-model:page-size="pagination.pageSize"
        :total="pagination.total"
        layout="total, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="onPageChange"
      />
    </el-card>

    <el-dialog
      v-model="exportVisible"
      :title="$t('systemLog.export.title')"
      :close-on-click-modal="false"
      width="540px"
    >
      <div v-loading="exportInfoLoading" style="width: 100%">
        <el-alert type="info" style="margin-bottom: 16px">
          {{
            $t('systemLog.export.summary', {
              count: exportInfo.count,
              pages: exportInfo.sum_page,
              size: exportInfo.page_size,
            })
          }}
          <br />
          {{
            $t('systemLog.export.limit', {
              pages: exportInfo.max_page,
              count: exportInfo.all_max_size,
            })
          }}
        </el-alert>
        <el-form :model="exportForm" label-position="top">
          <el-form-item prop="page_type" :label="$t('systemLog.export.range')">
            <el-radio-group v-model="exportForm.page_type">
              <el-radio :value="0" label="0">{{
                $t('systemLog.export.all')
              }}</el-radio>
              <el-radio :value="1" label="1">{{
                $t('systemLog.export.pages')
              }}</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item
            v-if="exportForm.page_type === 1"
            :label="$t('systemLog.export.pageRange')"
          >
            <el-space>
              <el-input-number
                v-model="exportForm.page_start"
                :min="1"
                :max="exportInfo.sum_page || 1"
              />
              <span>{{ $t('systemLog.export.to') }}</span>
              <el-input-number
                v-model="exportForm.page_end"
                :min="exportForm.page_start"
                :max="exportInfo.sum_page || 1"
              />
            </el-space>
          </el-form-item>
          <el-form-item
            prop="file_name"
            :label="$t('systemLog.export.fileName')"
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
          >{{ $t('systemLog.export.confirm') }}</el-button
        ></template
      >
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, ref, reactive } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import { Download, Refresh, Search } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getOperationLogList,
    getOperationLogExportInfo,
    exportOperationLog,
    type OperationLogRecord,
    type OperationLogParams,
    type OperationLogExportInfo,
  } from '@/api/system/log';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<OperationLogRecord[]>([]);
  let latestRequest = 0;

  const generateFormModel = () => ({
    username: '',
    uri: '',
    method: '',
    ip: '',
    timeRange: [] as string[],
  });
  const formModel = ref(generateFormModel());

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
  });

  const methodOptions = computed(() => [
    { label: 'POST', value: 'POST' },
    { label: 'GET', value: 'GET' },
    { label: 'PUT', value: 'PUT' },
    { label: 'DELETE', value: 'DELETE' },
  ]);

  const formatTime = (value?: number | string): string => {
    if (!value) return '-';
    if (typeof value === 'string') return value;
    return new Date(value * 1000).toLocaleString('zh-CN', { hour12: false });
  };
  const copyParams = async (value: string) => {
    await navigator.clipboard.writeText(value || '');
    ElMessage.success('已复制');
  };

  const fetchData = async (page = 1) => {
    latestRequest += 1;
    const requestId = latestRequest;
    setLoading(true);
    try {
      const params: OperationLogParams = {
        username: formModel.value.username || undefined,
        uri: formModel.value.uri || undefined,
        method: formModel.value.method || undefined,
        ip: formModel.value.ip || undefined,
        start_time: formModel.value.timeRange[0] || undefined,
        end_time: formModel.value.timeRange[1] || undefined,
        page_no: page,
        page_size: pagination.pageSize,
      };
      const { data } = await getOperationLogList(params);
      if (requestId !== latestRequest) return;
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
    } catch {
      // The shared API interceptor already reports request and session errors.
    } finally {
      if (requestId === latestRequest) setLoading(false);
    }
  };
  fetchData();

  const search = () => fetchData(1);
  const onPageChange = (current: number) => fetchData(current);
  const reset = () => {
    formModel.value = generateFormModel();
    fetchData(1);
  };

  // ---- 两阶段 XLSX 导出 ----
  const exportVisible = ref(false);
  const exportInfoLoading = ref(false);
  const exportLoading = ref(false);
  const emptyExportInfo = (): OperationLogExportInfo => ({
    count: 0,
    page_size: pagination.pageSize,
    sum_page: 0,
    max_page: 0,
    all_max_size: 0,
    page_start: 1,
    page_end: 1,
    file_name: '',
  });
  const exportInfo = reactive<OperationLogExportInfo>(emptyExportInfo());
  const exportForm = reactive({
    page_type: 0 as 0 | 1,
    page_start: 1,
    page_end: 1,
    file_name: '',
  });
  const listParams = (): OperationLogParams => ({
    username: formModel.value.username || undefined,
    uri: formModel.value.uri || undefined,
    method: formModel.value.method || undefined,
    ip: formModel.value.ip || undefined,
    start_time: formModel.value.timeRange[0] || undefined,
    end_time: formModel.value.timeRange[1] || undefined,
    page_no: 1,
    page_size: pagination.pageSize,
  });
  const openExport = async () => {
    exportVisible.value = true;
    exportInfoLoading.value = true;
    try {
      const { data } = await getOperationLogExportInfo(listParams());
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
      ElMessage.error(t('systemLog.export.invalidRange'));
      return false;
    }
    exportLoading.value = true;
    try {
      const { data } = await exportOperationLog({
        ...listParams(),
        ...exportForm,
      });
      const link = document.createElement('a');
      link.href = data.url;
      link.download = data.file_name;
      link.rel = 'noopener';
      document.body.appendChild(link);
      link.click();
      link.remove();
      ElMessage.success(t('systemLog.export.success'));
      exportVisible.value = false;
      return true;
    } finally {
      exportLoading.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemLog',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
