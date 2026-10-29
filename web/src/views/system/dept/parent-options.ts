import {
  computed,
  defineComponent,
  h,
  onScopeDispose,
  ref,
  watch,
  type PropType,
} from 'vue';
import {
  createTenantLifecycle,
  registerTenantDisposer,
} from '@peanut-admin/vue';
import {
  getDeptAll,
  type DepartmentPage,
  type DirectoryDepartment,
  type DeptListParams,
} from '@/api/system/dept';
import { getSessionSnapshot } from '@/utils/auth';

export const ROOT_DEPARTMENT_ID = 0;

export interface ParentDepartmentRecord {
  id: number;
  name: string;
  children?: ParentDepartmentRecord[];
}

export interface ParentDepartmentOption {
  id: number;
  name: string;
  children: ParentDepartmentOption[];
}

export function initialParentDepartmentId(parentId?: number): number {
  return parentId ?? ROOT_DEPARTMENT_ID;
}

export function buildParentDepartmentOptions(
  departments: ParentDepartmentRecord[],
  rootLabel: string
): ParentDepartmentOption[] {
  const mapDepartments = (
    nodes: ParentDepartmentRecord[]
  ): ParentDepartmentOption[] =>
    nodes.map((node) => ({
      id: node.id,
      name: node.name,
      children: mapDepartments(node.children || []),
    }));

  return [
    {
      id: ROOT_DEPARTMENT_ID,
      name: rootLabel,
      children: [],
    },
    ...mapDepartments(departments),
  ];
}

/** Keeps only pages explicitly opened by the user, within the current identity/filter. */
export function useDepartmentDirectory(
  load: (
    params: DeptListParams,
    signal?: AbortSignal
  ) => Promise<{ data: DepartmentPage }>
) {
  const rows = ref<DirectoryDepartment[]>([]);
  const pages = ref<
    Record<number, { page: number; hasMore: boolean; count: number }>
  >({});
  const busy = ref<Record<number, boolean>>({});
  const error = ref('');
  const lifecycle = createTenantLifecycle();
  let filters: { name?: string; status?: number | string | null } = {};
  const mode = ref<'children' | 'search'>('children');
  const isSearch = computed(() => mode.value === 'search');
  let identity = getSessionSnapshot().generation;
  const clear = () => {
    lifecycle.invalidate();
    rows.value = [];
    pages.value = {};
    busy.value = {};
    error.value = '';
    mode.value = 'children';
  };
  const unregister = registerTenantDisposer(
    `department-directory-${crypto.randomUUID()}`,
    clear
  );
  onScopeDispose(() => {
    clear();
    unregister();
  });
  const read = async (parent = 0, next = false) => {
    if (getSessionSnapshot().generation !== identity) {
      clear();
      return;
    }
    if (busy.value[parent] || (next && !pages.value[parent]?.hasMore)) return;
    const ticket = lifecycle.capture();
    const pageNo = next ? (pages.value[parent]?.page ?? 0) + 1 : 1;
    busy.value[parent] = true;
    try {
      const { data } = await load(
        {
          mode: mode.value,
          pageNo,
          pageSize: 20,
          ...(isSearch.value
            ? { name: filters.name, status: filters.status ?? undefined }
            : { parent_id: parent }),
        },
        ticket.signal
      );
      if (!ticket.isCurrent() || getSessionSnapshot().generation !== identity)
        return;
      const known = new Map(rows.value.map((row) => [row.id, row]));
      data.ancestors.forEach((row) => {
        if (!known.has(row.id)) known.set(row.id, row);
      });
      data.lists.forEach((row) => known.set(row.id, row));
      rows.value = [...known.values()];
      pages.value[parent] = {
        page: pageNo,
        hasMore: data.hasMore,
        count: data.count,
      };
    } catch {
      if (ticket.isCurrent()) error.value = '部门目录加载失败，请重试';
    } finally {
      if (ticket.isCurrent()) busy.value[parent] = false;
    }
  };
  const reset = async (value: typeof filters = {}) => {
    clear();
    identity = getSessionSnapshot().generation;
    filters = {
      name: value.name?.trim() || undefined,
      status: value.status === '' ? undefined : value.status ?? undefined,
    };
    mode.value =
      filters.name || filters.status !== undefined ? 'search' : 'children';
    await read();
  };
  type Node = DirectoryDepartment & { children: Node[] };
  const tree = computed(() => {
    const nodes = new Map<number, Node>(
      rows.value.map((row) => [row.id, { ...row, children: [] }])
    );
    const roots: Node[] = [];
    nodes.forEach((node) => {
      if (node.pid === 0) roots.push(node);
      else nodes.get(node.pid)?.children.push(node);
    });
    const sort = (items: Node[]) => {
      items.sort((a, b) => a.sort - b.sort || a.id - b.id);
      items.forEach((n) => sort(n.children));
    };
    sort(roots);
    return roots;
  });
  return { tree, rows, pages, busy, error, isSearch, read, reset, clear };
}

/** Expansion is independent of selection: disabled ancestors remain navigable. */
export const DepartmentPicker = defineComponent({
  name: 'DepartmentPicker',
  props: {
    modelValue: {
      type: [Number, Array] as PropType<number | number[]>,
      default: undefined,
    },
    multiple: Boolean,
    disabled: Boolean,
    rootLabel: String,
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const directory = useDepartmentDirectory(getDeptAll);
    const text = ref('');
    const selectedRows = ref<DirectoryDepartment[]>([]);
    const selectionLife = createTenantLifecycle();
    const values = computed(() => {
      if (Array.isArray(props.modelValue)) return props.modelValue;
      return props.modelValue === undefined ? [] : [props.modelValue];
    });
    const unregister = registerTenantDisposer(
      `department-selection-${crypto.randomUUID()}`,
      () => {
        selectionLife.invalidate();
        selectedRows.value = [];
        text.value = '';
      }
    );
    onScopeDispose(() => {
      selectionLife.invalidate();
      unregister();
    });
    watch(
      () => props.modelValue,
      async () => {
        selectionLife.invalidate();
        const ticket = selectionLife.capture();
        const { generation } = getSessionSnapshot();
        const ids = values.value.filter((id) => id > 0);
        selectedRows.value = [];
        try {
          const readSelectionPage = async (offset: number): Promise<void> => {
            if (offset >= ids.length) return;
            const { data } = await getDeptAll(
              {
                mode: 'resolve',
                ids: ids.slice(offset, offset + 100).join(','),
                pageNo: 1,
                pageSize: 100,
              },
              ticket.signal
            );
            if (
              !ticket.isCurrent() ||
              getSessionSnapshot().generation !== generation
            )
              return;
            selectedRows.value.push(...data.lists, ...data.ancestors);
            await readSelectionPage(offset + 100);
          };
          await readSelectionPage(0);
        } catch {
          /* Preserve the saved ids; failed lookup never clears an association. */
        }
      },
      { immediate: true, deep: true }
    );
    const choose = (id: number) => {
      if (props.disabled) return;
      emit(
        'update:modelValue',
        props.multiple ? [...new Set([...values.value, id])] : id
      );
    };
    const label = (id: number) => {
      if (id === 0) return props.rootLabel || '顶级';
      const known = new Map(selectedRows.value.map((row) => [row.id, row]));
      let row = known.get(id);
      const path: string[] = [];
      for (let depth = 0; row && depth < 10; depth += 1) {
        path.unshift(
          row.name +
            (row.realStatus === 'active'
              ? ''
              : `（${row.status_desc}，不可选）`)
        );
        row = known.get(row.pid);
      }
      return path.join(' / ') || `部门 ${id}（路径未加载）`;
    };
    const more = (parent: number) =>
      directory.pages.value[parent]?.hasMore
        ? h(
            'button',
            {
              type: 'button',
              disabled: directory.busy.value[parent],
              onClick: () => directory.read(parent, true),
            },
            '加载下一页'
          )
        : null;
    const renderNodes = (
      nodes: typeof directory.tree.value
    ): ReturnType<typeof h> =>
      h(
        'ul',
        nodes.map((row) =>
          h('li', { key: row.id }, [
            h(
              'details',
              {
                onToggle: (event: Event) => {
                  if (
                    (event.target as HTMLDetailsElement).open &&
                    row.hasChildren &&
                    !directory.isSearch.value &&
                    !directory.pages.value[row.id]
                  )
                    directory.read(row.id);
                },
              },
              [
                h('summary', [
                  row.name,
                  row.realStatus === 'active' ? '' : `（${row.status_desc}）`,
                  h(
                    'button',
                    {
                      type: 'button',
                      disabled: props.disabled || !row.selectable,
                      onClick: (event: Event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        choose(row.id);
                      },
                    },
                    '选择'
                  ),
                ]),
                renderNodes(row.children),
                !directory.isSearch.value ? more(row.id) : null,
              ]
            ),
          ])
        )
      );
    directory.reset();
    return () =>
      h('div', { style: 'width:100%' }, [
        h(
          'div',
          values.value.map((id) =>
            h('span', { key: id }, [
              label(id),
              props.multiple
                ? h(
                    'button',
                    {
                      type: 'button',
                      disabled: props.disabled,
                      onClick: () =>
                        emit(
                          'update:modelValue',
                          values.value.filter((value) => value !== id)
                        ),
                    },
                    '移除'
                  )
                : null,
            ])
          )
        ),
        h('input', {
          value: text.value,
          placeholder: '搜索部门',
          disabled: props.disabled,
          onInput: (event: Event) => {
            text.value = (event.target as HTMLInputElement).value;
            directory.reset({ name: text.value });
          },
        }),
        props.rootLabel
          ? h(
              'button',
              {
                type: 'button',
                disabled: props.disabled,
                onClick: () => choose(0),
              },
              props.rootLabel
            )
          : null,
        h('div', { style: 'max-height:240px;overflow:auto' }, [
          renderNodes(directory.tree.value),
          more(0),
        ]),
        directory.error.value
          ? h(
              'button',
              {
                type: 'button',
                onClick: () => directory.reset({ name: text.value }),
              },
              directory.error.value
            )
          : null,
      ]);
  },
});
