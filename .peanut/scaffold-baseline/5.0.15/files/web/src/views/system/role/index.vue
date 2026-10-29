<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.role']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.role') }}</template>
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-space>
            <el-button
              v-permission="['role/add']"
              type="primary"
              @click="handleAdd"
            >
              <template #icon><Plus /></template>
              {{ $t('systemRole.operation.create') }}
            </el-button>
            <el-button @click="fetchData(pagination.current)">
              <template #icon><Refresh /></template>
              {{ $t('systemRole.operation.refresh') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>

      <el-table row-key="id" :loading="loading" :data="renderData" border>
        <el-table-column
          prop="id"
          :label="$t('systemRole.columns.id')"
          width="80"
        />
        <el-table-column
          prop="name"
          :label="$t('systemRole.columns.name')"
          width="150"
        />
        <el-table-column
          prop="desc"
          :label="$t('systemRole.columns.desc')"
          show-overflow-tooltip
        />
        <el-table-column
          prop="num"
          :label="$t('systemRole.columns.num')"
          width="120"
        />
        <el-table-column
          prop="status"
          :label="$t('systemRole.columns.status')"
          width="100"
        >
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'">
              {{ $t(`systemRole.status.${row.status}`) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('systemRole.columns.createTime')"
          width="180"
        >
          <template #default="{ row }">{{
            formatTime(row.create_time)
          }}</template>
        </el-table-column>
        <el-table-column
          :label="$t('systemRole.columns.operations')"
          width="220"
          fixed="right"
        >
          <template #default="{ row }">
            <el-space v-if="row.status === 'active'">
              <el-button
                v-permission="['role/edit']"
                link
                size="small"
                @click="handleEdit(row)"
                >{{ $t('systemRole.operation.edit') }}</el-button
              >
              <el-button
                v-permission="['core.role.permission.assign']"
                link
                size="small"
                @click="handleAuth(row)"
                >{{ $t('systemRole.operation.permission') }}</el-button
              >
              <el-popconfirm
                :title="$t('systemRole.delete.confirm')"
                @confirm="handleDelete(row)"
              >
                <template #reference
                  ><el-button
                    v-permission="['role/delete']"
                    link
                    type="danger"
                    size="small"
                    >{{ $t('systemRole.operation.delete') }}</el-button
                  ></template
                >
              </el-popconfirm>
            </el-space>
            <span v-else>—</span>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-model:current-page="pagination.current"
        v-model:page-size="pagination.pageSize"
        :total="pagination.total"
        :page-sizes="[10, 15, 30, 50]"
        layout="total, sizes, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="onPageChange"
        @size-change="onPageSizeChange"
      />
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="
        isEdit
          ? $t('systemRole.modal.editTitle')
          : $t('systemRole.modal.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="name" :label="$t('systemRole.field.name')">
          <el-input
            v-model="form.name"
            :placeholder="$t('systemRole.field.name.placeholder')"
            clearable
          />
        </el-form-item>
        <el-form-item prop="desc" :label="$t('systemRole.field.desc')">
          <el-input
            type="textarea"
            v-model="form.desc"
            :placeholder="$t('systemRole.field.desc.placeholder')"
            :autosize="{ minRows: 4, maxRows: 6 }"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="modalVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="submitLoading"
          @click="handleSubmit"
          >保存</el-button
        ></template
      >
    </el-dialog>

    <el-dialog
      v-model="authVisible"
      :title="$t('systemRole.modal.authTitle')"
      :close-on-click-modal="false"
      width="560px"
    >
      <div v-loading="authLoading" style="width: 100%">
        <h4>{{ $t('systemRole.auth.menuPermissions') }}</h4>
        <div class="auth-toolbar">
          <el-checkbox
            :model-value="expandedKeys.length > 0"
            @change="handleExpandAll"
          >
            {{ $t('systemRole.auth.expand') }}
          </el-checkbox>
          <el-checkbox :model-value="isAllChecked" @change="handleSelectAll">
            {{ $t('systemRole.auth.selectAll') }}
          </el-checkbox>
          <el-checkbox v-model="parentLinked" @change="handleLinkageChange">
            {{ $t('systemRole.auth.parentLinked') }}
          </el-checkbox>
        </div>
        <div class="auth-tree">
          <el-tree
            :key="treeRenderKey"
            :default-expanded-keys="expandedKeys"
            :default-checked-keys="authCheckedKeys"
            :data="treeData"
            node-key="menu_key"
            :props="{ label: 'name', children: 'children' }"
            show-checkbox
            check-strictly
            @node-expand="handleTreeExpand"
            @node-collapse="handleTreeCollapse"
            @check="handleTreeCheck"
          />
        </div>
        <h4>{{ $t('systemRole.auth.actionPermissions') }}</h4>
        <el-input
          v-model="permissionSearch"
          clearable
          :placeholder="$t('systemRole.auth.searchActions')"
        />
        <el-checkbox-group
          v-model="extraPermissionKeys"
          class="action-permissions"
        >
          <el-checkbox
            v-for="permission in visibleActionPermissions"
            :key="permission.key"
            :value="permission.key"
            :title="permission.key"
          >
            {{ permission.name }} <small>{{ permission.module_key }}</small>
          </el-checkbox>
        </el-checkbox-group>
      </div>
      <template #footer
        ><el-button @click="authVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="authSubmitLoading"
          :disabled="authLoading || authRoleKey === 'core.tenant-owner'"
          @click="handleAuthSubmit"
          >保存</el-button
        ></template
      >
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import { Plus, Refresh } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import { getMenuAll, type MenuRecord } from '@/api/system/menu';
  import {
    addRole,
    deleteRole,
    editRole,
    getRolePermissions,
    getRolePermissionCatalog,
    replaceRolePermissions,
    getRoleList,
    type RolePermissionWrite,
    type RolePermissionOption,
    type RoleBaseForm,
    type RoleRecord,
  } from '@/api/system/role';

  type CheckboxValue = boolean | Array<string | number | boolean>;
  type AuthCheckEvent = {
    checked?: boolean;
    node?: MenuRecord & { id?: number };
  };
  type AuthTreeCheckedInfo = {
    checked?: boolean;
    checkedKeys: Array<number | string>;
  };

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<RoleRecord[]>([]);

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
    showPageSize: true,
  });

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const { data } = await getRoleList({
        page_no: page,
        page_size: pagination.pageSize,
      });
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };

  const onPageChange = (current: number) => fetchData(current);
  const onPageSizeChange = (pageSize: number) => {
    pagination.pageSize = pageSize;
    fetchData(1);
  };

  const formatTime = (value?: number | string) => {
    if (!value) return '-';
    if (typeof value === 'number') {
      return new Date(value * 1000).toLocaleString();
    }
    return value;
  };

  fetchData();

  // ---- 新增/编辑基本信息 ----
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();

  const defaultForm = (): RoleBaseForm => ({
    id: undefined,
    name: '',
    desc: '',
  });
  const form = reactive<RoleBaseForm>(defaultForm());

  const rules = {
    name: [{ required: true, message: t('systemRole.field.name.required') }],
  };

  const resetForm = (patch: Partial<RoleBaseForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
  };

  const handleAdd = () => {
    isEdit.value = false;
    resetForm();
    modalVisible.value = true;
  };

  const handleEdit = (record: RoleRecord) => {
    isEdit.value = true;
    resetForm({
      id: record.id,
      name: record.name,
      desc: record.desc,
    });
    modalVisible.value = true;
  };

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return false;

    submitLoading.value = true;
    try {
      const payload: RoleBaseForm = {
        id: form.id,
        name: form.name,
        desc: form.desc,
      };
      if (isEdit.value) {
        await editRole(payload);
      } else {
        await addRole(payload);
      }
      ElMessage.success(t('systemRole.tip.success'));
      modalVisible.value = false;
      await fetchData(pagination.current);
      return true;
    } catch {
      // The shared Axios interceptor already shows the server error; keep the form open and its values intact.
      return false;
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: RoleRecord) => {
    await deleteRole(record.id);
    ElMessage.success(t('systemRole.tip.success'));
    await fetchData(pagination.current);
  };

  // ---- 独立分配权限 ----
  const authVisible = ref(false);
  const authLoading = ref(false);
  const authSubmitLoading = ref(false);
  const menuTree = ref<MenuRecord[]>([]);
  const treeData = computed(() => menuTree.value);
  const parentLinked = ref(true);
  const expandedKeys = ref<string[]>([]);
  // These keys represent explicit grants, never inferred ancestor display state.
  const authCheckedKeys = ref<string[]>([]);
  const authForm = reactive<RolePermissionWrite>({
    role_id: 0,
    expected_revision: '',
    permission_keys: [],
  });
  const authRoleKey = ref('');
  const permissionCatalog = ref<RolePermissionOption[]>([]);
  const extraPermissionKeys = ref<string[]>([]);
  const permissionSearch = ref('');
  let authGeneration = 0;

  watch(
    authVisible,
    (visible) => {
      if (!visible) {
        // Invalidate synchronously even if the same role reopens this tick.
        authGeneration += 1;
        authLoading.value = false;
        authSubmitLoading.value = false;
      }
    },
    { flush: 'sync' }
  );
  onBeforeUnmount(() => {
    authGeneration += 1;
  });

  // Element Plus default state is initialization-only.
  const treeRenderKey = computed(
    () =>
      `${parentLinked.value}-${expandedKeys.value.join(
        ','
      )}-${authCheckedKeys.value.join(',')}`
  );

  const handleTreeExpand = (data: MenuRecord) => {
    const id = data.menu_key;
    if (!expandedKeys.value.includes(id)) {
      expandedKeys.value = [...expandedKeys.value, id];
    }
  };

  const handleTreeCollapse = (data: MenuRecord) => {
    const id = data.menu_key;
    expandedKeys.value = expandedKeys.value.filter((key) => key !== id);
  };

  const allMenuIds = computed(() => {
    const ids: string[] = [];
    const visit = (nodes: MenuRecord[]) => {
      nodes.forEach((node) => {
        ids.push(node.menu_key);
        if (node.children?.length) visit(node.children);
      });
    };
    visit(menuTree.value);
    return ids;
  });

  const menuNodeMap = computed(() => {
    const nodes = new Map<string, MenuRecord>();
    const visit = (items: MenuRecord[]) => {
      items.forEach((item) => {
        nodes.set(item.menu_key, item);
        if (item.children?.length) visit(item.children);
      });
    };
    visit(menuTree.value);
    return nodes;
  });

  const menuPermissionKeys = computed(
    () =>
      new Set(
        [...menuNodeMap.value.values()]
          .map((node) => node.perms)
          .filter(Boolean)
      )
  );
  const visibleActionPermissions = computed(() => {
    const options = permissionCatalog.value.filter(
      (item) => !menuPermissionKeys.value.has(item.key)
    );
    const available = new Set(options.map((item) => item.key));
    extraPermissionKeys.value.forEach((key) => {
      if (!available.has(key))
        options.push({
          key,
          name: t('systemRole.auth.unavailable'),
          module_key: key,
        });
    });
    const search = permissionSearch.value.trim().toLowerCase();
    return options.filter((item) =>
      `${item.name} ${item.key}`.toLowerCase().includes(search)
    );
  });

  const isAllChecked = computed(
    () =>
      allMenuIds.value.length > 0 &&
      authCheckedKeys.value.length === allMenuIds.value.length
  );

  const orderKeys = (keys: Set<string>) =>
    allMenuIds.value.filter((id) => keys.has(id));

  const loadMenuTree = async () => {
    const { data } = await getMenuAll();
    return data;
  };

  const loadPermissionCatalog = async () => {
    const items: RolePermissionOption[] = [];
    const readPage = async (page: number): Promise<void> => {
      const { data } = await getRolePermissionCatalog({
        page_no: page,
        page_size: 100,
      });
      if (data.items.length === 0 && items.length < data.total)
        throw new Error(t('systemRole.auth.catalogIncomplete'));
      items.push(...data.items);
      if (items.length < data.total) return readPage(page + 1);
      return undefined;
    };
    await readPage(1);
    return items;
  };

  const handleAuth = async (record: RoleRecord) => {
    authGeneration += 1;
    const generation = authGeneration;
    authVisible.value = true;
    authLoading.value = true;
    authSubmitLoading.value = false;
    Object.assign(authForm, {
      role_id: record.id,
      expected_revision: '',
      permission_keys: [],
    });
    authRoleKey.value = '';
    menuTree.value = [];
    permissionCatalog.value = [];
    authCheckedKeys.value = [];
    extraPermissionKeys.value = [];
    const isCurrent = () => generation === authGeneration && authVisible.value;
    try {
      const [{ data }, menus, catalog] = await Promise.all([
        getRolePermissions(record.id),
        loadMenuTree(),
        loadPermissionCatalog(),
      ]);
      if (!isCurrent()) return;
      if (String(data.role_id) !== String(record.id))
        throw new Error('Role permission response target changed');
      menuTree.value = menus;
      permissionCatalog.value = catalog;
      const permissions = new Set(data.permission_keys);
      const menuIds = [...menuNodeMap.value.values()]
        .filter((node) => node.perms && permissions.has(node.perms))
        .map((node) => node.menu_key);
      Object.assign(authForm, {
        role_id: data.role_id,
        expected_revision: data.revision,
        permission_keys: [...data.permission_keys],
      });
      authRoleKey.value = data.key;
      extraPermissionKeys.value = data.permission_keys.filter(
        (key) => !menuPermissionKeys.value.has(key)
      );
      permissionSearch.value = '';
      parentLinked.value = true;
      expandedKeys.value = [];
      authCheckedKeys.value = orderKeys(new Set(menuIds));
    } catch (error) {
      // Helpers return local data, so an obsolete response/error cannot mutate
      // the active role, its menus, catalog, visibility or loading indicators.
      if (isCurrent()) {
        authVisible.value = false;
        ElMessage.error(
          error instanceof Error
            ? error.message
            : t('systemRole.auth.catalogIncomplete')
        );
      }
    } finally {
      if (isCurrent()) authLoading.value = false;
    }
  };

  const setSubtreeChecked = (
    node: MenuRecord,
    checked: boolean,
    checkedSet: Set<string>
  ) => {
    if (checked) checkedSet.add(node.menu_key);
    else checkedSet.delete(node.menu_key);
    node.children?.forEach((child) =>
      setSubtreeChecked(child, checked, checkedSet)
    );
  };

  const handleAuthCheck = (
    rawCheckedKeys: Array<number | string>,
    event: AuthCheckEvent
  ) => {
    if (authLoading.value || authSubmitLoading.value) return;
    const keys = rawCheckedKeys.map(String);
    if (!parentLinked.value) {
      authCheckedKeys.value = orderKeys(new Set(keys));
      return;
    }

    const node = menuNodeMap.value.get(event.node?.menu_key ?? '');
    if (!node) return;
    const checkedSet = new Set(authCheckedKeys.value);
    const checked = event.checked ?? keys.includes(node.menu_key);
    // Parents own permission keys. Only an explicit parent gesture cascades
    // into its subtree; checking children never adds an ancestor's permission.
    setSubtreeChecked(node, checked, checkedSet);
    authCheckedKeys.value = orderKeys(checkedSet);
  };

  const handleTreeCheck = (data: MenuRecord, checked: AuthTreeCheckedInfo) => {
    handleAuthCheck(checked.checkedKeys, {
      checked: checked.checked,
      node: data,
    });
  };

  const handleLinkageChange = (value: CheckboxValue) => {
    // A display/interaction preference cannot change the canonical grant set.
    parentLinked.value = value === true;
  };

  const handleExpandAll = (value: CheckboxValue) => {
    expandedKeys.value = value === true ? [...allMenuIds.value] : [];
  };

  const handleSelectAll = (value: CheckboxValue) => {
    if (authLoading.value || authSubmitLoading.value) return;
    authCheckedKeys.value = value === true ? [...allMenuIds.value] : [];
  };

  const handleAuthSubmit = async () => {
    if (
      !authVisible.value ||
      authLoading.value ||
      authSubmitLoading.value ||
      !authForm.expected_revision ||
      authRoleKey.value === 'core.tenant-owner'
    )
      return false;
    const generation = authGeneration;
    const isCurrent = () => generation === authGeneration && authVisible.value;
    const menuIds = orderKeys(new Set(authCheckedKeys.value));
    authSubmitLoading.value = true;
    try {
      await replaceRolePermissions({
        role_id: authForm.role_id,
        expected_revision: authForm.expected_revision,
        permission_keys: [
          ...new Set([
            ...menuIds
              .map((key) => menuNodeMap.value.get(key)?.perms)
              .filter((key): key is string => Boolean(key)),
            ...extraPermissionKeys.value,
          ]),
        ],
      });
      if (!isCurrent()) return false;
      ElMessage.success(t('systemRole.tip.success'));
      authVisible.value = false;
      await fetchData(pagination.current);
      return true;
    } catch {
      return false;
    } finally {
      if (isCurrent()) authSubmitLoading.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemRole',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .auth-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 12px;
  }

  .auth-tree {
    height: 240px;
    padding: 8px 12px;
    overflow: auto;
    border: 1px solid var(--color-neutral-3);
    border-radius: 4px;
  }

  .action-permissions {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    max-height: 240px;
    overflow: auto;
    margin-top: 8px;
  }
</style>
