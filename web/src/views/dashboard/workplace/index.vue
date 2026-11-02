<template>
  <div class="container">
    <div class="workplace-actions">
      <el-button :loading="loading" :disabled="loading" @click="fetchWorkbench">
        {{ $t('workplace.refresh') }}
      </el-button>
    </div>
    <div v-loading="loading" class="workplace-loading">
      <el-alert
        v-if="workbench.statistics_state === 'demo'"
        class="demo-data-alert"
        type="warning"
        :title="$t('workplace.demoData.notice')"
        :closable="false"
        show-icon
      />
      <el-row :gutter="16" class="workplace-grid">
        <el-col :xs="24" :lg="7">
          <el-card class="general-card full-height">
            <template #header>{{ $t('workplace.version.title') }}</template>
            <el-descriptions :column="1" border size="large">
              <el-descriptions-item :label="$t('workplace.version.platform')">
                {{ workbench.version.name || 'Peanut Module Code' }}
              </el-descriptions-item>
              <el-descriptions-item :label="$t('workplace.version.current')">
                {{ workbench.version.version }}
              </el-descriptions-item>
              <el-descriptions-item :label="$t('workplace.version.based')">
                {{ workbench.version.based }}
              </el-descriptions-item>
              <el-descriptions-item
                v-if="workbench.version.website"
                :label="$t('workplace.version.website')"
              >
                {{ workbench.version.website }}
              </el-descriptions-item>
              <el-descriptions-item :label="$t('workplace.version.channel')">
                <el-space v-if="hasChannelLinks">
                  <el-link
                    v-if="workbench.version.channel.website"
                    :href="workbench.version.channel.website"
                    target="_blank"
                    type="primary"
                  >
                    {{ $t('workplace.version.official') }}
                  </el-link>
                  <el-link
                    v-if="workbench.version.channel.github"
                    :href="workbench.version.channel.github"
                    target="_blank"
                    type="primary"
                  >
                    GitHub
                  </el-link>
                </el-space>
                <span v-else>--</span>
              </el-descriptions-item>
            </el-descriptions>
          </el-card>
        </el-col>

        <el-col :xs="24" :lg="17">
          <el-card class="general-card full-height">
            <template #header>
              <el-space>
                <span>{{ $t('workplace.today.title') }}</span>
                <span
                  v-if="workbench.statistics_state === 'demo'"
                  class="updated-at"
                >
                  {{ $t('workplace.today.updatedAt')
                  }}{{ workbench.today.time }}
                </span>
              </el-space>
            </template>
            <el-row
              v-if="workbench.statistics_state === 'demo'"
              :gutter="16"
              class="metrics-grid"
            >
              <el-col
                v-for="metric in metrics"
                :key="metric.key"
                :xs="12"
                :sm="6"
              >
                <el-statistic :title="$t(metric.label)" :value="metric.value">
                  <template #suffix>
                    <span class="metric-unit">{{ $t(metric.unit) }}</span>
                  </template>
                </el-statistic>
                <div class="metric-total">
                  {{ $t('workplace.today.total') }}{{ metric.total }}
                </div>
              </el-col>
            </el-row>
            <el-empty
              v-else
              class="workplace-empty"
              :description="$t('workplace.statistics.unavailable')"
            />
          </el-card>
        </el-col>

        <el-col :span="24">
          <el-card class="general-card">
            <template #header>{{ $t('workplace.shortcuts.title') }}</template>
            <el-row
              v-if="workbench.menu.length > 0"
              :gutter="12"
              class="shortcut-grid"
            >
              <el-col
                v-for="item in workbench.menu"
                :key="item.url"
                :xs="6"
                :sm="3"
              >
                <router-link :to="item.url" class="shortcut">
                  <el-avatar :size="48" shape="square" :src="item.image">
                    <el-icon><Grid /></el-icon>
                  </el-avatar>
                  <span>{{ shortcutLabel(item) }}</span>
                </router-link>
              </el-col>
            </el-row>
            <el-empty
              v-else
              class="workplace-empty"
              :description="$t('workplace.empty.shortcuts')"
            />
          </el-card>
        </el-col>

        <el-col :xs="24" :lg="16">
          <el-card class="general-card">
            <template #header>{{ $t('workplace.visitor.title') }}</template>
            <Chart
              v-if="hasVisitorData"
              autoresize
              style="height: 320px; width: 100%"
              :option="visitorOption"
            />
            <el-empty
              v-else
              class="workplace-empty workplace-empty--chart"
              :description="$t('workplace.empty.visitor')"
            />
          </el-card>
        </el-col>
        <el-col :xs="24" :lg="8">
          <el-card class="general-card">
            <template #header>{{ $t('workplace.sale.title') }}</template>
            <Chart
              v-if="hasSaleData"
              autoresize
              style="height: 320px; width: 100%"
              :option="saleOption"
            />
            <el-empty
              v-else
              class="workplace-empty workplace-empty--chart"
              :description="$t('workplace.empty.sale')"
            />
          </el-card>
        </el-col>

        <el-col :span="24">
          <el-card class="general-card">
            <template #header>{{ $t('workplace.support.title') }}</template>
            <el-row
              v-if="supportItems.length > 0"
              :gutter="16"
              class="support-grid"
            >
              <el-col
                v-for="item in supportItems"
                :key="item.title"
                :xs="24"
                :sm="12"
              >
                <component
                  :is="item.url ? 'a' : 'div'"
                  class="support-item"
                  :href="item.url || undefined"
                  :target="item.url ? '_blank' : undefined"
                  :rel="item.url ? 'noopener noreferrer' : undefined"
                >
                  <el-avatar :size="48" :src="item.image">
                    <el-icon><QuestionFilled /></el-icon>
                  </el-avatar>
                  <div class="support-content">
                    <div class="support-title">{{ item.title }}</div>
                    <div class="support-desc">{{ item.desc }}</div>
                    <div v-if="item.url" class="support-url">{{
                      item.url
                    }}</div>
                  </div>
                </component>
              </el-col>
            </el-row>
            <el-empty
              v-else
              class="workplace-empty"
              :description="$t('workplace.empty.support')"
            />
          </el-card>
        </el-col>
      </el-row>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { computed, onMounted, reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import { Grid, QuestionFilled } from '@element-plus/icons-vue';
  import { useI18n } from 'vue-i18n';
  import { Chart, type EChartsOption } from '@peanut-admin/peanut-chart/charts';
  import { getWorkbench, type WorkbenchData } from '@/api/workbench';

  const { t } = useI18n();
  const loading = ref(true);
  const workbench = reactive<WorkbenchData>({
    statistics_state: 'unavailable',
    version: {
      version: '',
      website: '',
      name: '',
      based: '',
      channel: { website: '', github: '' },
    },
    today: {
      time: '',
      today_sales: 0,
      total_sales: 0,
      today_visitor: 0,
      total_visitor: 0,
      today_new_user: 0,
      total_new_user: 0,
      order_num: 0,
      order_sum: 0,
    },
    menu: [],
    visitor: { date: [], list: [] },
    support: [],
    sale: { date: [], list: [] },
  });

  const hasChannelLinks = computed(
    () =>
      Boolean(workbench.version.channel.website) ||
      Boolean(workbench.version.channel.github)
  );

  const shortcutLocaleKeys: Record<string, [string, string]> = {
    '/app/members': ['管理员', 'workplace.shortcut.admin'],
    '/app/roles': ['角色管理', 'menu.system.role'],
    '/app/departments': ['部门管理', 'menu.system.dept'],
    '/system/dict': ['字典管理', 'menu.system.dict'],
    '/system/file': ['素材中心', 'workplace.shortcut.file'],
    '/system/menu': ['菜单权限', 'systemRole.auth.menuPermissions'],
    '/app-setting/website': ['网站信息', 'workplace.shortcut.website'],
  };
  const shortcutLabel = (item: WorkbenchData['menu'][number]) => {
    const locale = shortcutLocaleKeys[item.url];
    return locale && item.name === locale[0] ? t(locale[1]) : item.name;
  };

  const supportLocaleKeys: Record<string, [string, string, string]> = {
    项目文档: [
      '查看 Peanut Module Code 使用与开发文档',
      'workplace.support.docs.title',
      'workplace.support.docs.desc',
    ],
    技术支持: [
      '获取 Peanut Module Code 技术支持',
      'workplace.support.technical.title',
      'workplace.support.technical.desc',
    ],
  };

  const supportItems = computed(() =>
    workbench.support.map((item) => {
      const locale = supportLocaleKeys[item.title];
      return {
        ...item,
        title: locale && item.desc === locale[0] ? t(locale[1]) : item.title,
        desc: locale && item.desc === locale[0] ? t(locale[2]) : item.desc,
        url: (item as typeof item & { url?: string }).url || '',
      };
    })
  );

  const hasVisitorData = computed(
    () =>
      workbench.visitor.date.length > 0 &&
      workbench.visitor.list.some((series) => series.data.length > 0)
  );

  const hasSaleData = computed(
    () =>
      workbench.sale.date.length > 0 &&
      workbench.sale.list.some((series) => series.data.length > 0)
  );

  const metrics = computed(() => [
    {
      key: 'sales',
      label: 'workplace.today.sales',
      unit: 'workplace.unit.yuan',
      value: workbench.today.today_sales,
      total: workbench.today.total_sales,
    },
    {
      key: 'orders',
      label: 'workplace.today.orders',
      unit: 'workplace.unit.order',
      value: workbench.today.order_num,
      total: workbench.today.order_sum,
    },
    {
      key: 'users',
      label: 'workplace.today.users',
      unit: 'workplace.unit.person',
      value: workbench.today.today_new_user,
      total: workbench.today.total_new_user,
    },
    {
      key: 'visitors',
      label: 'workplace.today.visitors',
      unit: 'workplace.unit.visit',
      value: workbench.today.today_visitor,
      total: workbench.today.total_visitor,
    },
  ]);

  const visitorOption = computed<EChartsOption>(() => ({
    tooltip: { trigger: 'axis' },
    grid: { left: 48, right: 24, top: 32, bottom: 32 },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: [...workbench.visitor.date].reverse(),
    },
    yAxis: { type: 'value' },
    series: [
      {
        name: workbench.visitor.list[0]?.name || '访客数',
        type: 'line',
        smooth: true,
        showSymbol: false,
        areaStyle: { opacity: 0.12 },
        data: workbench.visitor.list[0]?.data || [],
      },
    ],
  }));

  const saleOption = computed<EChartsOption>(() => ({
    tooltip: { trigger: 'axis' },
    grid: { left: 48, right: 24, top: 32, bottom: 32 },
    xAxis: {
      type: 'category',
      data: [...workbench.sale.date].reverse(),
    },
    yAxis: { type: 'value' },
    series: [
      {
        name: workbench.sale.list[0]?.name || '销售量',
        type: 'bar',
        barMaxWidth: 32,
        itemStyle: { borderRadius: [6, 6, 0, 0] },
        data: workbench.sale.list[0]?.data || [],
      },
    ],
  }));

  const fetchWorkbench = async () => {
    loading.value = true;
    try {
      const { data } = await getWorkbench();
      Object.assign(workbench, data);
    } catch (error) {
      ElMessage.error(t('workplace.loadFailed'));
    } finally {
      loading.value = false;
    }
  };

  onMounted(fetchWorkbench);
</script>

<script lang="ts">
  export default {
    name: 'Workplace',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 20px;
  }

  .workplace-actions {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 16px;
  }

  .scaffold-upgrade-demo-card {
    margin-bottom: 16px;
  }

  .full-height {
    height: 100%;
  }

  .workplace-loading {
    min-height: 280px;
  }

  .demo-data-alert {
    margin-bottom: 16px;
  }

  .workplace-grid,
  .metrics-grid,
  .shortcut-grid,
  .support-grid {
    row-gap: 16px;
  }

  .metrics-grid,
  .shortcut-grid {
    row-gap: 20px;
  }

  .updated-at,
  .metric-total,
  .support-desc {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }

  .metric-unit {
    margin-left: 4px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .metric-total {
    margin-top: 8px;
  }

  .shortcut {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: center;
    color: var(--el-text-color-regular);
    text-decoration: none;
  }

  .shortcut:hover {
    color: var(--el-color-primary);
  }

  .support-item {
    display: flex;
    gap: 12px;
    align-items: center;
    padding: 16px;
    background: var(--el-fill-color-light);
    border-radius: var(--el-border-radius-base);
    color: inherit;
    text-decoration: none;
  }

  a.support-item:hover {
    color: var(--el-color-primary);
  }

  .support-content {
    min-width: 0;
  }

  .support-title {
    margin-bottom: 6px;
    color: var(--el-text-color-primary);
    font-weight: 500;
  }

  .support-url {
    margin-top: 6px;
    color: var(--el-color-primary);
    font-size: 12px;
    overflow-wrap: anywhere;
  }

  .workplace-empty {
    padding: 20px 0;
  }

  .workplace-empty--chart {
    height: 320px;
  }
</style>
