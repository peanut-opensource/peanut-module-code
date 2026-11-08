<template>
  <el-card v-loading="loading" class="backup-center-card">
    <template #header>外部服务生产资格</template>
    <el-alert
      title="这里只读取已记录证据；刷新不会连接外部平台、发送消息或发起资金动作。配置变化或证据过期会自动撤销资格。"
      type="info"
      :closable="false"
    />
    <p v-if="snapshot" class="table-scroll-hint"
      >表格可左右滚动；键盘可聚焦表格后使用方向键查看全部列。</p
    >
    <el-table
      v-if="snapshot"
      :data="snapshot.providers"
      empty-text="暂无 Provider"
      scrollbar-tabindex="0"
      aria-label="服务提供者资格表；可水平滚动查看全部列"
    >
      <el-table-column prop="provider_key" label="Provider" min-width="190" />
      <el-table-column prop="category" label="类别" width="110" />
      <el-table-column label="范围" min-width="150">
        <template #default="{ row }">
          {{ row.scope.type }} · {{ row.scope.key.slice(0, 18) }}…
        </template>
      </el-table-column>
      <el-table-column label="配置" width="80">
        <template #default="{ row }">{{
          row.configured ? '是' : '否'
        }}</template>
      </el-table-column>
      <el-table-column label="连通" width="80">
        <template #default="{ row }">{{
          row.connected ? '是' : '否'
        }}</template>
      </el-table-column>
      <el-table-column label="回调" width="80">
        <template #default="{ row }">{{
          row.callback_verified ? '是' : '否'
        }}</template>
      </el-table-column>
      <el-table-column label="生产资格" width="100">
        <template #default="{ row }">
          <el-tag :type="row.qualified ? 'success' : 'warning'">
            {{ row.qualified ? '有效' : '无效' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="status_code" label="资格说明" min-width="230">
        <template #default="{ row }">{{
          reasonLabel(row.status_code)
        }}</template>
      </el-table-column>
      <el-table-column label="凭据轮换" min-width="170">
        <template #default="{ row }">{{
          row.credential_rotated_at || '未记录'
        }}</template>
      </el-table-column>
      <el-table-column label="最近失败" min-width="220">
        <template #default="{ row }">
          {{
            row.recent_failure
              ? `${row.recent_failure.code} · ${row.recent_failure.observed_at}`
              : '—'
          }}
        </template>
      </el-table-column>
      <el-table-column label="证据有效期" min-width="170">
        <template #default="{ row }">{{
          row.expires_at || '无有效证据'
        }}</template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-if="snapshot"
      :current-page="snapshot.page"
      :page-size="snapshot.page_size"
      :total="snapshot.total"
      layout="prev, pager, next, total"
      @current-change="emit('pageChange', $event)"
    />
    <el-empty v-else description="Provider 资格投影尚未加载" />
  </el-card>
</template>

<script setup lang="ts">
  import type { ProviderQualificationSnapshot } from '../api/platform';

  const reasonLabel = (code: string): string =>
    ((
      {
        NOT_IMPLEMENTED: '尚未实现',
        CONFIGURATION_REQUIRED: '尚未配置',
        PROVIDER_RECENT_FAILURE: '近期存在失败证据',
        PROVIDER_EVIDENCE_STALE: '证据已过期',
        CONNECTIVITY_EVIDENCE_REQUIRED: '需要连通证据',
        CALLBACK_EVIDENCE_REQUIRED: '需要回调验证证据',
        PRODUCTION_QUALIFICATION_REQUIRED: '尚未完成生产资格验证',
        PROVIDER_PRODUCTION_QUALIFIED: '生产资格有效',
      } as Record<string, string>
    )[code] || code);

  const emit = defineEmits<{ (event: 'pageChange', page: number): void }>();
  defineProps<{
    loading: boolean;
    snapshot: ProviderQualificationSnapshot | null;
  }>();
</script>
