<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.maintenance']" />
    <div v-loading="loading" style="width: 100%">
      <el-row :gutter="16">
        <el-col :span="12">
          <el-card class="general-card">
            <template #header>{{
              $t('systemMaintenance.server.title')
            }}</template>
            <el-descriptions :column="1" bordered>
              <el-descriptions-item
                v-for="item in info.server"
                :key="item.param"
                :label="item.param"
              >
                {{ item.value }}
              </el-descriptions-item>
            </el-descriptions>
          </el-card>
        </el-col>
        <el-col :span="12">
          <el-card class="general-card">
            <template #header>{{ $t('systemMaintenance.env.title') }}</template>
            <el-table :data="info.env" border row-key="option">
              <el-table-column
                :label="$t('systemMaintenance.env.option')"
                prop="option"
              />
              <el-table-column
                :label="$t('systemMaintenance.env.require')"
                prop="require"
              />
              <el-table-column :label="$t('systemMaintenance.env.status')">
                <template #default="{ row: record }">
                  <el-tag :type="record.status ? 'success' : 'danger'">
                    {{
                      record.status
                        ? $t('systemMaintenance.status.ok')
                        : $t('systemMaintenance.status.fail')
                    }}
                  </el-tag>
                </template>
              </el-table-column>
            </el-table>
          </el-card>
          <el-card class="general-card" style="margin-top: 16px">
            <template #header>{{
              $t('systemMaintenance.auth.title')
            }}</template>
            <el-table :data="info.auth" border row-key="dir">
              <el-table-column
                :label="$t('systemMaintenance.auth.dir')"
                prop="dir"
              />
              <el-table-column
                :label="$t('systemMaintenance.auth.require')"
                prop="require"
              />
              <el-table-column :label="$t('systemMaintenance.env.status')">
                <template #default="{ row: record }">
                  <el-tag :type="record.status ? 'success' : 'danger'">
                    {{
                      record.status
                        ? $t('systemMaintenance.status.writable')
                        : $t('systemMaintenance.status.readonly')
                    }}
                  </el-tag>
                </template>
              </el-table-column>
            </el-table>
          </el-card>
          <el-card class="general-card" style="margin-top: 16px">
            <template #header>{{
              $t('systemMaintenance.cache.title')
            }}</template>
            <el-space direction="vertical" fill>
              <el-text type="info">
                {{ $t('systemMaintenance.cache.desc') }}
              </el-text>
              <el-popconfirm
                :title="$t('systemMaintenance.cache.confirm')"
                @confirm="handleClearCache"
              >
                <template #reference
                  ><el-button
                    v-permission="['system/clearcache']"
                    type="primary"
                    plain
                    :loading="clearing"
                  >
                    <template #icon><Delete /></template>
                    {{ $t('systemMaintenance.cache.clear') }}
                  </el-button></template
                >
              </el-popconfirm>
            </el-space>
          </el-card>
        </el-col>
      </el-row>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import { Delete } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getSystemInfo,
    clearSystemCache,
    type SystemInfo,
  } from '@/api/system/system';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const clearing = ref(false);

  const info = reactive<SystemInfo>({
    server: [],
    env: [],
    auth: [],
  });

  const fetchData = async () => {
    setLoading(true);
    try {
      const { data } = await getSystemInfo();
      info.server = data.server;
      info.env = data.env;
      info.auth = data.auth;
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const handleClearCache = async () => {
    clearing.value = true;
    try {
      await clearSystemCache();
      ElMessage.success(t('systemMaintenance.cache.success'));
    } finally {
      clearing.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemMaintenance',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
