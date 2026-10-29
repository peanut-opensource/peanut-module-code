<script setup lang="ts">
  import {
    ElAlert,
    ElButton,
    ElCard,
    ElEmpty,
    ElInput,
    ElPagination,
  } from 'element-plus';
  import { computed, onMounted, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useImportExportRuntime } from './runtime';

  const { t } = useI18n();
  const runtime = useImportExportRuntime();
  const { state } = runtime;
  const providerKey = ref('');
  const inputFileKey = ref('');
  const mappingJson = ref('{}');
  const formError = ref('');
  const busy = computed(() => state.loading || state.mutating);
  const errorMessage = computed(() => {
    const messages: Record<number, string> = {
      401: 'importExport.error401',
      403: 'importExport.error403',
      503: 'importExport.error503',
    };
    return t(messages[state.error?.status ?? 0] ?? 'importExport.error');
  });
  const changePage = (page: number): Promise<void> => {
    state.page = page;
    return runtime.load();
  };
  const canCreate = computed(runtime.canCreate);
  const canCancel = computed(runtime.canCancel);
  const statuses = [
    'queued',
    'running',
    'cancel_requested',
    'succeeded',
    'failed',
    'cancelled',
    'expired',
  ] as const;
  const submitImport = async (): Promise<void> => {
    formError.value = '';
    try {
      const mapping = JSON.parse(mappingJson.value) as unknown;
      if (
        typeof mapping !== 'object' ||
        mapping === null ||
        Array.isArray(mapping)
      )
        throw new Error();
      await runtime.submitImport(
        providerKey.value,
        inputFileKey.value,
        mapping as Record<string, string>
      );
    } catch {
      formError.value = t('importExport.mappingInvalid');
    }
  };
  onMounted(runtime.load);
</script>

<template>
  <div class="container import-export-page">
    <ElCard class="general-card" shadow="never">
      <template #header>
        <div class="operations-header">
          <div
            ><h1>{{ t('importExport.title') }}</h1
            ><p>{{ t('importExport.description') }}</p></div
          >
          <ElButton
            :loading="state.loading"
            :disabled="busy"
            @click="runtime.load"
            >{{ t('importExport.refresh') }}</ElButton
          >
        </div>
      </template>
      <section
        v-if="canCreate"
        class="submission"
        :aria-label="t('importExport.createDescription')"
      >
        <p class="hint">{{ t('importExport.createDescription') }}</p>
        <label
          >{{ t('importExport.provider')
          }}<ElInput
            v-model="providerKey"
            :disabled="busy"
            :placeholder="t('importExport.providerPlaceholder')"
            v-bind="{ ariaLabel: t('importExport.provider') }"
        /></label>
        <label
          >{{ t('importExport.file')
          }}<ElInput
            v-model="inputFileKey"
            :disabled="busy"
            :placeholder="t('importExport.filePlaceholder')"
            v-bind="{ ariaLabel: t('importExport.file') }"
        /></label>
        <label
          >{{ t('importExport.mapping')
          }}<ElInput
            v-model="mappingJson"
            type="textarea"
            :disabled="busy"
            placeholder='{"CSV heading":"column_key"}'
            v-bind="{ ariaLabel: t('importExport.mapping') }"
        /></label>
        <ElAlert
          v-if="formError"
          :title="formError"
          type="warning"
          :closable="false"
        />
        <div class="operations-actions">
          <ElButton
            type="primary"
            :disabled="busy || !providerKey || !inputFileKey"
            @click="submitImport"
            >{{ t('importExport.import') }}</ElButton
          >
          <ElButton
            :disabled="busy || !providerKey"
            @click="runtime.submitExport(providerKey)"
            >{{ t('importExport.export') }}</ElButton
          >
        </div>
      </section>
      <div
        class="status-filters"
        role="group"
        :aria-label="t('importExport.status')"
      >
        <ElButton
          v-for="status in statuses"
          :key="status"
          :disabled="busy"
          :type="state.status === status ? 'primary' : 'default'"
          @click="runtime.setStatus(status)"
          >{{ t(`importExport.status.${status}`) }}</ElButton
        >
      </div>
      <ElAlert
        v-if="state.error"
        :title="errorMessage"
        type="warning"
        :closable="false"
        show-icon
      >
        <p v-if="state.error.requestId"
          >{{ t('importExport.requestId') }}: {{ state.error.requestId }}</p
        >
      </ElAlert>
      <div v-else-if="state.loading" class="loading-state" role="status">{{
        t('importExport.loading')
      }}</div>
      <ElEmpty
        v-else-if="state.items.length === 0"
        :description="t('importExport.empty')"
      />
      <div v-else class="table-wrap">
        <table>
          <thead
            ><tr
              ><th>{{ t('importExport.provider') }}</th
              ><th>{{ t('importExport.direction') }}</th
              ><th>{{ t('importExport.status') }}</th
              ><th>{{ t('importExport.progress') }}</th
              ><th>{{ t('importExport.rows') }}</th
              ><th>{{ t('importExport.expires') }}</th
              ><th>{{ t('importExport.actions') }}</th></tr
            ></thead
          >
          <tbody
            ><tr v-for="operation in state.items" :key="operation.operationKey">
              <td>{{ operation.providerKey }}</td
              ><td>{{ t(`importExport.direction.${operation.direction}`) }}</td
              ><td>{{ t(`importExport.status.${operation.status}`) }}</td>
              <td
                ><progress
                  :value="operation.processedRows"
                  :max="
                    Math.max(operation.totalRows, operation.processedRows, 1)
                  "
              /></td>
              <td>{{
                t('importExport.rowsSummary', {
                  accepted: operation.acceptedRows,
                  rejected: operation.rejectedRows,
                })
              }}</td
              ><td>{{ operation.retentionUntil }}</td>
              <td
                ><div class="operations-actions"
                  ><ElButton
                    v-if="['queued', 'running'].includes(operation.status)"
                    text
                    :disabled="!canCancel || busy"
                    @click="runtime.cancel(operation)"
                    >{{ t('importExport.cancel') }}</ElButton
                  ><ElButton
                    v-if="operation.resultFileKey"
                    text
                    :disabled="busy"
                    @click="runtime.download(operation.resultFileKey)"
                    >{{ t('importExport.result') }}</ElButton
                  ><ElButton
                    v-if="operation.errorFileKey"
                    text
                    :disabled="busy"
                    @click="runtime.download(operation.errorFileKey)"
                    >{{ t('importExport.errors') }}</ElButton
                  ></div
                ></td
              >
            </tr></tbody
          >
        </table>
      </div>
      <ElPagination
        v-if="state.total > 0"
        :current-page="state.page"
        :page-size="state.pageSize"
        :total="state.total"
        :disabled="busy"
        layout="prev, pager, next, total"
        @current-change="changePage"
      />
    </ElCard>
  </div>
</template>

<style scoped>
  .container {
    padding: 20px;
  }
  .operations-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
  }
  h1 {
    margin: 0;
    font-size: 20px;
    line-height: 1.4;
  }
  .operations-header p,
  .hint {
    margin: 8px 0 0;
    color: var(--el-text-color-secondary);
    line-height: 1.7;
  }
  .submission {
    display: grid;
    gap: 12px;
    max-width: 720px;
    margin-bottom: 24px;
  }
  .submission label {
    display: grid;
    gap: 6px;
  }
  .status-filters,
  .operations-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .status-filters {
    margin-bottom: 20px;
  }
  .status-filters :deep(.el-button + .el-button),
  .operations-actions :deep(.el-button + .el-button) {
    margin-left: 0;
  }
  .loading-state {
    padding: 32px 0;
    color: var(--el-text-color-secondary);
  }
  .table-wrap {
    overflow-x: auto;
    margin-bottom: 20px;
  }
  table {
    width: 100%;
    border-collapse: collapse;
  }
  th,
  td {
    padding: 12px;
    border-bottom: 1px solid var(--el-border-color);
    text-align: left;
  }
  th {
    color: var(--el-text-color-secondary);
    background: var(--el-fill-color-light);
  }
  progress {
    min-width: 120px;
  }
</style>
