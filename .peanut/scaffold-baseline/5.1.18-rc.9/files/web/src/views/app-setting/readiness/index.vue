<template>
  <div class="container">
    <Breadcrumb :items="['menu.appSetting', 'menu.appSetting.readiness']" />
    <el-alert
      class="boundary-alert"
      :title="$t('readiness.boundary.title')"
      :description="$t('readiness.boundary.description')"
      type="info"
      show-icon
      :closable="false"
    />

    <el-card v-loading="loading" class="general-card">
      <template #header>
        <div class="card-header">
          <div>
            <div class="card-title">{{ $t('readiness.title') }}</div>
            <div class="card-description">{{
              $t('readiness.description')
            }}</div>
          </div>
          <el-tag
            v-if="!error"
            :type="checklist.production_ready ? 'success' : 'danger'"
            size="large"
          >
            {{
              checklist.production_ready
                ? $t('readiness.summary.ready')
                : $t('readiness.summary.blocked', {
                    count: checklist.summary.production_blockers,
                  })
            }}
          </el-tag>
        </div>
      </template>

      <el-alert v-if="error" type="error" :closable="false" :title="error" />
      <el-table v-else :data="checklist.items" row-key="key" border>
        <el-table-column :label="$t('readiness.columns.item')" min-width="170">
          <template #default="{ row }">
            <div v-if="row.key" class="item-title">
              {{ $t(`readiness.items.${row.key}.title`) }}
            </div>
            <el-text size="small" type="info">
              {{ $t(`readiness.scope.${row.scope}`) }}
            </el-text>
          </template>
        </el-table-column>
        <el-table-column :label="$t('readiness.columns.status')" width="145">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)">
              {{ $t(`readiness.status.${row.status}`) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('readiness.columns.impact')"
          min-width="260"
        >
          <template #default="{ row }">
            {{ $t(row.impact_key) }}
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('readiness.columns.productionBlocking')"
          width="130"
          align="center"
        >
          <template #default="{ row }">
            <el-tag :type="row.production_blocking ? 'danger' : 'info'">
              {{
                row.production_blocking
                  ? $t('readiness.common.yes')
                  : $t('readiness.common.no')
              }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('readiness.columns.action')"
          min-width="280"
        >
          <template #default="{ row }">
            <div>{{ $t(row.action_key) }}</div>
            <el-button
              v-if="row.entry.kind === 'route' && row.entry.route"
              class="entry-button"
              type="primary"
              link
              :icon="ArrowRight"
              @click="openEntry(row.entry.route)"
            >
              {{ $t(`readiness.audience.${row.entry.audience}`) }}
            </el-button>
            <el-text v-else class="entry-owner" type="info" size="small">
              {{ $t('readiness.ownerPrefix') }}:
              {{ $t(`readiness.audience.${row.entry.audience}`) }}
            </el-text>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref } from 'vue';
  import { useRouter } from 'vue-router';
  import { useI18n } from 'vue-i18n';
  import { ArrowRight } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getReadinessChecklist,
    type ReadinessChecklist,
    type ReadinessStatus,
  } from '@/api/readiness';

  const router = useRouter();
  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const error = ref('');
  const checklist = reactive<ReadinessChecklist>({
    production_ready: false,
    summary: {
      configured: 0,
      observed: 0,
      action_required: 0,
      unverified: 0,
      not_implemented: 0,
      production_blockers: 0,
    },
    items: [],
  });

  const statusType = (status: ReadinessStatus) => {
    if (status === 'configured' || status === 'observed') return 'success';
    if (status === 'action_required') return 'warning';
    if (status === 'not_implemented') return 'danger';
    return 'info';
  };

  const fetchChecklist = async () => {
    setLoading(true);
    error.value = '';
    try {
      const { data } = await getReadinessChecklist();
      Object.assign(checklist, data);
    } catch {
      error.value = t('readiness.error.load');
    } finally {
      setLoading(false);
    }
  };

  const openEntry = (route: string) => router.push(route);

  fetchChecklist();
</script>

<script lang="ts">
  export default {
    name: 'AppSettingReadiness',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .boundary-alert {
    margin-bottom: 16px;
  }

  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }

  .card-title,
  .item-title {
    font-weight: 600;
  }

  .card-description {
    margin-top: 6px;
    color: var(--color-text-3);
    font-size: 13px;
  }

  .entry-button,
  .entry-owner {
    margin-top: 6px;
  }

  @media (max-width: 768px) {
    .card-header {
      align-items: flex-start;
      flex-direction: column;
    }
  }
</style>
