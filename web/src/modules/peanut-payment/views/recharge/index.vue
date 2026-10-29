<template>
  <div class="container">
    <Breadcrumb :items="['menu.finance', 'menu.finance.recharge']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.finance.recharge') }}</template>
      <el-alert type="warning" :closable="false" style="margin-bottom: 16px">
        {{ $t('recharge.alert') }}
      </el-alert>

      <el-form :model="formModel" label-position="top">
        <el-row :gutter="16">
          <el-col :span="6">
            <el-form-item prop="sn" :label="$t('recharge.form.sn')">
              <el-input
                v-model="formModel.sn"
                clearable
                :placeholder="$t('recharge.form.sn.placeholder')"
                @keyup.enter="search"
              />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item
              prop="user_info"
              :label="$t('recharge.form.userInfo')"
            >
              <el-input
                v-model="formModel.user_info"
                clearable
                :placeholder="$t('recharge.form.userInfo.placeholder')"
                @keyup.enter="search"
              />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item prop="pay_way" :label="$t('recharge.form.payWay')">
              <el-select
                v-model="formModel.pay_way"
                clearable
                :placeholder="$t('recharge.form.payWay.placeholder')"
                style="width: 100%"
              >
                <el-option
                  v-for="item in payWayOptions"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
                />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item
              prop="pay_status"
              :label="$t('recharge.form.payStatus')"
            >
              <el-select
                v-model="formModel.pay_status"
                clearable
                :placeholder="$t('recharge.form.payStatus.placeholder')"
                style="width: 100%"
              >
                <el-option
                  v-for="item in payStatusOptions"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
                />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item prop="timeRange" :label="$t('recharge.form.time')">
              <el-date-picker
                v-model="formModel.timeRange"
                type="datetimerange"
                value-format="YYYY-MM-DD HH:mm:ss"
                clearable
                style="width: 100%"
              />
            </el-form-item>
          </el-col>
          <el-col :span="12" class="filter-actions">
            <el-space>
              <el-button type="primary" :icon="Search" @click="search">{{
                $t('recharge.form.search')
              }}</el-button>
              <el-button :icon="Refresh" @click="reset">{{
                $t('recharge.form.reset')
              }}</el-button>
              <el-button :icon="Download" @click="openExport">{{
                $t('recharge.form.export')
              }}</el-button>
            </el-space>
          </el-col>
        </el-row>
      </el-form>

      <el-divider style="margin-top: 0" />
      <el-table v-loading="loading" row-key="id" :data="renderData" border>
        <el-table-column :label="$t('recharge.columns.user')" width="180">
          <template #default="{ row }"
            ><el-space
              ><el-avatar :size="40" :src="row.avatar">{{
                row.nickname?.slice(0, 1)
              }}</el-avatar
              ><span>{{ row.nickname || '-' }}</span></el-space
            ></template
          >
        </el-table-column>
        <el-table-column
          prop="sn"
          :label="$t('recharge.columns.sn')"
          width="210"
        />
        <el-table-column
          prop="order_amount"
          :label="$t('recharge.columns.orderAmount')"
          width="120"
        />
        <el-table-column
          prop="pay_way_text"
          :label="$t('recharge.columns.payWay')"
          width="110"
        />
        <el-table-column :label="$t('recharge.columns.payStatus')" width="110">
          <template #default="{ row }"
            ><span :class="{ 'pay-unpaid': row.pay_status === 0 }">{{
              row.pay_status_text
            }}</span></template
          >
        </el-table-column>
        <el-table-column
          prop="create_time"
          :label="$t('recharge.columns.createTime')"
          width="180"
        />
        <el-table-column
          prop="pay_time"
          :label="$t('recharge.columns.payTime')"
          width="180"
        />
        <el-table-column
          :label="$t('recharge.columns.operations')"
          width="120"
          fixed="right"
        >
          <template #default="{ row }">
            <el-button
              v-if="row.pay_status === 1"
              v-permission="['peanut.payment.recharge.refund']"
              link
              type="primary"
              size="small"
              :disabled="Number(row.refundable_amount) <= 0"
              :loading="refundingId === row.id"
              @click="handleRefund(row)"
              >{{ $t('recharge.action.refund') }}</el-button
            >
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrapper">
        <el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          :page-sizes="[25, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @current-change="onPageChange"
          @size-change="onPageSizeChange"
        />
      </div>
    </el-card>

    <el-dialog
      v-model="exportVisible"
      :title="$t('recharge.export.title')"
      :close-on-click-modal="false"
      width="540px"
    >
      <div v-loading="exportInfoLoading">
        <el-form :model="exportForm" label-position="top">
          <el-alert type="info" :closable="false" style="margin-bottom: 16px">
            {{
              $t('recharge.export.summary', {
                count: exportInfo.count,
                pages: exportInfo.sum_page,
                size: exportInfo.page_size,
              })
            }}
            <br />
            {{
              $t('recharge.export.limit', {
                pages: exportInfo.max_page,
                count: exportInfo.all_max_size,
              })
            }}
          </el-alert>
          <el-form-item prop="page_type" :label="$t('recharge.export.range')">
            <el-radio-group v-model="exportForm.page_type">
              <el-radio :value="0">{{ $t('recharge.export.all') }}</el-radio>
              <el-radio :value="1">{{ $t('recharge.export.pages') }}</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item
            v-if="exportForm.page_type === 1"
            :label="$t('recharge.export.pageRange')"
          >
            <el-space>
              <el-input-number
                v-model="exportForm.page_start"
                :min="1"
                :max="exportInfo.sum_page || 1"
              />
              <span>{{ $t('recharge.export.to') }}</span>
              <el-input-number
                v-model="exportForm.page_end"
                :min="exportForm.page_start"
                :max="exportInfo.sum_page || 1"
              />
            </el-space>
          </el-form-item>
          <el-form-item
            prop="file_name"
            :label="$t('recharge.export.fileName')"
          >
            <el-input v-model="exportForm.file_name" :maxlength="100" />
          </el-form-item>
        </el-form>
      </div>
      <template #footer>
        <el-button @click="exportVisible = false">取消</el-button>
        <el-button
          type="primary"
          :disabled="exportInfoLoading || exportInfo.count <= 0 || exportLoading"
          :loading="exportLoading"
          @click="handleExport"
          >{{ $t('recharge.export.confirm') }}</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { ElMessage, ElMessageBox } from 'element-plus';
  import { Download, Refresh, Search } from '@element-plus/icons-vue';
  import { useI18n } from 'vue-i18n';
  import useLoading from '@/hooks/loading';
  import {
    exportRecharge,
    getRechargeExportInfo,
    getRechargeList,
    refundRecharge,
    type RechargeExportInfo,
    type RechargeParams,
    type RechargeRecord,
  } from '@/modules/peanut-payment/api';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<RechargeRecord[]>([]);

  const generateFormModel = () => ({
    sn: '',
    user_info: '',
    pay_way: '' as string | number,
    pay_status: '' as string | number,
    timeRange: [] as string[],
  });
  const formModel = ref(generateFormModel());

  const pagination = reactive({
    current: 1,
    pageSize: 25,
    total: 0,
    showTotal: true,
    showPageSize: true,
  });

  const payWayOptions = computed(() => [
    { label: t('recharge.payWay.2'), value: 2 },
  ]);
  const payStatusOptions = computed(() => [
    { label: t('recharge.payStatus.0'), value: 0 },
    { label: t('recharge.payStatus.1'), value: 1 },
  ]);

  const listParams = (pageNo: number): RechargeParams => {
    const params: RechargeParams = {
      sn: formModel.value.sn || undefined,
      user_info: formModel.value.user_info || undefined,
      pay_way:
        formModel.value.pay_way === '' ? undefined : formModel.value.pay_way,
      pay_status:
        formModel.value.pay_status === ''
          ? undefined
          : formModel.value.pay_status,
      page_no: pageNo,
      page_size: pagination.pageSize,
    };
    if (formModel.value.timeRange.length === 2) {
      [params.start_time, params.end_time] = formModel.value.timeRange;
    }
    return params;
  };

  const fetchData = async (pageNo = 1) => {
    setLoading(true);
    try {
      const { data } = await getRechargeList(listParams(pageNo));
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };

  const search = () => fetchData(1);
  const onPageChange = (current: number) => fetchData(current);
  const onPageSizeChange = (pageSize: number) => {
    pagination.pageSize = pageSize;
    fetchData(1);
  };
  const reset = () => {
    formModel.value = generateFormModel();
    fetchData(1);
  };

  const refundingId = ref(0);
  const handleRefund = async (row: RechargeRecord) => {
    const remaining = Number(row.refundable_amount || 0);
    if (remaining <= 0) return;
    let amount: number;
    try {
      const result = await ElMessageBox.prompt(
        t('recharge.refund.amount.prompt', { amount: remaining.toFixed(2) }),
        t('recharge.refund.amount.title'),
        {
          inputValue: remaining.toFixed(2),
          inputPattern: /^\d+(\.\d{1,2})?$/,
          inputErrorMessage: t('recharge.refund.amount.invalid'),
          confirmButtonText: t('recharge.refund.amount.confirm'),
          cancelButtonText: t('recharge.refund.amount.cancel'),
        }
      );
      amount = Number(result.value);
    } catch (error) {
      if (error === 'cancel' || error === 'close') return;
      throw error;
    }
    if (!Number.isFinite(amount) || amount <= 0 || amount > remaining) {
      ElMessage.warning(t('recharge.refund.amount.range'));
      return;
    }

    refundingId.value = row.id;
    try {
      await refundRecharge(row.id, amount);
      ElMessage.success(t('recharge.refund.success'));
      await fetchData(pagination.current);
    } finally {
      refundingId.value = 0;
    }
  };

  const emptyExportInfo = (): RechargeExportInfo => ({
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
  const exportInfo = reactive<RechargeExportInfo>(emptyExportInfo());
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
      const { data } = await getRechargeExportInfo(listParams(1));
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
      ElMessage.error(t('recharge.export.invalidRange'));
      return false;
    }
    exportLoading.value = true;
    try {
      const { data } = await exportRecharge({
        ...listParams(1),
        ...exportForm,
      });
      const link = document.createElement('a');
      link.href = data.url;
      link.download = data.file_name || exportForm.file_name;
      link.rel = 'noopener';
      document.body.appendChild(link);
      link.click();
      link.remove();
      ElMessage.success(t('recharge.export.success'));
      exportVisible.value = false;
      return true;
    } finally {
      exportLoading.value = false;
    }
  };

  fetchData();
</script>

<script lang="ts">
  export default { name: 'FinanceRecharge' };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .pay-unpaid {
    color: var(--el-color-danger);
  }

  .filter-actions {
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
    padding-bottom: 18px;
  }

  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
