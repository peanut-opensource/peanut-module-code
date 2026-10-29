<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.dict']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.dict') }}</template>
      <el-row>
        <el-col :span="18">
          <el-form :model="formModel" label-position="left">
            <el-row :gutter="16">
              <el-col :span="8">
                <el-form-item prop="name" :label="$t('systemDict.form.name')">
                  <el-input
                    v-model="formModel.name"
                    clearable
                    :placeholder="$t('systemDict.form.name.placeholder')"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item prop="type" :label="$t('systemDict.form.type')">
                  <el-input
                    v-model="formModel.type"
                    clearable
                    :placeholder="$t('systemDict.form.type.placeholder')"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item
                  prop="is_disable"
                  :label="$t('systemDict.form.status')"
                >
                  <el-select
                    v-model="formModel.is_disable"
                    clearable
                    :placeholder="$t('systemDict.form.status.placeholder')"
                  >
                    <el-option
                      v-for="option in statusOptions"
                      :key="option.value"
                      :label="option.label"
                      :value="option.value"
                    />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-col>
        <el-divider style="height: 84px" direction="vertical" />
        <el-col :span="6" style="text-align: right">
          <el-space direction="vertical" :size="18">
            <el-button type="primary" @click="search">
              <template #icon><Search /></template>
              {{ $t('systemDict.form.search') }}
            </el-button>
            <el-button @click="reset">
              <template #icon><Refresh /></template>
              {{ $t('systemDict.form.reset') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>
      <el-divider style="margin-top: 0" />
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-button
            v-permission="['dict/type/add']"
            type="primary"
            @click="handleAdd"
          >
            <template #icon><Plus /></template>
            {{ $t('systemDict.operation.create') }}
          </el-button>
        </el-col>
      </el-row>
      <el-table row-key="id" :loading="loading" :data="renderData" border>
        <el-table-column
          prop="id"
          :label="$t('systemDict.columns.id')"
          width="80"
        />
        <el-table-column prop="name" :label="$t('systemDict.columns.name')" />
        <el-table-column prop="type" :label="$t('systemDict.columns.type')" />
        <el-table-column
          prop="remark"
          :label="$t('systemDict.columns.remark')"
        />
        <el-table-column :label="$t('systemDict.columns.status')" width="90">
          <template #default="{ row }"
            ><el-switch
              v-permission="['dict/type/status']"
              :model-value="row.is_disable === 0"
              @change="(v: string | number | boolean) => handleStatus(row, v as boolean)"
          /></template>
        </el-table-column>
        <el-table-column
          :label="$t('systemDict.columns.operations')"
          width="220"
        >
          <template #default="{ row }">
            <el-space>
              <el-button
                v-permission="['dict/data/lists']"
                link
                size="small"
                @click="openData(row)"
                >{{ $t('systemDict.operation.manageData') }}</el-button
              >
              <el-button
                v-permission="['dict/type/edit']"
                link
                size="small"
                @click="handleEdit(row)"
                >{{ $t('systemDict.operation.edit') }}</el-button
              >
              <el-popconfirm
                :title="$t('systemDict.delete.confirm')"
                @confirm="handleDelete(row)"
                ><template #reference
                  ><el-button
                    v-permission="['dict/type/delete']"
                    link
                    type="danger"
                    size="small"
                    >{{ $t('systemDict.operation.delete') }}</el-button
                  ></template
                ></el-popconfirm
              >
            </el-space>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-model:current-page="pagination.current"
        v-model:page-size="pagination.pageSize"
        :total="pagination.total"
        layout="total, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="onPageChange"
      />
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="
        isEdit
          ? $t('systemDict.modal.editTitle')
          : $t('systemDict.modal.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="name" :label="$t('systemDict.field.name')">
          <el-input
            v-model="form.name"
            :placeholder="$t('systemDict.field.name.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="type" :label="$t('systemDict.field.type')">
          <el-input
            v-model="form.type"
            :placeholder="$t('systemDict.field.type.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="remark" :label="$t('systemDict.field.remark')">
          <el-input
            type="textarea"
            v-model="form.remark"
            :placeholder="$t('systemDict.field.remark.placeholder')"
            maxlength="255"
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

    <DataDrawer
      v-model:visible="drawerVisible"
      :type-id="currentType.id"
      :type-name="currentType.name"
    />
  </div>
</template>

<script lang="ts" setup>
  import { computed, ref, reactive } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import { Plus, Refresh, Search } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getDictTypeList,
    addDictType,
    editDictType,
    deleteDictType,
    updateDictTypeStatus,
    type DictTypeRecord,
    type DictTypeForm,
    type DictTypeListParams,
  } from '@/api/system/dict';
  import DataDrawer from './components/DataDrawer.vue';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<DictTypeRecord[]>([]);

  const generateFormModel = () => ({
    name: '',
    type: '',
    is_disable: '' as number | '',
  });
  const formModel = ref(generateFormModel());

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
  });

  const statusOptions = computed(() => [
    { label: t('systemDict.status.enabled'), value: 0 },
    { label: t('systemDict.status.disabled'), value: 1 },
  ]);

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const params: DictTypeListParams = {
        ...formModel.value,
        page_no: page,
        page_size: pagination.pageSize,
      };
      const { data } = await getDictTypeList(params);
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const search = () => fetchData(1);
  const onPageChange = (current: number) => fetchData(current);
  const reset = () => {
    formModel.value = generateFormModel();
    fetchData(1);
  };

  // ---- 数据抽屉 ----
  const drawerVisible = ref(false);
  const currentType = reactive({ id: 0, name: '' });
  const openData = (record: DictTypeRecord) => {
    currentType.id = record.id;
    currentType.name = record.name;
    drawerVisible.value = true;
  };

  // ---- 弹窗 & 表单 ----
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();

  const defaultForm = (): DictTypeForm => ({
    id: undefined,
    name: '',
    type: '',
    is_disable: 0,
    remark: '',
  });
  const form = reactive<DictTypeForm>(defaultForm());

  const rules = {
    name: [{ required: true, message: t('systemDict.field.name.required') }],
    type: [{ required: true, message: t('systemDict.field.type.required') }],
  };

  const resetForm = (patch: Partial<DictTypeForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
  };

  const handleAdd = () => {
    isEdit.value = false;
    resetForm();
    modalVisible.value = true;
  };

  const handleEdit = (record: DictTypeRecord) => {
    isEdit.value = true;
    resetForm({ ...record });
    modalVisible.value = true;
  };

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    submitLoading.value = true;
    try {
      if (isEdit.value) {
        await editDictType(form);
      } else {
        await addDictType(form);
      }
      ElMessage.success(t('systemDict.tip.success'));
      modalVisible.value = false;
      await fetchData(pagination.current);
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: DictTypeRecord) => {
    await deleteDictType(record.id);
    ElMessage.success(t('systemDict.tip.success'));
    await fetchData(pagination.current);
  };

  const handleStatus = async (record: DictTypeRecord, enabled: boolean) => {
    await updateDictTypeStatus(record.id, enabled ? 0 : 1);
    record.is_disable = enabled ? 0 : 1;
    ElMessage.success(t('systemDict.tip.success'));
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemDict',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
