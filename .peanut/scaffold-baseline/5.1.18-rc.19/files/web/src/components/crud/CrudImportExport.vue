<template>
  <div class="crud-transfer">
    <el-button v-if="canImport" @click="openImport">导入</el-button>
    <el-select
      v-if="canExport"
      v-model="format"
      aria-label="导出格式"
      style="width: 110px"
    >
      <el-option label="XLSX" value="xlsx" /><el-option
        label="CSV"
        value="csv"
      />
    </el-select>
    <el-alert
      v-if="error && !visible"
      :title="error"
      type="error"
      :closable="false"
    />
    <el-button v-if="canExport" :loading="exporting" @click="exportData"
      >导出</el-button
    >
    <el-dialog v-model="visible" title="导入数据" @closed="cancel">
      <el-steps :active="step" finish-status="success" simple>
        <el-step title="选择文件" /><el-step title="校验" /><el-step
          title="提交"
        />
      </el-steps>
      <div class="crud-transfer__content">
        <el-button v-if="api.template" :loading="busy" @click="downloadTemplate"
          >下载模板</el-button
        >
        <input
          type="file"
          accept=".xlsx,.csv"
          :disabled="busy"
          @change="selectFile"
        />
        <p v-if="file">{{ file.name }}</p>
        <el-alert v-if="error" :title="error" type="error" :closable="false" />
        <el-table v-if="issues.length" :data="issues" max-height="260">
          <el-table-column prop="row" label="行号" width="80" />
          <el-table-column prop="message" label="错误" />
        </el-table>
      </div>
      <template #footer>
        <el-button @click="visible = false">取消</el-button>
        <el-button
          v-if="step === 0"
          type="primary"
          :disabled="!file"
          :loading="busy"
          @click="validate"
          >校验</el-button
        >
        <el-button
          v-if="step === 1"
          type="primary"
          :disabled="issues.length > 0"
          :loading="busy"
          @click="submit"
          >提交</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
  import { onScopeDispose, ref, watch } from 'vue';
  import useCrudTab from '@/hooks/useCrudTab';

  interface Issue {
    row: number;
    message: string;
  }
  interface Validation {
    issues: Issue[];
    token: string;
  }
  type Format = 'xlsx' | 'csv';
  interface Download {
    blob: Blob;
    filename: string;
  }
  interface TransferApi {
    template?: (signal: AbortSignal) => Promise<Download>;
    validate: (file: File, signal: AbortSignal) => Promise<Validation>;
    submit: (token: string, signal: AbortSignal) => Promise<unknown>;
    export: (format: Format, signal: AbortSignal) => Promise<Download>;
  }
  const props = defineProps<{
    api: TransferApi;
    canImport: boolean;
    canExport: boolean;
  }>();
  const emit = defineEmits<{ (event: 'imported'): void }>();
  const format = ref<Format>('xlsx');
  const visible = ref(false);
  const step = ref(0);
  const file = ref<File>();
  const issues = ref<Issue[]>([]);
  const token = ref('');
  const error = ref('');
  const busy = ref(false);
  const exporting = ref(false);
  let request: AbortController | undefined;
  const cancel = () => {
    request?.abort();
    request = undefined;
    busy.value = false;
    exporting.value = false;
  };
  const current = useCrudTab(() => {
    visible.value = false;
    cancel();
  });
  onScopeDispose(cancel);
  watch(
    () => [props.canImport, props.canExport],
    ([allowImport, allowExport]) => {
      if (!allowImport) visible.value = false;
      if (!allowImport || !allowExport) cancel();
    }
  );
  const openImport = () => {
    if (!props.canImport || !current.value) return;
    cancel();
    file.value = undefined;
    issues.value = [];
    error.value = '';
    token.value = '';
    step.value = 0;
    visible.value = true;
  };
  const selectFile = (event: Event) => {
    file.value = (event.target as HTMLInputElement).files?.[0];
    step.value = 0;
    issues.value = [];
    token.value = '';
    error.value = '';
  };
  const download = (blob: Blob, name: string) => {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.click();
    URL.revokeObjectURL(url);
  };
  const run = async (
    operation: (signal: AbortSignal) => Promise<void>,
    isExport = false
  ) => {
    if (
      request ||
      !current.value ||
      (isExport ? !props.canExport : !props.canImport)
    )
      return;
    const controller = new AbortController();
    request = controller;
    error.value = '';
    if (isExport) exporting.value = true;
    else busy.value = true;
    try {
      await operation(controller.signal);
    } catch (cause) {
      if (!controller.signal.aborted)
        error.value = cause instanceof Error ? cause.message : String(cause);
    } finally {
      if (request === controller) {
        request = undefined;
        busy.value = false;
        exporting.value = false;
      }
    }
  };
  const downloadTemplate = () =>
    run(async (signal) => {
      if (props.api.template) {
        const result = await props.api.template(signal);
        if (!signal.aborted) download(result.blob, result.filename);
      }
    });
  const validate = () =>
    run(async (signal) => {
      if (!file.value) return;
      const result = await props.api.validate(file.value, signal);
      if (signal.aborted) return;
      issues.value = result.issues;
      token.value = result.token;
      step.value = 1;
    });
  const submit = () =>
    run(async (signal) => {
      await props.api.submit(token.value, signal);
      if (signal.aborted) return;
      visible.value = false;
      emit('imported');
    });
  const exportData = () =>
    run(async (signal) => {
      const result = await props.api.export(format.value, signal);
      if (!signal.aborted) download(result.blob, result.filename);
    }, true);
</script>

<style scoped>
  .crud-transfer {
    display: inline-flex;
    gap: 8px;
  }
  .crud-transfer__content {
    padding: 20px 0;
  }
</style>
