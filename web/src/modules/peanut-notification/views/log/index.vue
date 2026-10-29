<template>
  <div class="container">
    <!-- 搜索栏 -->
    <el-card shadow="never" style="margin-bottom: 16px">
      <div class="filter-grid">
        <el-input
          v-model="filterForm.receiver"
          :placeholder="$t('noticeLog.filter.receiver')"
          clearable
          @keyup.enter="handleSearch"
        />
        <el-select
          v-model="filterForm.scene_id"
          :placeholder="$t('noticeLog.filter.scene')"
          clearable
          style="width: 100%"
        >
          <el-option
            v-for="scene in sceneOptions"
            :key="scene.id"
            :value="String(scene.id)"
            :label="scene.name"
          />
        </el-select>
        <el-select
          v-model="filterForm.channel"
          :placeholder="$t('noticeLog.filter.channel')"
          clearable
          style="width: 100%"
        >
          <el-option :label="$t('noticeLog.channel.1')" value="1" />
          <el-option :label="$t('noticeLog.channel.2')" value="2" />
          <el-option :label="$t('noticeLog.channel.3')" value="3" />
        </el-select>
        <el-select
          v-model="filterForm.status"
          :placeholder="$t('noticeLog.filter.status')"
          clearable
          style="width: 100%"
        >
          <el-option :label="$t('noticeLog.status.0')" value="0" />
          <el-option :label="$t('noticeLog.status.1')" value="1" />
          <el-option :label="$t('noticeLog.status.2')" value="2" />
        </el-select>
        <el-date-picker
          v-model="filterForm.timeRange"
          type="datetimerange"
          value-format="YYYY-MM-DD HH:mm:ss"
          range-separator="-"
          style="width: 100%"
          :start-placeholder="$t('noticeLog.filter.time')"
          :end-placeholder="$t('noticeLog.filter.time')"
        />
        <el-space>
          <el-button type="primary" :icon="Search" @click="handleSearch">
            {{ $t('noticeLog.form.search') }}
          </el-button>
          <el-button @click="handleReset">
            {{ $t('noticeLog.form.reset') }}
          </el-button>
        </el-space>
      </div>
    </el-card>

    <!-- 表格 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" row-key="id" border>
        <el-table-column label="ID" prop="id" width="70" />
        <el-table-column
          :label="$t('noticeLog.columns.scene')"
          prop="scene_name"
        >
          <template #default="{ row }">
            {{ row.scene_name || row.template_name || '-' }}
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('noticeLog.columns.channel')"
          prop="channel"
          width="80"
        >
          <template #default="{ row }">
            <el-tag :type="channelType(row.channel)">
              {{ $t(`noticeLog.channel.${row.channel}`) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('noticeLog.columns.receiver')"
          prop="receiver"
        />
        <el-table-column
          :label="$t('noticeLog.columns.title')"
          prop="title"
          show-overflow-tooltip
        />
        <el-table-column
          :label="$t('noticeLog.columns.status')"
          prop="status"
          width="90"
        >
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)">
              {{ $t(`noticeLog.status.${row.status}`) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('noticeLog.columns.send_time')"
          prop="send_time"
          width="180"
        >
          <template #default="{ row }">
            {{ formatTime(row.send_time) }}
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('noticeLog.columns.operations')"
          align="center"
          width="80"
          fixed="right"
        >
          <template #default="{ row }">
            <el-button
              v-permission="['peanut.notification.log.detail']"
              link
              type="primary"
              size="small"
              @click="openDetail(row)"
            >
              {{ $t('form.detail') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrapper">
        <el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="onPageChange"
        />
      </div>
    </el-card>

    <!-- 详情弹窗 -->
    <el-dialog
      v-model="detailVisible"
      :title="$t('noticeLog.detail.title')"
      width="600px"
    >
      <el-descriptions border :column="1">
        <el-descriptions-item
          v-for="item in detailItems"
          :key="item.label"
          :label="item.label"
        >
          {{ item.value }}
        </el-descriptions-item>
      </el-descriptions>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref, computed, onMounted } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { Search } from '@element-plus/icons-vue';
  import type { TagProps } from 'element-plus';
  import {
    getNoticeLogList,
    getNoticeSceneList,
    type NoticeLogRecord,
    type NoticeSceneRecord,
  } from '@/modules/peanut-notification/api';

  const { t } = useI18n();

  // ─── 列表 ─────────────────────────────────────────────────────────────────────
  const loading = ref(false);
  const tableData = ref<NoticeLogRecord[]>([]);
  const sceneOptions = ref<NoticeSceneRecord[]>([]);
  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
  });

  const filterForm = reactive({
    receiver: '',
    channel: '',
    status: '',
    scene_id: '',
    timeRange: [] as string[],
  });

  const fetchData = async () => {
    loading.value = true;
    try {
      const params: Record<string, unknown> = {
        page_no: pagination.current,
        page_size: pagination.pageSize,
      };
      if (filterForm.receiver) params.receiver = filterForm.receiver;
      if (filterForm.channel) params.channel = filterForm.channel;
      if (filterForm.status) params.status = filterForm.status;
      if (filterForm.scene_id) params.scene_id = filterForm.scene_id;
      if (filterForm.timeRange?.length === 2) {
        params.start_time = Math.floor(
          new Date(filterForm.timeRange[0]).getTime() / 1000
        );
        params.end_time = Math.floor(
          new Date(filterForm.timeRange[1]).getTime() / 1000
        );
      }
      const { data } = await getNoticeLogList(
        params as Parameters<typeof getNoticeLogList>[0]
      );
      tableData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
    } finally {
      loading.value = false;
    }
  };

  const handleSearch = () => {
    pagination.current = 1;
    fetchData();
  };
  const handleReset = () => {
    filterForm.receiver = '';
    filterForm.channel = '';
    filterForm.status = '';
    filterForm.scene_id = '';
    filterForm.timeRange = [];
    handleSearch();
  };
  const onPageChange = (page: number) => {
    pagination.current = page;
    fetchData();
  };

  // ─── 详情 ─────────────────────────────────────────────────────────────────────
  const detailVisible = ref(false);
  const currentRecord = ref<NoticeLogRecord | null>(null);

  const openDetail = (record: NoticeLogRecord) => {
    currentRecord.value = record;
    detailVisible.value = true;
  };

  // ─── 工具 ─────────────────────────────────────────────────────────────────────
  const channelType = (channel: number): TagProps['type'] =>
    (({ 1: 'primary', 2: 'info', 3: 'warning' } as const)[channel] ?? 'info');
  const statusType = (status: number): TagProps['type'] =>
    (({ 0: 'info', 1: 'success', 2: 'danger' } as const)[status] ?? 'info');
  const formatTime = (ts: number) =>
    ts ? new Date(ts * 1000).toLocaleString() : '-';

  const detailItems = computed(() => {
    const r = currentRecord.value;
    if (!r) return [];
    return [
      { label: 'ID', value: String(r.id) },
      {
        label: t('noticeLog.columns.scene'),
        value: r.scene_name || r.template_name || '-',
      },
      {
        label: t('noticeLog.columns.channel'),
        value: t(`noticeLog.channel.${r.channel}`),
      },
      { label: t('noticeLog.columns.receiver'), value: r.receiver },
      { label: t('noticeLog.columns.title'), value: r.title || '-' },
      { label: t('noticeLog.detail.content'), value: r.content || '-' },
      {
        label: t('noticeLog.columns.status'),
        value: t(`noticeLog.status.${r.status}`),
      },
      { label: t('noticeLog.detail.error'), value: r.error || '-' },
      { label: t('noticeLog.detail.provider'), value: r.provider || '-' },
      {
        label: t('noticeLog.detail.verifyStatus'),
        value: r.scene_id ? t(`noticeLog.verify.${r.is_verified}`) : '-',
      },
      {
        label: t('noticeLog.detail.checkCount'),
        value: String(r.check_count || 0),
      },
      {
        label: t('noticeLog.columns.send_time'),
        value: formatTime(r.send_time),
      },
    ];
  });

  onMounted(async () => {
    const { data } = await getNoticeSceneList();
    sceneOptions.value = data.list;
    await fetchData();
  });
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .filter-grid {
    display: grid;
    grid-template-columns: 1.4fr repeat(3, 1fr) 1.6fr auto;
    gap: 12px;
    align-items: center;
  }

  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }

  @media (max-width: 1200px) {
    .filter-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
</style>
