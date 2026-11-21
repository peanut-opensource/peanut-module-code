<template>
  <div class="container">
    <Breadcrumb :items="['menu.member', 'menu.member.tag']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.member.tag') }}</template>
      <CrudPage
        :loading="loading"
        :data="renderData"
        :pagination="pagination"
        :fields="fields"
        column-storage-key="peanut-member-tag"
        pagination-layout="total"
        row-key="id"
        border
      >
        <template #toolbar>
          <el-row v-if="canAdd">
            <el-col :span="12">
              <el-button type="primary" @click="handleAdd">
                <template #icon><Plus /></template>
                {{ $t('memberTag.operation.add') }}
              </el-button>
            </el-col>
          </el-row>
        </template>
        <template #columns>
          <el-table-column
            v-if="canEdit || canDelete"
            :label="$t('memberTag.columns.operations')"
            width="160"
          >
            <template #default="{ row }">
              <el-space>
                <el-button
                  v-if="canEdit"
                  link
                  size="small"
                  @click="handleEdit(row)"
                >
                  {{ $t('memberTag.operation.edit') }}
                </el-button>
                <el-popconfirm
                  v-if="canDelete"
                  :title="$t('memberTag.delete.confirm')"
                  @confirm="handleDelete(row)"
                >
                  <template #reference>
                    <el-button
                      link
                      type="danger"
                      size="small"
                      :disabled="deleting"
                    >
                      {{ $t('memberTag.operation.delete') }}
                    </el-button>
                  </template>
                </el-popconfirm>
              </el-space>
            </template>
          </el-table-column>
        </template>
      </CrudPage>
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="
        mode === 'edit'
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
  import { computed } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useRoute } from 'vue-router';
  import { Plus } from '@element-plus/icons-vue';
  import { ElMessage } from 'element-plus';
  import { hasPermission } from '@/hooks/permission';
  import CrudPage from '@/components/crud/CrudPage.vue';
  import useCrudModal from '@/hooks/useCrudModal';
  import useCrudTable from '@/hooks/useCrudTable';
  import {
    getMemberTagList,
    addMemberTag,
    editMemberTag,
    deleteMemberTag,
    type MemberTagRecord,
    type MemberTagForm,
  } from '@/modules/peanut-member/api';

  const { t } = useI18n();
  const route = useRoute();
  const canAdd = computed(() =>
    hasPermission('peanut.member.tag.add', route.path)
  );
  const canEdit = computed(() =>
    hasPermission('peanut.member.tag.edit', route.path)
  );
  const canDelete = computed(() =>
    hasPermission('peanut.member.tag.delete', route.path)
  );

  const fields = computed(() => [
    { key: 'name', label: t('memberTag.columns.name') },
    { key: 'remark', label: t('memberTag.columns.remark') },
  ]);

  const {
    data: renderData,
    pagination,
    loading,
    deleting,
    fetchList,
    handleDelete: remove,
  } = useCrudTable<MemberTagRecord, Record<string, never>>({
    initialFilters: {},
    initialPageSize: 1,
    load: async ({ signal }) => {
      const { data } = await getMemberTagList(signal);
      return {
        items: data,
        total: data.length,
        page: 1,
        pageSize: Math.max(1, data.length),
      };
    },
    deleteRecord: (record, signal) => deleteMemberTag(record.id, signal),
    errorMessage: (error) =>
      error instanceof Error ? error.message : String(error),
  });
  fetchList();

  const {
    create: handleAdd,
    edit: handleEdit,
    submit: handleSubmit,
    visible: modalVisible,
    form,
    formRef,
    mode,
    submitLoading,
  } = useCrudModal<MemberTagForm, MemberTagRecord>({
    canOpen: (intent) => {
      if (intent === 'view') return false;
      return intent === 'create' ? canAdd.value : canEdit.value;
    },
    createForm: () => ({ name: '', remark: '' }),
    editForm: ({ id, name, remark }) => ({ id, name, remark }),
    save: (values, intent, signal) =>
      intent === 'edit'
        ? editMemberTag(values, signal)
        : addMemberTag(values, signal),
    onSaved: async () => {
      ElMessage.success(t('memberTag.tip.success'));
      await fetchList();
    },
  });

  const rules = {
    name: [{ required: true, message: t('memberTag.field.name.required') }],
  };

  const handleDelete = async (record: MemberTagRecord) => {
    if (canDelete.value && (await remove(record))) {
      ElMessage.success(t('memberTag.tip.success'));
    }
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
