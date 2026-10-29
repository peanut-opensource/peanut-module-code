<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.menu']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.menu') }}</template>
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-space>
            <el-button type="primary" @click="handleAdd()">
              <template #icon><Plus /></template>
              {{ $t('systemMenu.operation.create') }}
            </el-button>
            <el-button @click="fetchData">
              <template #icon><Refresh /></template>
              {{ $t('systemMenu.operation.refresh') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>
      <el-table
        row-key="menu_key"
        :loading="loading"
        :data="renderData"
        border
        default-expand-all
        :tree-props="{ children: 'children' }"
      >
        <el-table-column prop="menu_key" label="菜单标识" min-width="180" />
        <el-table-column label="来源 / 所属模块" min-width="180">
          <template #default="{ row }">
            <el-tag>{{
              row.source === 'module'
                ? '模块贡献'
                : row.source === 'system'
                ? '系统菜单'
                : '自建菜单'
            }}</el-tag>
            <div>{{ row.module_key }}</div>
            <el-tag v-if="row.status === 'retired'" type="info"
              >模块已退出</el-tag
            >
          </template>
        </el-table-column>
        <el-table-column prop="name" :label="$t('systemMenu.columns.name')" />
        <el-table-column :label="$t('systemMenu.columns.type')" width="90">
          <template #default="{ row }">
            <el-tag :type="typeColor[row.type as MenuType]">{{
              $t(`systemMenu.type.${row.type}`)
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column
          prop="icon"
          :label="$t('systemMenu.columns.icon')"
          width="140"
        />
        <el-table-column prop="perms" :label="$t('systemMenu.columns.perms')" />
        <el-table-column
          prop="sort"
          :label="$t('systemMenu.columns.sort')"
          width="80"
        />
        <el-table-column :label="$t('systemMenu.columns.status')" width="90">
          <template #default="{ row }">
            <el-switch
              :model-value="row.status === 'active' && row.is_disable === 0"
              :disabled="row.status !== 'active'"
              @change="(v: string | number | boolean) => handleStatus(row, v as boolean)"
            />
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('systemMenu.columns.operations')"
          width="220"
        >
          <template #default="{ row }">
            <el-space>
              <el-button
                v-if="row.type !== 'A'"
                link
                size="small"
                @click="handleAdd(row)"
                >{{ $t('systemMenu.operation.addChild') }}</el-button
              >
              <el-button link size="small" @click="handleEdit(row)">{{
                $t('systemMenu.operation.edit')
              }}</el-button>
              <el-popconfirm
                v-if="!row.managed"
                :title="$t('systemMenu.delete.confirm')"
                @confirm="handleDelete(row)"
              >
                <template #reference>
                  <el-button link type="danger" size="small">{{
                    $t('systemMenu.operation.delete')
                  }}</el-button>
                </template>
              </el-popconfirm>
            </el-space>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="
        isEdit
          ? $t('systemMenu.modal.editTitle')
          : $t('systemMenu.modal.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="type" :label="$t('systemMenu.field.type')">
          <el-radio-group v-model="form.type" :disabled="form.managed">
            <el-radio-button label="M">{{
              $t('systemMenu.type.M')
            }}</el-radio-button>
            <el-radio-button label="C">{{
              $t('systemMenu.type.C')
            }}</el-radio-button>
            <el-radio-button label="A">{{
              $t('systemMenu.type.A')
            }}</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item prop="parent_key" :label="$t('systemMenu.field.pid')">
          <el-tree-select
            v-model="form.parent_key"
            :data="parentTree"
            :props="{ value: 'menu_key', label: 'name', children: 'children' }"
            :placeholder="$t('systemMenu.field.pid.placeholder')"
            clearable
          />
        </el-form-item>
        <el-form-item prop="name" :label="$t('systemMenu.field.name')">
          <el-input
            v-model="form.name"
            :placeholder="$t('systemMenu.field.name.placeholder')"
          />
        </el-form-item>
        <el-form-item
          v-if="form.type !== 'A'"
          prop="icon"
          :label="$t('systemMenu.field.icon')"
        >
          <el-input
            v-model="form.icon"
            :placeholder="$t('systemMenu.field.icon.placeholder')"
          />
        </el-form-item>
        <el-form-item
          v-if="form.type !== 'M'"
          prop="paths"
          :label="$t('systemMenu.field.paths')"
        >
          <el-input
            v-model="form.paths"
            :disabled="form.managed"
            :placeholder="$t('systemMenu.field.paths.placeholder')"
          />
        </el-form-item>
        <el-form-item
          v-if="form.type === 'C'"
          prop="component"
          :label="$t('systemMenu.field.component')"
        >
          <el-input
            v-model="form.component"
            :disabled="form.managed"
            :placeholder="$t('systemMenu.field.component.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="perms" :label="$t('systemMenu.field.perms')">
          <el-input
            v-model="form.perms"
            :disabled="form.managed"
            :placeholder="$t('systemMenu.field.perms.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="sort" :label="$t('systemMenu.field.sort')">
          <el-input-number v-model="form.sort" :min="0" style="width: 160px" />
        </el-form-item>
        <el-form-item :label="$t('systemMenu.field.flags')">
          <el-space size="large">
            <el-checkbox v-model="showChecked">
              {{ $t('systemMenu.field.isShow') }}
            </el-checkbox>
            <el-checkbox v-model="cacheChecked">
              {{ $t('systemMenu.field.isCache') }}
            </el-checkbox>
          </el-space>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitLoading" @click="handleSubmit"
          >保存</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, ref, reactive } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance, TagProps } from 'element-plus';
  import { Plus, Refresh } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getMenuList,
    addMenu,
    editMenu,
    deleteMenu,
    updateMenuStatus,
    type MenuRecord,
    type MenuForm,
    type MenuType,
  } from '@/api/system/menu';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<MenuRecord[]>([]);

  const typeColor: Record<MenuType, TagProps['type']> = {
    M: 'primary',
    C: 'success',
    A: 'info',
  };

  const fetchData = async () => {
    setLoading(true);
    try {
      const { data } = await getMenuList();
      renderData.value = data;
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  // ---- 上级菜单选择树：只允许挂在 M/C 下，按钮(A)不能当父级 ----
  interface MenuParentOption {
    menu_key: string | null;
    name: string;
    children: MenuParentOption[];
  }

  const parentTree = computed<MenuParentOption[]>(() => {
    const strip = (nodes: MenuRecord[]): MenuParentOption[] =>
      nodes
        .filter(
          (n) =>
            n.type !== 'A' &&
            (!form.managed ||
              form.source !== 'module' ||
              (n.source === 'module' && n.type === 'M') ||
              n.menu_key === form.parent_key)
        )
        .map((n) => ({
          menu_key: n.menu_key,
          name: n.name,
          children: n.children ? strip(n.children) : [],
        }));
    return [
      {
        menu_key: null,
        name: t('systemMenu.field.pid.root'),
        children: strip(renderData.value),
      },
    ];
  });

  // ---- 弹窗 & 表单 ----
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();

  const defaultForm = (): MenuForm => ({
    id: undefined,
    parent_key: null,
    managed: false,
    type: 'C',
    name: '',
    icon: '',
    sort: 0,
    perms: '',
    paths: '',
    component: '',
    is_cache: 0,
    is_show: 1,
    is_disable: 0,
  });
  const form = reactive<MenuForm>(defaultForm());

  // 布尔勾选 ↔ 后端 0/1 映射
  const showChecked = computed({
    get: () => form.is_show === 1,
    set: (v: boolean) => {
      form.is_show = v ? 1 : 0;
    },
  });
  const cacheChecked = computed({
    get: () => form.is_cache === 1,
    set: (v: boolean) => {
      form.is_cache = v ? 1 : 0;
    },
  });

  const rules = {
    name: [{ required: true, message: t('systemMenu.field.name.required') }],
    type: [{ required: true, message: t('systemMenu.field.type.required') }],
  };

  const resetForm = (patch: Partial<MenuForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
  };

  const handleAdd = (parent?: MenuRecord) => {
    isEdit.value = false;
    resetForm({ parent_key: parent?.menu_key ?? null });
    modalVisible.value = true;
  };

  const handleEdit = (record: MenuRecord) => {
    isEdit.value = true;
    resetForm({ ...record, children: undefined });
    modalVisible.value = true;
  };

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    submitLoading.value = true;
    try {
      if (isEdit.value) {
        await editMenu(form);
      } else {
        await addMenu(form);
      }
      ElMessage.success(t('systemMenu.tip.success'));
      modalVisible.value = false;
      await fetchData();
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: MenuRecord) => {
    await deleteMenu(record.menu_key);
    ElMessage.success(t('systemMenu.tip.success'));
    await fetchData();
  };

  const handleStatus = async (record: MenuRecord, enabled: boolean) => {
    await updateMenuStatus(record.menu_key, enabled ? 0 : 1);
    record.is_disable = enabled ? 0 : 1;
    ElMessage.success(t('systemMenu.tip.success'));
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemMenu',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
