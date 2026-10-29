<template>
  <div class="container">
    <Breadcrumb :items="['menu.member', 'menu.member.tag']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.member.tag') }}</template>
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-button type="primary" @click="handleAdd">
            <template #icon><Plus /></template>
            {{ $t('memberTag.operation.add') }}
          </el-button>
        </el-col>
      </el-row>
      <el-table v-loading="loading" row-key="id" :data="renderData" border>
        <el-table-column prop="name" :label="$t('memberTag.columns.name')" />
        <el-table-column
          prop="remark"
          :label="$t('memberTag.columns.remark')"
        />
        <el-table-column
          :label="$t('memberTag.columns.operations')"
          width="160"
        >
          <template #default="{ row }">
            <el-space>
              <el-button link size="small" @click="handleEdit(row)">
                {{ $t('memberTag.operation.edit') }}
              </el-button>
              <el-popconfirm
                :title="$t('memberTag.delete.confirm')"
                @confirm="handleDelete(row)"
              >
                <template #reference>
                  <el-button link type="danger" size="small">
                    {{ $t('memberTag.operation.delete') }}
                  </el-button>
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
          ? $t('memberTag.modal.editTitle')
          : $t('memberTag.modal.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="name" :label="$t('memberTag.field.name')">
          <el-input
            v-model="form.name"
            :placeholder="$t('memberTag.field.name.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="remark" :label="$t('memberTag.field.remark')">
          <el-input
            v-model="form.remark"
            :placeholder="$t('memberTag.field.remark.placeholder')"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="modalVisible = false">
          {{ $t('userSetting.cancel') }}
        </el-button>
        <el-button
          type="primary"
          :loading="submitLoading"
          @click="handleSubmit"
        >
          {{ $t('userSetting.save') }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { ref, reactive } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { Plus } from '@element-plus/icons-vue';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import useLoading from '@/hooks/loading';
  import {
    getMemberTagList,
    addMemberTag,
    editMemberTag,
    deleteMemberTag,
    type MemberTagRecord,
    type MemberTagForm,
  } from '@/modules/peanut-member/api';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<MemberTagRecord[]>([]);

  const fetchData = async () => {
    setLoading(true);
    try {
      const { data } = await getMemberTagList();
      renderData.value = data;
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const modalVisible = ref(false);
  const isEdit = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();
  const defaultForm = (): MemberTagForm => ({
    id: undefined,
    name: '',
    remark: '',
  });
  const form = reactive<MemberTagForm>(defaultForm());
  const rules = {
    name: [{ required: true, message: t('memberTag.field.name.required') }],
  };

  const resetForm = (patch: Partial<MemberTagForm> = {}) =>
    Object.assign(form, defaultForm(), patch);
  const handleAdd = () => {
    isEdit.value = false;
    resetForm();
    modalVisible.value = true;
  };
  const handleEdit = (record: MemberTagRecord) => {
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
        await editMemberTag(form);
      } else {
        await addMemberTag(form);
      }
      ElMessage.success(t('memberTag.tip.success'));
      modalVisible.value = false;
      await fetchData();
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: MemberTagRecord) => {
    await deleteMemberTag(record.id);
    ElMessage.success(t('memberTag.tip.success'));
    await fetchData();
  };
</script>

<script lang="ts">
  export default { name: 'MemberTag' };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
