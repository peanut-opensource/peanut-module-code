<template>
  <div class="container">
    <Breadcrumb :items="['menu.richText', 'menu.richText.documents']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.richText.documents') }}</template>
      <el-alert
        v-if="
          listAction.error.value ||
          (documentAction.error.value && !dialogVisible)
        "
        type="error"
        :title="listAction.error.value || documentAction.error.value || ''"
        :closable="false"
      />
      <el-form inline @submit.prevent="fetchData(1)">
        <el-form-item :label="$t('richText.search.title')">
          <el-input v-model="keyword" clearable />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="loading" @click="fetchData(1)">{{
            $t('richText.action.search')
          }}</el-button>
          <el-button @click="reset">{{
            $t('richText.action.reset')
          }}</el-button>
        </el-form-item>
      </el-form>
      <el-button
        v-permission="['peanut.rich-text.document.add']"
        type="primary"
        :disabled="documentAction.loading.value"
        style="margin-bottom: 16px"
        @click="openAdd"
        >{{ $t('richText.action.add') }}</el-button
      >
      <el-table v-loading="loading" :data="documents" row-key="id" border>
        <el-table-column prop="title" :label="$t('richText.column.title')" />
        <el-table-column
          prop="revision"
          :label="$t('richText.column.revision')"
          width="100"
        />
        <el-table-column :label="$t('richText.column.updatedAt')" width="190">
          <template #default="{ row }">{{
            formatTime(row.update_time)
          }}</template>
        </el-table-column>
        <el-table-column
          :label="$t('richText.column.operations')"
          width="150"
          fixed="right"
        >
          <template #default="{ row }">
            <el-button
              v-permission="['peanut.rich-text.document.edit']"
              link
              type="primary"
              :disabled="documentAction.loading.value"
              @click="openEdit(row)"
              >{{ $t('richText.action.edit') }}</el-button
            >
            <el-popconfirm
              :title="$t('richText.confirm.delete')"
              @confirm="remove(row.id)"
            >
              <template #reference>
                <el-button
                  v-permission="['peanut.rich-text.document.delete']"
                  link
                  type="danger"
                  :disabled="documentAction.loading.value"
                  >{{ $t('richText.action.delete') }}</el-button
                >
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrapper">
        <el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? $t('richText.dialog.edit') : $t('richText.dialog.add')"
      width="min(1000px, 92vw)"
      destroy-on-close
      :close-on-click-modal="!documentAction.loading.value"
      :close-on-press-escape="!documentAction.loading.value"
      :show-close="!documentAction.loading.value"
      @closed="closeDialog"
    >
      <el-alert
        v-if="documentAction.error.value"
        type="error"
        :title="documentAction.error.value"
        :closable="false"
      />
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="title" :label="$t('richText.form.title')">
          <el-input v-model="form.title" maxlength="200" show-word-limit />
        </el-form-item>
        <el-form-item :label="$t('richText.form.content')">
          <RichTextEditor
            :key="editorKey"
            v-model="form.document"
            v-model:collaboration-state="form.collaboration_state"
            :collaboration="collaboration"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="documentAction.loading.value" @click="closeDialog"
          >取消</el-button
        >
        <el-button
          type="primary"
          :loading="documentAction.loading.value"
          @click="save"
          >保存</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref } from 'vue';
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
  import { useAsyncAction } from '@peanut-admin/vue';
  import { RichTextEditor } from '../src/editor';
  import { emptyDocument, type RichTextDocumentValue } from '../src/document';
  import type { RichTextCollaborationConfig } from '../components/types';
  import {
    addRichTextDocument,
    deleteRichTextDocument,
    editRichTextDocument,
    getRichTextCollaboration,
    getRichTextDocument,
    getRichTextDocuments,
    type RichTextDocumentRecord,
  } from '../api';

  interface DocumentForm {
    id: number;
    title: string;
    revision: number;
    document: RichTextDocumentValue;
    collaboration_state: string;
  }

  const blankForm = (): DocumentForm => ({
    id: 0,
    title: '',
    revision: 0,
    document: emptyDocument(),
    collaboration_state: '',
  });
  const listAction = useAsyncAction(() => '文档列表加载失败，请重试');
  const documentAction = useAsyncAction(() => '文档操作失败，请重试');
  const { loading } = listAction;
  const documents = ref<RichTextDocumentRecord[]>([]);
  const keyword = ref('');
  const pagination = reactive({ current: 1, pageSize: 15, total: 0 });
  const dialogVisible = ref(false);
  const formRef = ref<FormInstance>();
  const form = ref<DocumentForm>(blankForm());
  const collaboration = ref<RichTextCollaborationConfig | null>(null);
  const editorKey = ref('new');
  const rules: FormRules = {
    title: [
      { required: true, max: 200, message: '请输入不超过 200 个字符的标题' },
    ],
  };

  const fetchData = async (page = 1) => {
    listAction.cancel();
    const result = await listAction.run(({ signal }) =>
      getRichTextDocuments(
        {
          title: keyword.value || undefined,
          page_no: page,
          page_size: pagination.pageSize,
        },
        signal
      )
    );
    if (result.status !== 'completed') return;
    const { data } = result.data;
    documents.value = data.lists;
    pagination.current = data.pageNo;
    pagination.pageSize = data.pageSize;
    pagination.total = data.count;
  };

  const reset = () => {
    keyword.value = '';
    fetchData(1);
  };

  const openAdd = () => {
    if (documentAction.loading.value) return;
    documentAction.cancel();
    form.value = blankForm();
    collaboration.value = null;
    editorKey.value = `new-${Date.now()}`;
    dialogVisible.value = true;
  };

  const openEdit = async (row: RichTextDocumentRecord) => {
    if (documentAction.loading.value) return;
    const result = await documentAction.run(({ signal }) =>
      Promise.all([
        getRichTextDocument(row.id, signal),
        getRichTextCollaboration(row.id, signal),
      ])
    );
    if (result.status !== 'completed') return;
    const [{ data }, { data: collaborationData }] = result.data;
    form.value = {
      id: data.id,
      title: data.title,
      revision: data.revision,
      document: data.document || emptyDocument(),
      collaboration_state: data.collaboration_state || '',
    };
    collaboration.value = collaborationData;
    editorKey.value = `document-${data.id}-${data.revision}`;
    dialogVisible.value = true;
  };

  const save = async () => {
    if (documentAction.loading.value) return;
    const result = await documentAction.run(async ({ signal }) => {
      const valid = await formRef.value?.validate().catch(() => false);
      if (!valid) return false;
      const {
        id,
        revision,
        title,
        document,
        collaboration_state: collaborationState,
      } = form.value;
      const payload = {
        title,
        document,
        collaboration_state: collaborationState,
      };
      if (id) await editRichTextDocument({ id, revision, ...payload }, signal);
      else await addRichTextDocument(payload, signal);
      return true;
    });
    if (result.status !== 'completed' || !result.data) return;
    ElMessage.success('保存成功');
    dialogVisible.value = false;
    await fetchData(pagination.current);
  };

  const remove = async (id: number) => {
    const result = await documentAction.run(({ signal }) =>
      deleteRichTextDocument(id, signal)
    );
    if (result.status !== 'completed') return;
    ElMessage.success('删除成功');
    await fetchData(pagination.current);
  };

  const closeDialog = () => {
    if (documentAction.loading.value) return;
    documentAction.cancel();
    dialogVisible.value = false;
  };

  const formatTime = (value: string) => value || '-';

  fetchData();
</script>

<script lang="ts">
  export default { name: 'RichTextDocuments' };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }
  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
