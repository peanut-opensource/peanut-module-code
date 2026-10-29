<template>
  <el-drawer
    v-model="drawerVisible"
    size="720px"
    :title="`${$t('systemDict.data.title')} - ${typeName}`"
    destroy-on-close
  >
    <el-row style="margin-bottom: 16px" justify="space-between">
      <el-col :span="12">
        <el-button
          v-permission="['dict/data/add']"
          type="primary"
          @click="handleAdd"
        >
          <template #icon><Plus /></template>
          {{ $t('systemDict.data.create') }}
        </el-button>
      </el-col>
      <el-col :span="10">
        <el-input
          v-model="keyword"
          clearable
          :placeholder="$t('systemDict.data.search.placeholder')"
          @keyup.enter="fetchData(1)"
        />
      </el-col>
    </el-row>
    <el-table row-key="id" :loading="loading" :data="renderData" border>
      <el-table-column
        prop="name"
        :label="$t('systemDict.data.columns.name')"
      />
      <el-table-column
        prop="value"
        :label="$t('systemDict.data.columns.value')"
      />
      <el-table-column
        prop="sort"
        :label="$t('systemDict.data.columns.sort')"
        width="80"
      />
      <el-table-column :label="$t('systemDict.data.columns.status')" width="80"
        ><template #default="{ row }"
          ><el-switch
            v-permission="['dict/data/status']"
            :model-value="row.is_disable === 0"
            @change="(v: string | number | boolean) => handleStatus(row, v as boolean)" /></template
      ></el-table-column>
      <el-table-column
        :label="$t('systemDict.data.columns.operations')"
        width="140"
        ><template #default="{ row }"
          ><el-space
            ><el-button
              v-permission="['dict/data/edit']"
              link
              size="small"
              @click="handleEdit(row)"
              >{{ $t('systemDict.operation.edit') }}</el-button
            ><el-popconfirm
              :title="$t('systemDict.data.delete.confirm')"
              @confirm="handleDelete(row)"
              ><template #reference
                ><el-button
                  v-permission="['dict/data/delete']"
                  link
                  type="danger"
                  size="small"
                  >{{ $t('systemDict.operation.delete') }}</el-button
                ></template
              ></el-popconfirm
            ></el-space
          ></template
        ></el-table-column
      >
    </el-table>
    <el-pagination
      v-model:current-page="pagination.current"
      v-model:page-size="pagination.pageSize"
      :total="pagination.total"
      layout="total, prev, pager, next"
      style="margin-top: 16px; justify-content: flex-end"
      @current-change="onPageChange"
    />

    <el-dialog
      v-model="modalVisible"
      :title="
        isEdit
          ? $t('systemDict.data.modal.editTitle')
          : $t('systemDict.data.modal.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="name" :label="$t('systemDict.data.field.name')">
          <el-input
            v-model="form.name"
            :placeholder="$t('systemDict.data.field.name.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="value" :label="$t('systemDict.data.field.value')">
          <el-input
            v-model="form.value"
            :placeholder="$t('systemDict.data.field.value.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="sort" :label="$t('systemDict.data.field.sort')">
          <el-input-number v-model="form.sort" :min="0" style="width: 160px" />
        </el-form-item>
        <el-form-item prop="remark" :label="$t('systemDict.data.field.remark')">
          <el-input
            type="textarea"
            v-model="form.remark"
            :placeholder="$t('systemDict.data.field.remark.placeholder')"
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
  </el-drawer>
</template>

<script lang="ts" setup>
  import { computed, ref, reactive, watch } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import { Plus } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getDictDataList,
    addDictData,
    editDictData,
    deleteDictData,
    updateDictDataStatus,
    type DictDataRecord,
    type DictDataForm,
    type DictDataListParams,
  } from '@/api/system/dict';

  const props = defineProps<{
    visible: boolean;
    typeId: number;
    typeName: string;
  }>();
  const emit = defineEmits<{ (e: 'update:visible', v: boolean): void }>();
  const drawerVisible = computed({
    get: () => props.visible,
    set: (value: boolean) => emit('update:visible', value),
  });

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(false);
  const renderData = ref<DictDataRecord[]>([]);
  const keyword = ref('');

  const pagination = reactive({
    current: 1,
    pageSize: 10,
    total: 0,
    showTotal: true,
  });

  const fetchData = async (page = 1) => {
    if (!props.typeId) return;
    setLoading(true);
    try {
      const params: DictDataListParams = {
        type_id: props.typeId,
        name: keyword.value,
        page_no: page,
        page_size: pagination.pageSize,
      };
      const { data } = await getDictDataList(params);
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };

  const onPageChange = (current: number) => fetchData(current);

  // 抽屉打开或切换类型时刷新
  watch(
    () => [props.visible, props.typeId],
    ([vis]) => {
      if (vis) {
        keyword.value = '';
        pagination.current = 1;
        fetchData(1);
      }
    }
  );

  // ---- 弹窗 & 表单 ----
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();

  const defaultForm = (): DictDataForm => ({
    id: undefined,
    name: '',
    value: '',
    sort: 0,
    is_disable: 0,
    remark: '',
  });
  const form = reactive<DictDataForm>(defaultForm());

  const rules = {
    name: [
      { required: true, message: t('systemDict.data.field.name.required') },
    ],
    value: [
      { required: true, message: t('systemDict.data.field.value.required') },
    ],
  };

  const resetForm = (patch: Partial<DictDataForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
  };

  const handleAdd = () => {
    isEdit.value = false;
    resetForm();
    modalVisible.value = true;
  };

  const handleEdit = (record: DictDataRecord) => {
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
        await editDictData(form);
      } else {
        await addDictData({ ...form, type_id: props.typeId });
      }
      ElMessage.success(t('systemDict.tip.success'));
      modalVisible.value = false;
      await fetchData(pagination.current);
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: DictDataRecord) => {
    await deleteDictData(record.id);
    ElMessage.success(t('systemDict.tip.success'));
    await fetchData(pagination.current);
  };

  const handleStatus = async (record: DictDataRecord, enabled: boolean) => {
    await updateDictDataStatus(record.id, enabled ? 0 : 1);
    record.is_disable = enabled ? 0 : 1;
    ElMessage.success(t('systemDict.tip.success'));
  };
</script>
