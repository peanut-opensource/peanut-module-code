<script setup lang="ts">
  import { ElAlert, ElButton } from 'element-plus';
  import { computed, onBeforeUnmount, provide, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useRoute } from 'vue-router';
  import FilePicker from '@/components/file-picker/index.vue';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import {
    createImportExportFetchTransport,
    createImportExportRuntime,
    IMPORT_EXPORT_CANCEL_PERMISSION,
    IMPORT_EXPORT_CREATE_PERMISSION,
    IMPORT_EXPORT_READ_PERMISSION,
    importExportRuntimeKey,
  } from '@peanut-admin/peanut-import-export/runtime';
  import ImportExportPage from './optional-runtime/src/ImportExportPage.vue';

  const route = useRoute();
  const { t } = useI18n();
  const canImportDrafts = computed(
    () =>
      hasPermission('peanut.article.add', route.path) &&
      hasPermission('peanut.file.list', route.path) &&
      hasPermission('peanut.file.download-original', route.path)
  );
  const draftCsv = ref<{ key: string; name: string }>();
  const invalidDraftFile = ref(false);
  const baseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createImportExportRuntime({
    transport: createImportExportFetchTransport({
      baseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(IMPORT_EXPORT_READ_PERMISSION, route.path),
    canCreate: () => hasPermission(IMPORT_EXPORT_CREATE_PERMISSION, route.path),
    canCancel: () => hasPermission(IMPORT_EXPORT_CANCEL_PERMISSION, route.path),
    // File owns the original-delivery permission independently of this module's task read permission.
    canDownload: () => hasPermission('peanut.file.download-original', route.path),
  });

  provide(importExportRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);

  const downloadDraftTemplate = () => {
    const url = URL.createObjectURL(
      new Blob(['\ufefftitle,cid,body\r\n'], { type: 'text/csv;charset=utf-8' })
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = 'article-drafts-template.csv';
    link.click();
    URL.revokeObjectURL(url);
  };

  const importDrafts = async () => {
    const file = draftCsv.value;
    if (!file) return;
    await runtime.submitImport('peanut.article.drafts', file.key, {
      title: 'title',
      cid: 'cid',
      body: 'body',
    });
    // A server-confirmed enqueue is success even if the subsequent list GET
    // failed. Do not offer the same file as a misleading unsent draft.
    if (runtime.state.actionFeedback?.kind === 'accepted')
      draftCsv.value = undefined;
  };
</script>

<template>
  <ImportExportPage>
    <template #submission="{ disabled }">
      <section v-if="canImportDrafts" class="draft-import">
        <h2>{{ t('importExport.drafts.title') }}</h2>
        <p>{{ t('importExport.drafts.csvInstructions') }}</p>
        <ElButton @click="downloadDraftTemplate">{{
          t('importExport.drafts.downloadTemplate')
        }}</ElButton>
        <FilePicker
          v-if="!disabled"
          :type="30"
          :limit="1"
          :button-text="t('importExport.drafts.pickFile')"
          @select="
            (_urls, files) => {
              const file = files[0];
              invalidDraftFile =
                !file?.file_key ||
                file.access_type !== 'private' ||
                !/\.csv$/i.test(file.name);
              draftCsv =
                !invalidDraftFile && file?.file_key
                  ? { key: file.file_key, name: file.name }
                  : undefined;
            }
          "
        />
        <span v-if="draftCsv">{{ draftCsv.name }}</span>
        <ElButton
          type="primary"
          :disabled="disabled || !draftCsv"
          @click="importDrafts"
          >{{ t('importExport.drafts.submit') }}</ElButton
        >
        <ElAlert
          v-if="invalidDraftFile"
          :title="t('importExport.drafts.invalidFile')"
          type="warning"
          :closable="false"
        />
      </section>
    </template>
    <template #file-picker="{ select, disabled }">
      <FilePicker
        v-if="!disabled"
        :type="30"
        :limit="1"
        button-text="从素材库选择 CSV"
        @select="
          (_urls, files) => {
            if (files[0]?.file_key) select(files[0].file_key);
          }
        "
      />
    </template>
  </ImportExportPage>
</template>

<style scoped>
  .draft-import {
    margin-bottom: 16px;
  }

  .draft-import h2 {
    margin: 0;
    font-size: 16px;
  }

  .draft-import p {
    margin: 8px 0 12px;
  }

  .draft-import span {
    margin-inline: 12px;
  }
</style>
