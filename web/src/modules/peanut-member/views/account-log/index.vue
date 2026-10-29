<template>
  <div class="container">
    <Breadcrumb :items="['menu.finance', 'menu.finance.accountLog']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.finance.accountLog') }}</template>
      <el-alert type="warning" :closable="false" style="margin-bottom: 16px">
        {{ $t('accountLog.alert') }}
      </el-alert>
      <el-row :gutter="16">
        <el-col :span="21">
          <el-form :model="formModel" label-position="left" label-width="100px">
            <el-row :gutter="16">
              <el-col :span="8">
                <el-form-item
                  prop="user_info"
                  :label="$t('accountLog.form.userInfo')"
                >
                  <el-input
                    v-model="formModel.user_info"
                    clearable
                    :placeholder="$t('accountLog.form.userInfo.placeholder')"
                    @keyup.enter="search"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item
                  prop="change_type"
                  :label="$t('accountLog.form.changeType')"
                >
                  <el-select
                    v-model="formModel.change_type"
                    clearable
                    :placeholder="$t('accountLog.form.changeType.placeholder')"
                    style="width: 100%"
                  >
                    <el-option
                      v-for="item in changeTypeOptions"
                      :key="item.value"
                      :label="item.label"
                      :value="item.value"
                    />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item
                  prop="timeRange"
                  :label="$t('accountLog.form.time')"
                >
                  <el-date-picker
                    v-model="formModel.timeRange"
                    type="datetimerange"
                    value-format="YYYY-MM-DD HH:mm:ss"
                    style="width: 100%"
                    clearable
                  />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-col>
        <el-col :span="3" class="filter-actions">
          <el-space direction="vertical" :size="18">
            <el-button type="primary" :icon="Search" @click="search">
              {{ $t('accountLog.form.search') }}
            </el-button>
            <el-button :icon="Refresh" @click="reset">
              {{ $t('accountLog.form.reset') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>
      <el-divider style="margin-top: 0" />
      <el-table v-loading="loading" row-key="id" :data="renderData" border>
        <el-table-column
          prop="account"
          :label="$t('accountLog.columns.account')"
          width="140"
        />
        <el-table-column :label="$t('accountLog.columns.nickname')" width="180">
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
          prop="mobile"
          :label="$t('accountLog.columns.mobile')"
          width="140"
        />
        <el-table-column
          :label="$t('accountLog.columns.changeAmount')"
          width="120"
        >
          <template #default="{ row }">
            <span :class="{ 'amount-expense': row.action === 2 }">
              {{ row.change_amount }}
            </span>
          </template>
        </el-table-column>
        <el-table-column
          prop="left_amount"
          :label="$t('accountLog.columns.leftAmount')"
          width="120"
        />
        <el-table-column
          prop="change_type_desc"
          :label="$t('accountLog.columns.changeType')"
          width="180"
        />
        <el-table-column
          prop="source_sn"
          :label="$t('accountLog.columns.sourceSn')"
          width="180"
        />
        <el-table-column
          prop="create_time"
          :label="$t('accountLog.columns.createTime')"
          width="180"
        />
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
  </div>
</template>

<script lang="ts" setup>
  import { ref, reactive } from 'vue';
  import { Refresh, Search } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getAccountLogList,
    getUmChangeType,
    type AccountLogRecord,
    type AccountLogParams,
  } from '@/modules/peanut-member/api';

  const { loading, setLoading } = useLoading(true);
  const renderData = ref<AccountLogRecord[]>([]);

  const generateFormModel = () => ({
    user_info: '',
    change_type: '',
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

  const changeTypeOptions = ref<Array<{ label: string; value: string }>>([]);

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const params: AccountLogParams = {
        user_info: formModel.value.user_info || undefined,
        change_type: formModel.value.change_type || undefined,
        page_no: page,
        page_size: pagination.pageSize,
      };
      if (formModel.value.timeRange?.length === 2) {
        [params.start_time, params.end_time] = formModel.value.timeRange;
      }
      const { data } = await getAccountLogList(params);
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };

  const fetchChangeTypes = async () => {
    const { data } = await getUmChangeType();
    changeTypeOptions.value = Object.entries(data).map(([value, label]) => ({
      label,
      value,
    }));
  };

  fetchData();
  fetchChangeTypes();

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
</script>

<script lang="ts">
  export default {
    name: 'FinanceAccountLog',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .amount-expense {
    color: var(--el-color-danger);
  }

  .filter-actions {
    text-align: right;
  }

  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
