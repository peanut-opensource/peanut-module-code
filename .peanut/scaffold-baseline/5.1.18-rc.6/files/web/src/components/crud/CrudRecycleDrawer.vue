<template>
  <el-drawer v-model="visible" title="回收站" @closed="cancel">
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="rows">
      <slot name="columns" />
      <el-table-column label="操作" width="150">
        <template #default="{ row }">
          <el-button
            v-if="canRestore"
            link
            type="primary"
            :disabled="loading"
            @click="restore(row)"
            >恢复</el-button
          >
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      :current-page="page"
      :page-size="pageSize"
      :total="total"
      :disabled="loading"
      layout="total, prev, pager, next"
      @current-change="load"
    />
  </el-drawer>
</template>

<script setup lang="ts" generic="TRow extends object">
  import { ref, shallowRef, watch } from 'vue';
  import useCrudTab from '@/hooks/useCrudTab';

  const props = defineProps<{
    modelValue: boolean;
    canList: boolean;
    canRestore: boolean;
    list: (
      page: number,
      pageSize: number,
      signal: AbortSignal
    ) => Promise<{ items: TRow[]; total: number }>;
    restore: (row: TRow, signal: AbortSignal) => Promise<unknown>;
  }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void;
    (event: 'restored'): void;
  }>();
  const visible = ref(props.modelValue);
  const rows = shallowRef<TRow[]>([]);
  const page = ref(1);
  const pageSize = 20;
  const total = ref(0);
  const loading = ref(false);
  const error = ref('');
  let request: AbortController | undefined;
  const cancel = () => {
    request?.abort();
    request = undefined;
    loading.value = false;
  };
  const current = useCrudTab(() => {
    cancel();
    visible.value = false;
  });
  const run = async (operation: (signal: AbortSignal) => Promise<void>) => {
    cancel();
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    error.value = '';
    try {
      await operation(controller.signal);
    } catch (cause) {
      if (!controller.signal.aborted)
        error.value = cause instanceof Error ? cause.message : String(cause);
    } finally {
      if (request === controller) {
        request = undefined;
        loading.value = false;
      }
    }
  };
  const load = (next = page.value) => {
    if (!visible.value || !current.value || !props.canList) return;
    run(async (signal) => {
      const result = await props.list(next, pageSize, signal);
      if (signal.aborted) return;
      rows.value = result.items;
      total.value = result.total;
      page.value = next;
    });
  };
  const restore = (row: TRow) => {
    if (!props.canRestore || !props.canList || !visible.value || !current.value)
      return;
    run(async (signal) => {
      await props.restore(row, signal);
      if (signal.aborted) return;
      emit('restored');
      const result = await props.list(page.value, pageSize, signal);
      if (!signal.aborted) {
        rows.value = result.items;
        total.value = result.total;
      }
    });
  };
  watch(
    () => props.modelValue,
    (value) => {
      visible.value = value && props.canList;
      if (visible.value) load(1);
    },
    { immediate: true }
  );
  watch(
    () => props.canRestore,
    (allowed) => {
      if (!allowed) cancel();
    }
  );
  watch(visible, (value) => {
    if (!value) cancel();
    emit('update:modelValue', value);
  });
  watch(
    () => props.canList,
    (allowed) => {
      if (!allowed) {
        cancel();
        visible.value = false;
        rows.value = [];
      }
    }
  );
</script>
