<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.crontab']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.crontab') }}</template>
      <el-row>
        <el-col :span="18">
          <el-form :model="formModel" label-position="left">
            <el-row :gutter="16">
              <el-col :span="8">
                <el-form-item
                  prop="name"
                  :label="$t('systemCrontab.form.name')"
                >
                  <el-input
                    v-model="formModel.name"
                    clearable
                    :placeholder="$t('systemCrontab.form.name.placeholder')"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item
                  prop="status"
                  :label="$t('systemCrontab.form.status')"
                >
                  <el-select
                    v-model="formModel.status"
                    clearable
                    :placeholder="$t('systemCrontab.form.status.placeholder')"
                  >
                    <el-option
                      v-for="option in statusOptions"
                      :key="option.value"
                      :label="option.label"
                      :value="option.value"
                    />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-col>
        <el-divider style="height: 56px" direction="vertical" />
        <el-col :span="6" style="text-align: right">
          <el-space direction="vertical" :size="18">
            <el-button type="primary" @click="search">
              <template #icon><Search /></template>
              {{ $t('systemCrontab.form.search') }}
            </el-button>
            <el-button @click="reset">
              <template #icon><Refresh /></template>
              {{ $t('systemCrontab.form.reset') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>
      <el-divider style="margin-top: 0" />
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-button
            v-permission="['peanut.task.add']"
            type="primary"
            @click="handleAdd"
          >
            <template #icon><Plus /></template>
            {{ $t('systemCrontab.operation.create') }}
          </el-button>
        </el-col>
      </el-row>
      <el-table row-key="id" :loading="loading" :data="renderData" border>
        <el-table-column
          prop="id"
          :label="$t('systemCrontab.columns.id')"
          width="70"
        />
        <el-table-column
          prop="name"
          :label="$t('systemCrontab.columns.name')"
        />
        <el-table-column
          prop="command"
          :label="$t('systemCrontab.columns.command')"
        />
        <el-table-column
          prop="expression"
          :label="$t('systemCrontab.columns.expression')"
        />
        <el-table-column :label="$t('systemCrontab.columns.status')" width="90"
          ><template #default="{ row }"
            ><el-tag :type="statusColor(row.status)">{{
              row.status_desc
            }}</el-tag></template
          ></el-table-column
        >
        <el-table-column prop="last_time" width="170">
          <template #header>
            <el-tooltip
              :content="$t('systemCrontab.columns.lastTimeHelp')"
              placement="top"
            >
              <span>{{ $t('systemCrontab.columns.lastTime') }}</span>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column
          prop="time"
          :label="$t('systemCrontab.columns.time')"
          width="90"
        />
        <el-table-column
          prop="max_time"
          :label="$t('systemCrontab.columns.maxTime')"
          width="90"
        />
        <el-table-column
          prop="error"
          :label="$t('systemCrontab.columns.error')"
          width="180"
        />
        <el-table-column
          :label="$t('systemCrontab.columns.operations')"
          width="220"
          ><template #default="{ row }"
            ><el-space
              ><el-button
                v-if="row.status !== 1"
                v-permission="['peanut.task.operate']"
                link
                size="small"
                @click="handleOperate(row, 'start')"
                >{{ $t('systemCrontab.operation.start') }}</el-button
              ><el-button
                v-else
                v-permission="['peanut.task.operate']"
                link
                type="warning"
                size="small"
                @click="handleOperate(row, 'stop')"
                >{{ $t('systemCrontab.operation.stop') }}</el-button
              ><el-button
                v-permission="['peanut.task.edit']"
                link
                size="small"
                @click="handleEdit(row)"
                >{{ $t('systemCrontab.operation.edit') }}</el-button
              ><el-popconfirm
                :title="$t('systemCrontab.delete.confirm')"
                @confirm="handleDelete(row)"
                ><template #reference
                  ><el-button
                    v-permission="['peanut.task.delete']"
                    link
                    type="danger"
                    size="small"
                    >{{ $t('systemCrontab.operation.delete') }}</el-button
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
        layout="total, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="onPageChange"
      />
    </el-card>
    <el-dialog
      v-model="modalVisible"
      :title="
        isEdit
          ? $t('systemCrontab.modal.editTitle')
          : $t('systemCrontab.modal.addTitle')
      "
      :close-on-click-modal="false"
      width="640px"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="name" :label="$t('systemCrontab.field.name')">
          <el-input
            v-model="form.name"
            :placeholder="$t('systemCrontab.field.name.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="command" :label="$t('systemCrontab.field.command')">
          <el-input
            v-model="form.command"
            :placeholder="$t('systemCrontab.field.command.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="params" :label="$t('systemCrontab.field.params')">
          <el-input
            v-model="form.params"
            :placeholder="$t('systemCrontab.field.params.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="sort" :label="$t('systemCrontab.field.sort')">
          <el-input-number v-model="form.sort" :min="0" style="width: 160px" />
        </el-form-item>
        <el-form-item
          prop="expression"
          :label="$t('systemCrontab.field.expression')"
        >
          <el-input
            v-model="form.expression"
            :placeholder="$t('systemCrontab.field.expression.placeholder')"
            @update:model-value="clearPreview"
          >
            <template #append
              ><el-button
                v-permission="['peanut.task.expression']"
                :disabled="previewLoading"
                :loading="previewLoading"
                @click="previewExpression"
                >{{ $t('systemCrontab.field.preview') }}</el-button
              ></template
            >
          </el-input>
        </el-form-item>
        <el-form-item v-if="previewError">
          <span class="preview-error" role="alert">{{ previewError }}</span>
        </el-form-item>
        <el-form-item
          v-if="previewList.length"
          :label="$t('systemCrontab.field.nextRuns')"
        >
          <ul class="preview-list">
            <li v-for="p in previewList" :key="p.time">{{ p.date }}</li>
          </ul>
        </el-form-item>
        <el-form-item prop="status" :label="$t('systemCrontab.field.status')">
          <el-radio-group v-model="form.status">
            <el-radio :value="1" label="1">{{
              $t('systemCrontab.status.start')
            }}</el-radio>
            <el-radio :value="2" label="2">{{
              $t('systemCrontab.status.stop')
            }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item prop="remark" :label="$t('systemCrontab.field.remark')">
          <el-input
            v-model="form.remark"
            type="textarea"
            :placeholder="$t('systemCrontab.field.remark.placeholder')"
            maxlength="255"
            show-word-limit
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
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance, TagProps } from 'element-plus';
  import { Plus, Refresh, Search } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getCrontabList,
    getCrontabExpression,
    addCrontab,
    editCrontab,
    deleteCrontab,
    operateCrontab,
    type CrontabRecord,
    type CrontabForm,
    type CrontabListParams,
    type CrontabStatus,
    type ExpressionItem,
  } from '@/modules/peanut-task/api';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<CrontabRecord[]>([]);

  const generateFormModel = () => ({
    name: '',
    status: '' as CrontabStatus | '',
  });
  const formModel = ref(generateFormModel());

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
  });

  const statusOptions = computed(() => [
    { label: t('systemCrontab.status.start'), value: 1 },
    { label: t('systemCrontab.status.stop'), value: 2 },
    { label: t('systemCrontab.status.error'), value: 3 },
  ]);

  const statusColors: Record<CrontabStatus, TagProps['type']> = {
    1: 'success',
    2: 'info',
    3: 'danger',
  };
  const statusColor = (status: CrontabStatus): TagProps['type'] =>
    statusColors[status] || 'info';

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const params: CrontabListParams = {
        ...formModel.value,
        page_no: page,
        page_size: pagination.pageSize,
      };
      const { data } = await getCrontabList(params);
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const search = () => fetchData(1);
  const onPageChange = (current: number) => fetchData(current);
  const reset = () => {
    formModel.value = generateFormModel();
    fetchData(1);
  };
  // ---- 弹窗 & 表单 ----
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();
  const previewList = ref<ExpressionItem[]>([]);
  const previewError = ref('');
  const previewLoading = ref(false);

  const defaultForm = (): CrontabForm => ({
    id: undefined,
    name: '',
    type: 1,
    command: '',
    params: '',
    sort: 0,
    status: 1,
    expression: '',
    remark: '',
  });
  const form = reactive<CrontabForm>(defaultForm());

  const clearPreview = () => {
    previewList.value = [];
    previewError.value = '';
  };

  const rules = {
    name: [{ required: true, message: t('systemCrontab.field.name.required') }],
    command: [
      { required: true, message: t('systemCrontab.field.command.required') },
    ],
    expression: [
      { required: true, message: t('systemCrontab.field.expression.required') },
    ],
  };

  const resetForm = (patch: Partial<CrontabForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
    clearPreview();
  };

  const handleAdd = () => {
    isEdit.value = false;
    resetForm();
    modalVisible.value = true;
  };

  const handleEdit = (record: CrontabRecord) => {
    isEdit.value = true;
    resetForm({
      id: record.id,
      name: record.name,
      type: record.type,
      command: record.command,
      params: record.params,
      sort: record.sort,
      status: record.status === 3 ? 2 : record.status,
      expression: record.expression,
      remark: record.remark,
    });
    modalVisible.value = true;
  };

  const previewExpression = async () => {
    if (previewLoading.value) return;
    clearPreview();
    const { expression } = form;
    if (!expression?.trim()) {
      previewError.value = t('systemCrontab.field.expression.required');
      return;
    }
    previewLoading.value = true;
    try {
      const { data } = await getCrontabExpression(expression);
      if (form.expression !== expression || !modalVisible.value) return;
      if (Array.isArray(data) && data.length) {
        previewList.value = data;
      } else {
        previewError.value = t('systemCrontab.tip.badExpression');
      }
    } catch {
      // The shared interceptor already shows the server's business message.
      if (form.expression === expression && modalVisible.value)
        previewError.value = t('systemCrontab.tip.previewFailed');
    } finally {
      previewLoading.value = false;
    }
  };

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    submitLoading.value = true;
    try {
      if (isEdit.value) {
        await editCrontab(form);
      } else {
        await addCrontab(form);
      }
      ElMessage.success(t('systemCrontab.tip.success'));
      modalVisible.value = false;
      await fetchData(pagination.current);
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: CrontabRecord) => {
    await deleteCrontab(record.id);
    ElMessage.success(t('systemCrontab.tip.success'));
    await fetchData(pagination.current);
  };

  const handleOperate = async (
    record: CrontabRecord,
    operate: 'start' | 'stop'
  ) => {
    await operateCrontab(record.id, operate);
    ElMessage.success(t('systemCrontab.tip.success'));
    await fetchData(pagination.current);
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemCrontab',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .preview-error {
    color: var(--el-color-danger);
  }

  .preview-list {
    margin: 0;
    padding: 8px 12px;
    list-style: none;
    width: 100%;
    background: var(--color-fill-2);
    border-radius: 4px;

    li {
      line-height: 22px;
      font-size: 13px;
      color: var(--color-text-2);
    }
  }
</style>
