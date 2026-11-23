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
        v-if="views && !savedViews"
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
      <template v-if="views && !savedViews">
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
      <template v-if="savedViewKey">
        <el-select
          v-model="selectedView"
          placeholder="已保存视图"
          v-bind="{ ariaLabel: '已保存视图' }"
          @change="applySavedView"
        >
          <el-option
            v-for="view in savedViewItems"
            :key="view.name"
            :label="view.name"
            :value="view.name"
          />
        </el-select>
        <el-input
          v-model="savedViewName"
          maxlength="80"
          placeholder="视图名称"
          v-bind="{ ariaLabel: '视图名称' }"
          style="width: 150px"
          @keydown.enter="saveCurrentView"
        />
        <el-button :disabled="!savedViewName.trim()" @click="saveCurrentView"
          >保存视图</el-button
        >
        <el-button
          :disabled="!selectedView"
          @click="applySavedView(selectedView)"
          >应用视图</el-button
        >
        <el-button :disabled="!selectedView" @click="deleteSavedView"
          >删除视图</el-button
        >
      </template>
      <TableColumnSetting
        ref="columnSettings"
        v-if="fields && effectiveColumnKey"
        :key="`${effectiveColumnKey}:${fields
          .map((field) => field.label)
          .join('|')}`"
        :schema="fields"
        :storage-key="effectiveColumnKey"
        @change="onColumnsChange"
      />
    </div>
    <el-alert
      v-if="viewError"
      :title="viewError"
      type="error"
      :closable="false"
    />
    <el-alert
      v-if="editError"
      :title="editError"
      type="error"
      :closable="false"
    />
    <div v-if="selection.length && $slots.batch" class="crud-page__batch">
      <slot name="batch" :selection="selection" />
    </div>
    <div
      v-if="error && !loading && data.length === 0"
      class="crud-page__load-error"
      role="alert"
    >
      <el-alert title="列表加载失败，请重试" type="error" :closable="false" />
      <el-button type="primary" @click="$emit('retry')">重试</el-button>
    </div>
    <div
      v-if="error && !loading && data.length > 0"
      class="crud-page__load-error"
      role="alert"
    >
      <el-alert
        title="列表加载失败，当前仍显示上次结果"
        type="warning"
        :closable="false"
      />
      <el-button type="primary" @click="$emit('retry')">重试</el-button>
    </div>
    <el-table
      v-if="!error || loading || data.length > 0"
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
        v-if="saveEdit && canEdit && editableFields.length"
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

<script
  setup
  lang="ts"
  generic="TRow extends object, TFilters extends Record<string, unknown> = Record<string, unknown>"
>
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
    error?: string | null;
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
    savedViews?: {
      pageKey: string;
      tenantId: number | string;
      userId: number | string;
      filters: TFilters;
      applyFilters: (filters: TFilters) => void;
    };
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
    (event: 'search' | 'reset' | 'retry'): void;
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
  interface SavedView {
    name: string;
    filters: TFilters;
    columns: string[];
  }
  const columnSettings = ref<InstanceType<typeof TableColumnSetting>>();
  const savedViewItems = shallowRef<SavedView[]>([]);
  const selectedView = ref('');
  const savedViewName = ref('');
  const viewError = ref('');
  const savedViewKey = computed(() => {
    const config = props.savedViews;
    if (!config?.pageKey || !config.tenantId || !config.userId) return '';
    return `peanut:crud:views:${JSON.stringify([
      config.pageKey,
      String(config.tenantId),
      String(config.userId),
    ])}`;
  });
  const effectiveColumnKey = computed(() => {
    if (!props.savedViews) return props.columnStorageKey;
    return savedViewKey.value ? `${savedViewKey.value}:columns` : undefined;
  });
  watch(
    savedViewKey,
    (key) => {
      savedViewName.value = '';
      selectedView.value = '';
      viewError.value = '';
      savedViewItems.value = [];
      if (!key) return;
      try {
        const raw = localStorage.getItem(key);
        const stored: unknown = raw ? JSON.parse(raw) : [];
        if (!Array.isArray(stored)) return;
        savedViewItems.value = stored.filter(
          (view): view is SavedView =>
            view &&
            typeof view.name === 'string' &&
            view.filters &&
            typeof view.filters === 'object' &&
            !Array.isArray(view.filters) &&
            Array.isArray(view.columns) &&
            view.columns.every((column: unknown) => typeof column === 'string')
        );
      } catch {
        viewError.value = '无法读取已保存视图';
      }
    },
    { immediate: true, flush: 'sync' }
  );

  const writeSavedViews = (views: SavedView[]) => {
    if (!savedViewKey.value) return false;
    try {
      localStorage.setItem(savedViewKey.value, JSON.stringify(views));
      savedViewItems.value = views;
      viewError.value = '';
      return true;
    } catch {
      viewError.value = '无法保存视图，请检查浏览器存储设置';
      return false;
    }
  };
  const deleteSavedView = () => {
    if (
      writeSavedViews(
        savedViewItems.value.filter((view) => view.name !== selectedView.value)
      )
    ) {
      selectedView.value = '';
    }
  };
  const customFields = shallowRef<Field[]>();
  const displayedFields = computed(
    () =>
      customFields.value ??
      props.fields?.filter((field) => field.visible !== false) ??
      []
  );
  const editableFields = computed(() =>
    displayedFields.value.filter((field) => field.editable)
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
  const saveCurrentView = () => {
    const name = savedViewName.value.trim();
    if (!name || !props.savedViews || !savedViewKey.value) return;
    try {
      const view: SavedView = {
        name,
        filters: JSON.parse(JSON.stringify(props.savedViews.filters)),
        columns: displayedFields.value.map((field) => field.key),
      };
      if (
        writeSavedViews([
          ...savedViewItems.value.filter((item) => item.name !== name),
          view,
        ])
      ) {
        selectedView.value = name;
        savedViewName.value = '';
      }
    } catch {
      viewError.value = '无法保存当前筛选条件';
    }
  };
  const applySavedView = (name: string) => {
    const view = savedViewItems.value.find((item) => item.name === name);
    if (!view || !props.savedViews) return;
    cancelEdit();
    columnSettings.value?.applyVisibility(view.columns);
    props.savedViews.applyFilters(JSON.parse(JSON.stringify(view.filters)));
  };
  const active = useCrudTab(cancelEdit);
  watch(
    () => editing.value && props.canEdit?.(editing.value),
    (allowed) => {
      if (editing.value && !allowed) cancelEdit();
    },
    { flush: 'sync' }
  );
  watch(
    () => editableFields.value.map((field) => field.key).join('\0'),
    cancelEdit
  );
  watch(
    effectiveColumnKey,
    () => {
      customFields.value = undefined;
      cancelEdit();
    },
    { flush: 'sync' }
  );
  watch(
    () => props.data,
    (data) => {
      if (editing.value && !saving.value && !data.includes(editing.value))
        cancelEdit();
    }
  );
  const startEdit = (row: TRow) => {
    if (!active.value || !props.canEdit?.(row) || !props.saveEdit) return;
    editing.value = row;
    editError.value = '';
    draft.value = Object.fromEntries(
      editableFields.value.map((field) => [
        field.key,
        String(Reflect.get(row, field.key) ?? ''),
      ])
    );
  };
  const confirmEdit = async () => {
    const row = editing.value;
    const save = props.saveEdit;
    if (!row || !save || !props.canEdit?.(row) || !active.value || saving.value)
      return;
    const values = Object.fromEntries(
      editableFields.value
        .map(({ key }) => [key, draft.value[key]] as const)
        .filter(
          ([key, value]) =>
            value !== undefined && value !== String(Reflect.get(row, key) ?? '')
        )
    ) as Record<string, string>;
    if (!Object.keys(values).length) {
      cancelEdit();
      return;
    }
    const controller = new AbortController();
    editRequest = controller;
    saving.value = true;
    editError.value = '';
    try {
      await save(row, values, controller.signal);
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
        if (!props.data.includes(row)) cancelEdit();
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
  .crud-page__load-error {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
  }
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
