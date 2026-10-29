<template>
  <div class="container">
    <Breadcrumb :items="['menu.finance', 'menu.finance.refund']" />

    <el-card shadow="never" style="margin-bottom: 16px">
      <el-row :gutter="24">
        <el-col :span="6">
          <el-statistic
            :title="$t('refund.stat.total')"
            :value="stat.total"
            :precision="2"
          />
        </el-col>
        <el-col :span="6">
          <el-statistic
            :title="$t('refund.stat.ing')"
            :value="stat.ing"
            :precision="2"
          />
        </el-col>
        <el-col :span="6">
          <el-statistic
            :title="$t('refund.stat.success')"
            :value="stat.success"
            :precision="2"
          />
        </el-col>
        <el-col :span="6">
          <el-statistic
            :title="$t('refund.stat.error')"
            :value="stat.error"
            :precision="2"
          />
        </el-col>
      </el-row>
    </el-card>

    <el-card shadow="never" style="margin-bottom: 16px">
      <el-form :model="formModel" inline>
        <el-form-item :label="$t('refund.filter.sn')">
          <el-input
            v-model="formModel.sn"
            :placeholder="$t('refund.filter.sn.placeholder')"
            clearable
            style="width: 200px"
            @keyup.enter="search"
          />
        </el-form-item>
        <el-form-item :label="$t('refund.filter.order_sn')">
          <el-input
            v-model="formModel.order_sn"
            :placeholder="$t('refund.filter.order_sn.placeholder')"
            clearable
            style="width: 200px"
            @keyup.enter="search"
          />
        </el-form-item>
        <el-form-item :label="$t('refund.filter.user_info')">
          <el-input
            v-model="formModel.user_info"
            :placeholder="$t('refund.filter.user_info.placeholder')"
            clearable
            style="width: 200px"
            @keyup.enter="search"
          />
        </el-form-item>
        <el-form-item :label="$t('refund.filter.refund_type')">
          <el-select
            v-model="formModel.refund_type"
            clearable
            style="width: 130px"
            :placeholder="$t('refund.filter.all')"
          >
            <el-option :label="$t('refund.filter.admin')" :value="1" />
          </el-select>
        </el-form-item>
        <el-form-item :label="$t('refund.filter.time')">
          <el-date-picker
            v-model="formModel.timeRange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            clearable
            style="width: 360px"
          />
        </el-form-item>
        <el-form-item>
          <el-space>
            <el-button type="primary" :icon="Search" @click="search">
              {{ $t('refund.filter.search') }}
            </el-button>
            <el-button :icon="Refresh" @click="reset">
              {{ $t('refund.filter.reset') }}
            </el-button>
          </el-space>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane
          v-for="tab in tabs"
          :key="tab.key"
          :name="tab.key"
          :label="`${$t(tab.label)}(${extend[tab.extendKey] ?? 0})`"
        />
      </el-tabs>

      <el-table v-loading="loading" :data="list" row-key="id" border>
        <el-table-column :label="$t('refund.col.sn')" prop="sn" width="200" />
        <el-table-column :label="$t('refund.col.user')" width="180">
          <template #default="{ row }">
            <el-space>
              <el-avatar :size="40" :src="row.avatar">
                {{ row.nickname?.slice(0, 1) }}
              </el-avatar>
              <span>{{ row.nickname || '-' }}</span>
            </el-space>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('refund.col.order_sn')"
          prop="order_sn"
          width="200"
        />
        <el-table-column :label="$t('refund.col.refund_amount')" width="120">
          <template #default="{ row }"> ¥ {{ row.refund_amount }} </template>
        </el-table-column>
        <el-table-column
          :label="$t('refund.col.refund_type')"
          prop="refund_type_text"
          width="110"
        />
        <el-table-column :label="$t('refund.col.refund_status')" width="110">
          <template #default="{ row }">
            <el-tag :type="statusType(row.refund_status)">
              {{ row.refund_status_text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('refund.col.create_time')"
          prop="create_time"
          width="180"
        />
        <el-table-column
          :label="$t('refund.col.action')"
          width="180"
          fixed="right"
        >
          <template #default="{ row }">
            <el-space>
              <el-button
                v-permission="['peanut.payment.refund.log']"
                link
                type="primary"
                size="small"
                @click="openLog(row.id)"
              >
                {{ $t('refund.action.log') }}
              </el-button>
              <el-popconfirm
                v-if="row.refund_status === 2"
                :title="$t('refund.retry.confirm')"
                @confirm="handleRetry(row.id)"
              >
                <template #reference>
                  <el-button
                    v-permission="['peanut.payment.refund.retry']"
                    link
                    type="primary"
                    size="small"
                    :loading="retryingId === row.id"
                    >{{ $t('refund.action.retry') }}</el-button
                  >
                </template>
              </el-popconfirm>
            </el-space>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrapper">
        <el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          :page-sizes="[15, 30, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @current-change="onPageChange"
          @size-change="onPageSizeChange"
        />
      </div>
    </el-card>

    <el-drawer
      v-model="logVisible"
      :title="$t('refund.log.title')"
      size="760px"
    >
      <el-table v-loading="logLoading" :data="logList" row-key="id" border>
        <el-table-column
          :label="$t('refund.log.col.sn')"
          prop="sn"
          width="200"
        />
        <el-table-column
          :label="$t('refund.log.col.refund_amount')"
          width="120"
        >
          <template #default="{ row }"> ¥ {{ row.refund_amount }} </template>
        </el-table-column>
        <el-table-column
          :label="$t('refund.log.col.refund_status')"
          width="110"
        >
          <template #default="{ row }">
            <el-tag :type="statusType(row.refund_status)">
              {{ row.refund_status_text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('refund.log.col.create_time')"
          prop="create_time"
          width="180"
        />
        <el-table-column
          :label="$t('refund.log.col.handler')"
          prop="handler"
          width="120"
        />
      </el-table>
    </el-drawer>
  </div>
</template>

<script lang="ts" setup>
  import { onMounted, reactive, ref } from 'vue';
  import { ElMessage, type TagProps } from 'element-plus';
  import { Refresh, Search } from '@element-plus/icons-vue';
  import { useI18n } from 'vue-i18n';
  import {
    getRefundLog,
    getRefundRecords,
    getRefundStat,
    refundAgain,
    type RefundListExtend,
    type RefundLogRecord,
    type RefundParams,
    type RefundRecord,
    type RefundStat,
  } from '@/modules/peanut-payment/api';

  const { t } = useI18n();

  const stat = reactive<RefundStat>({ total: 0, ing: 0, success: 0, error: 0 });
  const extend = reactive<RefundListExtend>({
    total: 0,
    ing: 0,
    success: 0,
    error: 0,
  });

  const tabs = [
    { key: '', label: 'refund.tab.all', extendKey: 'total' },
    { key: '0', label: 'refund.tab.ing', extendKey: 'ing' },
    { key: '1', label: 'refund.tab.success', extendKey: 'success' },
    { key: '2', label: 'refund.tab.error', extendKey: 'error' },
  ] as const;
  const activeTab = ref('');

  const generateFormModel = () => ({
    sn: '',
    order_sn: '',
    user_info: '',
    refund_type: '' as string | number,
    timeRange: [] as string[],
  });
  const formModel = ref(generateFormModel());

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
    showPageSize: true,
  });
  const list = ref<RefundRecord[]>([]);
  const loading = ref(false);

  const statusType = (status: number): TagProps['type'] =>
    (({ 0: 'warning', 1: 'success', 2: 'danger' } as const)[status] ?? 'info');

  const listParams = (pageNo: number): RefundParams => {
    const params: RefundParams = {
      sn: formModel.value.sn || undefined,
      order_sn: formModel.value.order_sn || undefined,
      user_info: formModel.value.user_info || undefined,
      refund_type:
        formModel.value.refund_type === ''
          ? undefined
          : formModel.value.refund_type,
      refund_status: activeTab.value === '' ? undefined : activeTab.value,
      page_no: pageNo,
      page_size: pagination.pageSize,
    };
    if (formModel.value.timeRange.length === 2) {
      [params.start_time, params.end_time] = formModel.value.timeRange;
    }
    return params;
  };

  const fetchStat = async () => {
    const { data } = await getRefundStat();
    Object.assign(stat, data);
  };

  const fetchList = async (pageNo = 1) => {
    loading.value = true;
    try {
      const { data } = await getRefundRecords(listParams(pageNo));
      list.value = data.lists;
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
      Object.assign(extend, data.extend);
    } finally {
      loading.value = false;
    }
  };

  const search = () => fetchList(1);
  const reset = () => {
    formModel.value = generateFormModel();
    fetchList(1);
  };
  const handleTabChange = (key: string | number) => {
    activeTab.value = String(key);
    fetchList(1);
  };
  const onPageChange = (current: number) => fetchList(current);
  const onPageSizeChange = (pageSize: number) => {
    pagination.pageSize = pageSize;
    fetchList(1);
  };

  const retryingId = ref(0);
  const handleRetry = async (recordId: number) => {
    retryingId.value = recordId;
    try {
      await refundAgain(recordId);
      ElMessage.success(t('refund.retry.success'));
      await Promise.all([fetchList(pagination.current), fetchStat()]);
    } finally {
      retryingId.value = 0;
    }
  };

  const logVisible = ref(false);
  const logLoading = ref(false);
  const logList = ref<RefundLogRecord[]>([]);
  const openLog = async (recordId: number) => {
    logVisible.value = true;
    logLoading.value = true;
    logList.value = [];
    try {
      const { data } = await getRefundLog(recordId);
      logList.value = data;
    } finally {
      logLoading.value = false;
    }
  };

  onMounted(() => {
    fetchStat();
    fetchList();
  });
</script>

<script lang="ts">
  export default { name: 'FinanceRefund' };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
