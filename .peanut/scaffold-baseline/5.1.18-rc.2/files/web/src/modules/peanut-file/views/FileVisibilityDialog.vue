<template>
  <el-dialog
    :model-value="modelValue"
    title="资源可见性"
    width="780px"
    :close-on-click-modal="false"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <el-alert
      v-if="note"
      :title="note"
      type="warning"
      :closable="false"
      show-icon
    />
    <template v-if="plan && !batchKey">
      <el-alert
        :title="plan.confirmation.manifest.warning"
        type="warning"
        :closable="false"
        show-icon
      />
      <p
        >本批包含
        {{ Object.keys(plan.confirmation.manifest.files).length }}
        个资源，已登记引用
        {{ plan.confirmation.manifest.registered_reference_count }} 项。</p
      >
      <el-table :data="Object.values(plan.confirmation.manifest.files)">
        <el-table-column label="资源" prop="source.original_name" />
        <el-table-column label="当前空间" prop="source.space_key" />
        <el-table-column label="目标空间" prop="target.space_key" />
        <el-table-column label="版本" prop="source.revision" width="70" />
      </el-table>
      <el-checkbox v-model="accepted"
        >我确认公开页面和已有引用可能受影响，继续设为私有</el-checkbox
      >
      <p
        >预检查到期时间：{{
          new Date(plan.confirmation.expires_at * 1000).toLocaleTimeString()
        }}</p
      >
      <el-button
        type="primary"
        :disabled="!accepted || busy"
        :loading="busy"
        @click="submit"
        >确认本批变更</el-button
      >
    </template>
    <template v-if="batchKey">
      <p>批次：{{ batchKey }}</p>
      <p v-if="batch"
        >状态：{{ label(batch.status) }}。已下载副本和第三方缓存无法收回。</p
      >
      <el-table v-if="batch" :data="batch.items">
        <el-table-column label="资源标识" prop="file_key" min-width="210" />
        <el-table-column label="状态" width="150"
          ><template #default="{ row }">{{
            label(row.status)
          }}</template></el-table-column
        >
        <el-table-column label="当前阶段" width="130"
          ><template #default="{ row }">{{
            label(row.step)
          }}</template></el-table-column
        >
        <el-table-column label="待处理原因" prop="error_code" />
      </el-table>
      <el-space>
        <el-button :disabled="busy" @click="refreshBatch">查询原批次</el-button>
        <el-button
          v-if="batch && batch.status !== 'completed'"
          :disabled="busy"
          @click="resume"
          >核对原状态并继续</el-button
        >
      </el-space>
    </template>
    <el-divider />
    <p>最近的可见性任务</p>
    <el-table :data="recent">
      <el-table-column label="批次" prop="batch_key" />
      <el-table-column label="状态" width="140"
        ><template #default="{ row }">{{
          label(row.status)
        }}</template></el-table-column
      >
      <el-table-column width="80"
        ><template #default="{ row }"
          ><el-button link :disabled="busy" @click="openBatch(row.batch_key)"
            >查看</el-button
          ></template
        ></el-table-column
      >
    </el-table>
  </el-dialog>
</template>

<script setup lang="ts">
  import { ref, watch } from 'vue';
  import {
    previewFileVisibility,
    submitFileVisibility,
    getFileVisibilityBatch,
    resumeFileVisibilityBatch,
    recentFileVisibilityBatches,
    type FileVisibilityBatch,
    type FileVisibilityConfirmation,
    FileVisibilityRejection,
  } from '@/modules/peanut-file/api';

  const props = defineProps<{
    modelValue: boolean;
    fileKeys: string[];
    contextKey: string;
  }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void;
    (event: 'changed'): void;
  }>();
  const plan = ref<{
    confirmation: FileVisibilityConfirmation;
    signature: string;
  }>();
  const accepted = ref(false);
  const busy = ref(false);
  const note = ref('');
  const batchKey = ref('');
  const batch = ref<FileVisibilityBatch>();
  const recent = ref<
    Array<Pick<FileVisibilityBatch, 'batch_key' | 'status' | 'revision'>>
  >([]);
  const pendingKey = 'peanut.file.visibility.pending';
  const labels: Record<string, string> = {
    pending: '已接纳，待处理',
    running: '处理中',
    completed: '完成',
    attention: '受限，需处理',
    restricting: '正在收紧访问',
    verifying: '正在核验',
    ready_private: '已私有',
    restricted_attention: '受限，需处理',
    copy: '保全私有副本',
    switch: '切换位置',
    close_source: '关闭旧位置',
    verify: '核验访问',
  };
  const label = (state: string) => labels[state] ?? state;
  const remember = (key: string) => {
    // Only a non-secret locator is retained. Confirmation/signatures and URLs are never stored.
    try {
      sessionStorage.setItem(
        pendingKey,
        JSON.stringify({ context: props.contextKey, batch_key: key })
      );
    } catch {
      /* The server still owns the durable intent. */
    }
  };
  const pending = () => {
    try {
      const value = JSON.parse(sessionStorage.getItem(pendingKey) ?? 'null');
      return value?.context === props.contextKey &&
        /^batch_[a-f0-9]{32}$/.test(value.batch_key)
        ? String(value.batch_key)
        : '';
    } catch {
      return '';
    }
  };
  const observed = (value: FileVisibilityBatch) => {
    batch.value = value;
    if (value.status === 'completed') {
      try {
        if (pending() === value.batch_key)
          sessionStorage.removeItem(pendingKey);
      } catch {
        /* No credentials are stored. */
      }
    }
    emit('changed');
  };
  const refreshBatch = async () => {
    if (!batchKey.value || busy.value) return;
    const key = batchKey.value;
    const context = props.contextKey;
    busy.value = true;
    try {
      const { data } = await getFileVisibilityBatch(key);
      if (context === props.contextKey && key === batchKey.value) {
        observed(data);
        note.value = '';
      }
    } catch {
      if (context === props.contextKey)
        note.value = '暂未取得批次结果，请继续查询原批次；不要重复提交变更。';
    } finally {
      if (context === props.contextKey) busy.value = false;
    }
  };
  const openBatch = async (key: string) => {
    batchKey.value = key;
    batch.value = undefined;
    plan.value = undefined;
    await refreshBatch();
  };
  const submit = async () => {
    if (!plan.value || !accepted.value || busy.value || batchKey.value) return;
    if (plan.value.confirmation.expires_at * 1000 <= Date.now()) {
      note.value = '预检查已过期，请重新选择资源并预检查。';
      return;
    }
    const current = plan.value;
    const context = props.contextKey;
    busy.value = true;
    batchKey.value = current.confirmation.batch_key;
    remember(batchKey.value);
    try {
      const { data } = await submitFileVisibility(
        current.confirmation,
        current.signature,
        crypto.randomUUID()
      );
      if (context === props.contextKey) {
        observed(data);
        note.value = '';
      }
    } catch (error) {
      if (context === props.contextKey) {
        note.value =
          '提交结果尚未确认。批次标识已保留，请查询原批次，不会自动重发。';
        // A response after admission may still fail. Only a definitive rejection
        // followed by an authenticated not-found permits abandoning the candidate.
        if (error instanceof FileVisibilityRejection) {
          try {
            const { data } = await getFileVisibilityBatch(
              current.confirmation.batch_key
            );
            if (context === props.contextKey) observed(data);
          } catch (lookup) {
            if (
              context === props.contextKey &&
              lookup instanceof FileVisibilityRejection &&
              lookup.status === 404 &&
              lookup.errorCode === 'FILE_VISIBILITY_BATCH_NOT_FOUND'
            ) {
              try {
                if (pending() === current.confirmation.batch_key)
                  sessionStorage.removeItem(pendingKey);
              } catch {
                /* The candidate is also cleared in memory. */
              }
              batchKey.value = '';
              batch.value = undefined;
              accepted.value = false;
              note.value = '本批未被接纳，请关闭窗口，重新选择资源并预检查。';
            }
          }
        }
      }
    } finally {
      if (context === props.contextKey) {
        busy.value = false;
        plan.value = undefined;
      }
    }
  };
  const resume = async () => {
    if (
      !batchKey.value ||
      busy.value ||
      !batch.value ||
      batch.value.status === 'completed'
    )
      return;
    const key = batchKey.value;
    const context = props.contextKey;
    busy.value = true;
    remember(key);
    try {
      const { data } = await resumeFileVisibilityBatch(key);
      if (context === props.contextKey && key === batchKey.value) {
        observed(data);
        note.value = '';
      }
    } catch {
      if (context === props.contextKey)
        note.value = '本次继续的结果尚未确认，请先查询原批次。';
    } finally {
      if (context === props.contextKey) busy.value = false;
    }
  };
  watch(
    () => props.contextKey,
    () => {
      plan.value = undefined;
      batch.value = undefined;
      batchKey.value = '';
      recent.value = [];
      accepted.value = false;
      busy.value = false;
      note.value = '';
      emit('update:modelValue', false);
    }
  );
  watch(
    () => props.modelValue,
    async (visible) => {
      if (!visible) {
        plan.value = undefined;
        accepted.value = false;
        return;
      }
      const context = props.contextKey;
      busy.value = true;
      note.value = '';
      plan.value = undefined;
      accepted.value = false;
      batchKey.value = pending();
      batch.value = undefined;
      try {
        const { data } = await recentFileVisibilityBatches();
        if (context !== props.contextKey) return;
        recent.value = data.items;
        if (!batchKey.value && props.fileKeys.length) {
          const result = await previewFileVisibility([...props.fileKeys]);
          if (context === props.contextKey) plan.value = result.data;
        }
      } catch {
        if (context === props.contextKey)
          note.value =
            '尚未完成预检查。未确认的批次请先查询原状态，其他资源可稍后重新选择。';
      } finally {
        if (context === props.contextKey) {
          busy.value = false;
          if (batchKey.value) await refreshBatch();
        }
      }
    }
  );
</script>
