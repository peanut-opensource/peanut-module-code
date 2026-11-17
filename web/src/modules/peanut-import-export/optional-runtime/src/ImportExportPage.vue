<script setup lang="ts">
  import {
    ElAlert,
    ElButton,
    ElCard,
    ElCollapse,
    ElCollapseItem,
    ElForm,
    ElFormItem,
    ElSelect,
    ElOption,
    ElTable,
    ElTableColumn,
    ElProgress,
    ElTag,
    ElDropdown,
    ElDropdownMenu,
    ElDropdownItem,
    ElCheckbox,
    ElEmpty,
    ElInput,
    ElPagination,
  } from 'element-plus';
  import { PageContent, PageHeader, PageToolbar } from '@peanut-admin/ui-vue';
  import { useMediaQuery } from '@vueuse/core';
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
  const narrow = useMediaQuery('(max-width: 768px)');
  const visibleColumns = ref(['direction', 'progress', 'rows', 'expires']);
  const optionalColumns = ['direction', 'progress', 'rows', 'expires'];
  const toggleColumn = (column: string) => {
    visibleColumns.value = visibleColumns.value.includes(column)
      ? visibleColumns.value.filter((item) => item !== column)
      : [...visibleColumns.value, column];
  };
  const selectFile = (fileKey: string) => {
    inputFileKey.value = fileKey;
  };
  const busy = computed(() => state.loading || state.mutating);
  const errorMessage = computed(() => {
    const messages: Record<number, string> = {
      401: 'importExport.error401',
      403: 'importExport.error403',
      404: 'importExport.error404',
      409: 'importExport.error409',
      422: 'importExport.error422',
      503: 'importExport.error503',
    };
    return t(messages[state.error?.status ?? 0] ?? 'importExport.error');
  });
  const changePage = (page: number): Promise<void> => {
    state.page = page;
    return runtime.load();
  };
  const cancelOperation = (operationKey: string) => {
    const operation = state.items.find(
      (item) => item.operationKey === operationKey
    );
    if (operation) return runtime.cancel(operation);
    return Promise.resolve();
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
    let mapping: unknown;
    try {
      mapping = JSON.parse(mappingJson.value) as unknown;
      if (
        typeof mapping !== 'object' ||
        mapping === null ||
        Array.isArray(mapping) ||
        Object.keys(mapping).length === 0 ||
        Object.values(mapping).some((value) => typeof value !== 'string')
      )
        throw new Error();
    } catch {
      formError.value = t('importExport.mappingInvalid');
      return;
    }
    await runtime.submitImport(
      providerKey.value.trim(),
      inputFileKey.value.trim(),
      mapping as Record<string, string>
    );
  };
  onMounted(runtime.load);
</script>

<template>
  <PageContent class="pa-page-stack">
    <PageHeader>
      <div
        ><h1>{{ t('importExport.title') }}</h1
        ><p class="pa-page-header__description">{{
          t('importExport.description')
        }}</p></div
      >
    </PageHeader>
    <ElCard class="general-card" shadow="never">
      <slot v-if="canCreate" name="submission" :disabled="busy" />
      <ElCollapse v-if="canCreate">
        <ElCollapseItem name="create" title="高级任务提交">
          <section
            v-if="canCreate"
            class="submission"
            :aria-label="t('importExport.createDescription')"
          >
            <p class="hint"
              >{{
                t('importExport.createDescription')
              }}
              仅支持当前应用已登记并授权的
              provider；此处不代表所有业务均已开放导入。</p
            >
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
            <slot name="file-picker" :select="selectFile" :disabled="busy" />
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
                :disabled="busy || !providerKey.trim() || !inputFileKey.trim()"
                @click="submitImport"
                >{{ t('importExport.import') }}</ElButton
              >
              <ElButton
                :disabled="busy || !providerKey.trim()"
                @click="runtime.submitExport(providerKey.trim())"
                >{{ t('importExport.export') }}</ElButton
              >
            </div>
          </section>
        </ElCollapseItem>
      </ElCollapse>
      <PageToolbar :label="t('importExport.status')">
        <ElForm class="pa-page-filters" label-position="left"
          ><ElFormItem :label="t('importExport.status')">
            <ElSelect
              :model-value="state.status"
              :disabled="busy"
              @change="runtime.setStatus"
              ><ElOption
                v-for="status in statuses"
                :key="status"
                :label="t(`importExport.status.${status}`)"
                :value="status"
            /></ElSelect> </ElFormItem
        ></ElForm>
      </PageToolbar>
      <div class="pa-page-results-toolbar">
        <div class="pa-page-results-title"
          ><h2>{{ t('importExport.title') }}</h2
          ><span>{{ state.total }} 条</span></div
        >
        <div class="operations-actions">
          <ElButton
            :loading="state.loading"
            :disabled="busy"
            @click="runtime.load"
            >{{ t('importExport.refresh') }}</ElButton
          >
          <ElDropdown trigger="click" :hide-on-click="false"
            ><ElButton>显示列</ElButton
            ><template #dropdown
              ><ElDropdownMenu
                ><ElDropdownItem v-for="column in optionalColumns" :key="column"
                  ><ElCheckbox
                    :model-value="visibleColumns.includes(column)"
                    @change="toggleColumn(column)"
                    :label="column"
                    :value="column"
                    >{{ t(`importExport.${column}`) }}</ElCheckbox
                  ></ElDropdownItem
                ></ElDropdownMenu
              ></template
            ></ElDropdown
          >
        </div>
      </div>
      <ElAlert
        v-if="state.error"
        :title="errorMessage"
        type="warning"
        :closable="false"
        show-icon
      >
        <p>{{ state.error.message }}</p>
        <p v-if="state.error.requestId"
          >{{ t('importExport.requestId') }}: {{ state.error.requestId }}</p
        >
      </ElAlert>
      <ElTable
        class="pa-page-table"
        v-loading="state.loading"
        :data="state.error ? [] : state.items"
        row-key="operationKey"
        border
        max-height="max(240px, calc(100dvh - 380px))"
        scrollbar-tabindex="0"
        :aria-label="t('importExport.title')"
      >
        <ElTableColumn
          prop="operationKey"
          label="任务编号"
          min-width="210"
          :fixed="narrow ? false : 'left'"
          show-overflow-tooltip
        />
        <ElTableColumn
          prop="providerKey"
          :label="t('importExport.provider')"
          min-width="190"
          :fixed="narrow ? false : 'left'"
          show-overflow-tooltip
        />
        <ElTableColumn
          v-if="visibleColumns.includes('direction')"
          :label="t('importExport.direction')"
          width="100"
          ><template #default="{ row }">{{
            t(`importExport.direction.${row.direction}`)
          }}</template></ElTableColumn
        >
        <ElTableColumn :label="t('importExport.status')" width="170"
          ><template #default="{ row }"
            ><ElTag
              :type="
                row.status === 'failed'
                  ? 'danger'
                  : row.status === 'succeeded'
                  ? 'success'
                  : 'info'
              "
              >{{ t(`importExport.status.${row.status}`) }}</ElTag
            ><div v-if="row.lastErrorCode" class="operation-error">{{
              row.lastErrorCode
            }}</div></template
          ></ElTableColumn
        >
        <ElTableColumn
          v-if="visibleColumns.includes('progress')"
          :label="t('importExport.progress')"
          width="170"
          ><template #default="{ row }"
            ><ElProgress
              :percentage="
                Math.min(
                  100,
                  Math.round(
                    (row.processedRows /
                      Math.max(row.totalRows, row.processedRows, 1)) *
                      100
                  )
                )
              "
            /><small
              >{{ row.processedRows }} / {{ row.totalRows }}</small
            ></template
          ></ElTableColumn
        >
        <ElTableColumn
          v-if="visibleColumns.includes('rows')"
          :label="t('importExport.rows')"
          min-width="180"
          ><template #default="{ row }">{{
            t('importExport.rowsSummary', {
              accepted: row.acceptedRows,
              rejected: row.rejectedRows,
            })
          }}</template></ElTableColumn
        >
        <ElTableColumn
          v-if="visibleColumns.includes('expires')"
          prop="retentionUntil"
          :label="t('importExport.expires')"
          width="190"
        />
        <ElTableColumn
          :label="t('importExport.actions')"
          :width="narrow ? 160 : 230"
          fixed="right"
          ><template #default="{ row }"
            ><div class="operations-actions">
              <ElButton
                v-if="['queued', 'running'].includes(row.status) && canCancel"
                link
                type="warning"
                :disabled="busy"
                @click="cancelOperation(row.operationKey)"
                >{{ t('importExport.cancel') }}</ElButton
              >
              <ElButton
                v-if="row.resultFileKey"
                link
                type="primary"
                :disabled="busy"
                @click="runtime.download(row.resultFileKey)"
                >{{ t('importExport.result') }}</ElButton
              >
              <ElButton
                v-if="row.errorFileKey"
                link
                type="danger"
                :disabled="busy"
                @click="runtime.download(row.errorFileKey)"
                >{{ t('importExport.errors') }}</ElButton
              >
            </div></template
          ></ElTableColumn
        >
        <template #empty
          ><ElEmpty
            :description="state.error ? errorMessage : t('importExport.empty')"
        /></template>
      </ElTable>
      <div class="pa-page-pagination">
        <ElPagination
          :current-page="state.page"
          :page-size="state.pageSize"
          :total="state.total"
          :disabled="busy"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @size-change="(size: number) => { state.pageSize = size; changePage(1); }"
          @current-change="changePage"
        />
      </div>
    </ElCard>
  </PageContent>
</template>

<style scoped>
  .hint {
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
  .operations-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .operations-actions :deep(.el-button + .el-button) {
    margin-left: 0;
  }
  .operation-error {
    margin-top: 4px;
    color: var(--el-color-danger);
    font-size: 12px;
  }
</style>
