<template>
  <section class="crud-page">
    <div v-if="$slots.search" class="crud-page__search">
      <div v-show="searchExpanded"><slot name="search" /></div>
      <el-button v-if="!hideSearchActions" @click="$emit('search')"
        >查询</el-button
      >
      <el-button v-if="!hideSearchActions" @click="$emit('reset')"
        >重置</el-button
      >
      <el-button text @click="searchExpanded = !searchExpanded">
        {{ searchExpanded ? '收起' : '展开' }}
      </el-button>
    </div>
    <div class="crud-page__toolbar">
      <slot name="toolbar" />
      <el-select
        v-if="views"
        placeholder="常用视图"
        v-bind="{ ariaLabel: '常用视图' }"
        @change="$emit('applyView', $event)"
      >
        <el-option
          v-for="view in views"
          :key="view.id"
          :label="view.label"
          :value="view.id"
        />
      </el-select>
      <template v-if="views">
        <el-input
          v-model="viewName"
          placeholder="视图名称"
          v-bind="{ ariaLabel: '视图名称' }"
          style="width: 150px"
        />
        <el-button :disabled="!viewName.trim()" @click="saveView"
          >保存视图</el-button
        >
      </template>
      <TableColumnSetting
        v-if="fields && columnStorageKey"
        :key="`${columnStorageKey}:${fields
          .map((field) => field.label)
          .join('|')}`"
        :schema="fields"
        :storage-key="columnStorageKey"
        @change="onColumnsChange"
      />
    </div>
    <el-alert
      v-if="editError"
      :title="editError"
      type="error"
      :closable="false"
    />
    <div v-if="selection.length && $slots.batch" class="crud-page__batch">
      <slot name="batch" :selection="selection" />
    </div>
    <el-table
      v-loading="loading"
      :class="tableClass"
      :data="tableData"
      :row-key="rowKey"
      :show-summary="showSummary"
      :border="border"
      :tree-props="treeProps"
      :default-expand-all="defaultExpandAll"
      :size="tableDensity"
      :height="fixedHeader ? tableHeight ?? '60vh' : undefined"
      @selection-change="onSelectionChange"
      @expand-change="onExpandChange"
      @sort-change="onSortChange"
    >
      <el-table-column
        v-for="field in displayedFields"
        :key="field.key"
        :prop="field.key"
        :label="field.label"
        :width="field.width"
        :fixed="field.fixed"
      >
        <template #default="{ row }">
          <el-input
            v-if="editing === row && field.editable"
            v-model="draft[field.key]"
          />
          <template v-else>{{ row[field.key] }}</template>
        </template>
      </el-table-column>
      <el-table-column
        v-if="saveEdit && canEdit && fields?.some((field) => field.editable)"
        label="操作"
        width="150"
      >
        <template #default="{ row }">
          <template v-if="editing === row">
            <el-button
              link
              type="primary"
              :loading="saving"
              @click="confirmEdit"
              >确定</el-button
            >
            <el-button link :disabled="saving" @click="cancelEdit"
              >取消</el-button
            >
          </template>
          <el-button
            v-else-if="canEdit(row)"
            link
            type="primary"
            :disabled="saving || !!editing"
            @click="startEdit(row)"
            >编辑</el-button
          >
        </template>
      </el-table-column>
      <slot name="columns" />
      <template v-if="$slots.empty" #empty><slot name="empty" /></template>
      <slot />
    </el-table>
    <el-pagination
      :current-page="pagination.page"
      :page-size="pagination.pageSize"
      :total="pagination.total"
      :disabled="paginationDisabled"
      :layout="paginationLayout ?? 'total, sizes, prev, pager, next, jumper'"
      @current-change="$emit('pageChange', $event)"
      @size-change="$emit('sizeChange', $event)"
    />
  </section>
</template>

<script setup lang="ts" generic="TRow extends object">
  import { computed, ref, shallowRef, watch } from 'vue';
  import { ElLoadingDirective as vLoading } from 'element-plus';
  import useCrudTab from '@/hooks/useCrudTab';
  import TableColumnSetting from './TableColumnSetting.vue';

  interface Field {
    key: string;
    label: string;
    visible?: boolean;
    fixed?: 'left' | 'right' | false;
    width?: number;
    editable?: boolean;
  }

  const props = defineProps<{
    loading: boolean;
    data: readonly TRow[];
    pagination: { page: number; pageSize: number; total: number };
    rowKey?: string;
    showSummary?: boolean;
    border?: boolean;
    tableClass?: string;
    hideSearchActions?: boolean;
    paginationLayout?: string;
    paginationDisabled?: boolean;
    treeProps?: { children?: string; hasChildren?: string };
    defaultExpandAll?: boolean;
    views?: readonly { id: string; label: string }[];
    fields?: Field[];
    columnStorageKey?: string;
    tableHeight?: string | number;
    canEdit?: (row: TRow) => boolean;
    saveEdit?: (
      row: TRow,
      values: Record<string, string>,
      signal: AbortSignal
    ) => Promise<void>;
  }>();
  const emit = defineEmits<{
    (event: 'search' | 'reset'): void;
    (event: 'applyView' | 'saveView', value: string): void;
    (event: 'edited', value: TRow): void;
    (event: 'pageChange' | 'sizeChange', value: number): void;
    (event: 'selectionChange', value: TRow[]): void;
    (event: 'expandChange', row: TRow, expanded: TRow[] | boolean): void;
    (
      event: 'sortChange',
      value: { prop: string; order: 'ascending' | 'descending' | null }
    ): void;
  }>();
  const viewName = ref('');
  const saveView = () => {
    const name = viewName.value.trim();
    if (name) {
      emit('saveView', name);
      viewName.value = '';
    }
  };
  const customFields = shallowRef<Field[]>();
  const displayedFields = computed(
    () =>
      customFields.value ??
      props.fields?.filter((field) => field.visible !== false) ??
      []
  );
  const tableDensity = ref<'default' | 'large' | 'small'>('default');
  const fixedHeader = ref(false);
  const onColumnsChange = (value: {
    columns: Field[];
    density: string | undefined;
    fixedHeader: boolean | undefined;
  }) => {
    customFields.value = value.columns;
    if (
      value.density === 'default' ||
      value.density === 'small' ||
      value.density === 'large'
    )
      tableDensity.value = value.density;
    fixedHeader.value = value.fixedHeader ?? false;
  };
  const editing = shallowRef<TRow>();
  const draft = ref<Record<string, string>>({});
  const saving = ref(false);
  const editError = ref('');
  let editRequest: AbortController | undefined;
  const cancelEdit = () => {
    editRequest?.abort();
    editRequest = undefined;
    saving.value = false;
    editing.value = undefined;
    draft.value = {};
  };
  const active = useCrudTab(cancelEdit);
  watch(
    () => editing.value && props.canEdit?.(editing.value),
    (allowed) => {
      if (editing.value && !allowed) cancelEdit();
    },
    { flush: 'sync' }
  );
  const startEdit = (row: TRow) => {
    if (!active.value || !props.canEdit?.(row) || !props.saveEdit) return;
    editing.value = row;
    editError.value = '';
    const record: object = row;
    draft.value = Object.fromEntries(
      (props.fields ?? [])
        .filter((field) => field.editable)
        .map((field) => [
          field.key,
          String(Reflect.get(record, field.key) ?? ''),
        ])
    );
  };
  const confirmEdit = async () => {
    const row = editing.value;
    const save = props.saveEdit;
    if (!row || !save || !props.canEdit?.(row) || !active.value || saving.value)
      return;
    const controller = new AbortController();
    editRequest = controller;
    saving.value = true;
    editError.value = '';
    try {
      await save(row, { ...draft.value }, controller.signal);
      if (!controller.signal.aborted) {
        cancelEdit();
        emit('edited', row);
      }
    } catch (error) {
      if (!controller.signal.aborted)
        editError.value =
          error instanceof Error ? error.message : String(error);
    } finally {
      if (editRequest === controller) {
        editRequest = undefined;
        saving.value = false;
      }
    }
  };
  const tableData = computed(() => [...props.data]);
  const searchExpanded = ref(true);
  const selection = ref<TRow[]>([]);
  const onSortChange = (value: {
    prop: string | null;
    order: string | null;
  }) => {
    const order =
      value.order === 'ascending' || value.order === 'descending'
        ? value.order
        : null;
    emit('sortChange', { prop: value.prop ?? '', order });
  };
  const onExpandChange = (row: TRow, expanded: TRow[] | boolean) =>
    emit('expandChange', row, expanded);
  const onSelectionChange = (value: TRow[]) => {
    selection.value = value;
    emit('selectionChange', value);
  };
</script>

<style scoped>
  .crud-page__search,
  .crud-page__toolbar,
  .crud-page__batch {
    margin-bottom: 16px;
  }
  .crud-page__batch {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--el-bg-color);
  }
  .el-pagination {
    margin-top: 16px;
    justify-content: flex-end;
  }
</style>
