<template>
  <el-popover trigger="click" placement="bottom-end" :width="360">
    <template #reference><el-button>列设置</el-button></template>
    <div class="column-settings">
      <div ref="list" class="column-settings__list">
        <div
          v-for="column in columns"
          :key="column.key"
          class="column-settings__row"
        >
          <span class="column-settings__handle" aria-label="拖动排序">⋮⋮</span>
          <el-checkbox
            :model-value="column.visible"
            @update:model-value="column.visible = $event === true"
            >{{ column.label }}</el-checkbox
          >
          <el-select
            v-model="column.fixed"
            v-bind="{ ariaLabel: '固定位置' }"
            style="width: 100px"
          >
            <el-option label="不固定" :value="false" />
            <el-option label="左侧" value="left" />
            <el-option label="右侧" value="right" />
          </el-select>
          <el-input-number
            v-model="column.width"
            :min="1"
            :controls="false"
            v-bind="{ ariaLabel: '列宽' }"
            style="width: 75px"
          />
        </div>
      </div>
      <el-select
        v-model="density"
        placeholder="密度"
        v-bind="{ ariaLabel: '密度' }"
      >
        <el-option label="默认" value="default" />
        <el-option label="宽松" value="large" />
        <el-option label="紧凑" value="small" />
      </el-select>
      <el-checkbox
        :model-value="fixedHeader"
        @update:model-value="fixedHeader = $event === true"
        >固定表头</el-checkbox
      >
      <el-button text @click="reset">恢复默认</el-button>
    </div>
  </el-popover>
</template>

<script setup lang="ts">
  import { onBeforeUnmount, ref, watch } from 'vue';
  import Sortable from 'sortablejs';
  import { useTableColumns } from '@peanut-admin/vue';
  import type { TableColumn } from '@peanut-admin/vue';

  interface Column extends TableColumn {
    label: string;
  }
  const props = defineProps<{
    schema: readonly Column[];
    storageKey: string;
    version?: string | number;
  }>();
  const emit = defineEmits<{
    (
      event: 'change',
      value: {
        columns: Column[];
        density: string | undefined;
        fixedHeader: boolean | undefined;
      }
    ): void;
  }>();
  const { columns, visibleColumns, density, fixedHeader, reset } =
    useTableColumns(
      props.schema.map((column) => ({
        ...column,
        visible: column.visible !== false,
      })),
      {
        storageKey: props.storageKey,
        version: props.version,
        density: 'default',
        fixedHeader: false,
      }
    );
  function applyVisibility(keys: readonly string[]) {
    const visible = new Set(keys);
    columns.value.forEach((column) => {
      column.visible = visible.has(column.key);
    });
  }
  defineExpose({ applyVisibility });
  const list = ref<HTMLElement>();
  let sortable: Sortable | undefined;
  watch(
    list,
    (element) => {
      sortable?.destroy();
      if (!element) return;
      sortable = Sortable.create(element, {
        handle: '.column-settings__handle',
        onEnd: ({ oldIndex, newIndex }) => {
          if (
            oldIndex === undefined ||
            newIndex === undefined ||
            oldIndex === newIndex
          )
            return;
          const reordered = columns.value.slice();
          const [item] = reordered.splice(oldIndex, 1);
          if (item) reordered.splice(newIndex, 0, item);
          columns.value = reordered;
        },
      });
    },
    { flush: 'post' }
  );
  onBeforeUnmount(() => sortable?.destroy());
  watch(
    [visibleColumns, density, fixedHeader],
    () =>
      emit('change', {
        columns: visibleColumns.value,
        density: density.value,
        fixedHeader: fixedHeader.value,
      }),
    { deep: true, immediate: true }
  );
</script>

<style scoped>
  .column-settings__row {
    display: flex;
    gap: 4px;
    align-items: center;
    margin-bottom: 8px;
  }
  .column-settings__handle {
    cursor: grab;
  }
</style>
