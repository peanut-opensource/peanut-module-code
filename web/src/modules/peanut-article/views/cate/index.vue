<template>
  <div class="container">
    <Breadcrumb :items="['menu.article', 'menu.article.cate']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.article.cate') }}</template>
      <el-alert type="warning" :closable="false" style="margin-bottom: 16px">
        {{ $t('articleCate.alert') }}
      </el-alert>
      <el-alert
        v-if="categoryList.error.value"
        type="error"
        :closable="false"
        :title="categoryList.error.value"
        style="margin-bottom: 16px"
      />
      <el-alert
        v-if="formAction.error.value && !modalVisible"
        type="error"
        :closable="false"
        :title="formAction.error.value"
        style="margin-bottom: 16px"
      />
      <el-button
        v-permission="['peanut.article.category.add']"
        type="primary"
        :icon="Plus"
        style="margin-bottom: 16px"
        @click="openAdd"
      >
        {{ $t('articleCate.button.add') }}
      </el-button>
      <el-table
        v-loading="categoryList.loading.value"
        row-key="id"
        :data="tableRows"
        border
      >
        <el-table-column
          v-for="column in articleCategoryResource.columns.slice(0, 2)"
          :key="column.key"
          :prop="column.key"
          :label="$t(column.labelKey)"
          :width="column.width"
        />
        <el-table-column :label="$t('articleCate.columns.isShow')" width="100">
          <template #default="{ row }">
            <el-switch
              v-permission="['peanut.article.category.update-status']"
              :model-value="row.is_show === 1"
              @change="(value: boolean) => onStatusChange(row, value)"
            />
          </template>
        </el-table-column>
        <el-table-column
          :prop="articleCategoryResource.columns[2].key"
          :label="$t(articleCategoryResource.columns[2].labelKey)"
          :width="articleCategoryResource.columns[2].width"
        />
        <el-table-column
          :label="$t('articleCate.columns.operations')"
          width="160"
          fixed="right"
        >
          <template #default="{ row }">
            <el-space>
              <el-button
                v-if="canEdit"
                link
                type="primary"
                size="small"
                @click="openEdit(row)"
              >
                {{ $t('articleCate.button.edit') }}
              </el-button>
              <el-popconfirm
                :title="$t('articleCate.confirm.delete')"
                @confirm="onDelete(row)"
              >
                <template #reference>
                  <el-button
                    v-permission="['peanut.article.category.delete']"
                    link
                    type="danger"
                    size="small"
                  >
                    {{ $t('articleCate.button.delete') }}
                  </el-button>
                </template>
              </el-popconfirm>
            </el-space>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrapper">
        <el-pagination
          :current-page="categoryList.pagination.page"
          :page-size="categoryList.pagination.pageSize"
          :total="categoryList.pagination.total"
          layout="total, prev, pager, next"
          @current-change="categoryList.fetchList"
        />
      </div>

      <el-dialog
        v-model="modalVisible"
        :title="
          form.id ? $t('articleCate.modal.edit') : $t('articleCate.modal.add')
        "
        width="520px"
      >
        <el-alert
          v-if="submitError"
          type="error"
          :closable="false"
          :title="submitError"
          style="margin-bottom: 16px"
        />
        <el-form ref="formRef" :model="form" :rules="rules" label-width="auto">
          <el-form-item prop="name" :label="$t('articleCate.form.name')">
            <el-input
              v-model="form.name"
              :placeholder="$t('articleCate.form.name.placeholder')"
              :maxlength="90"
              show-word-limit
            />
          </el-form-item>
          <el-form-item prop="sort" :label="$t('articleCate.form.sort')">
            <div>
              <el-input-number
                v-model="form.sort"
                :min="0"
                :max="9999"
                style="width: 160px"
              />
              <div class="form-tip">
                {{ $t('articleCate.form.sort.tip') }}
              </div>
            </div>
          </el-form-item>
          <el-form-item prop="is_show" :label="$t('articleCate.form.isShow')">
            <el-switch
              v-model="form.is_show"
              :active-value="1"
              :inactive-value="0"
            />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="modalVisible = false">取消</el-button>
          <el-button
            type="primary"
            :loading="submitLoading"
            @click="onSubmit"
            >确定</el-button
          >
        </template>
      </el-dialog>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { computed, onScopeDispose, reactive } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useRoute } from 'vue-router';
  import { ElMessage, type FormRules } from 'element-plus';
  import { Plus } from '@element-plus/icons-vue';
  import { getTabKey } from '@peanut-admin/ui-vue/tabs';
  import { registerTenantDisposer, useAsyncAction } from '@peanut-admin/vue';
  import { hasPermission } from '@/hooks/permission';
  import useCrudModal from '@/hooks/useCrudModal';
  import useCrudTab from '@/hooks/useCrudTab';
  import useCrudTable from '@/hooks/useCrudTable';
  import {
    articleCategoryResource,
    type ArticleCategoryFilters,
  } from '@/modules/peanut-article/category-resource';
  import { type ArticleCateRecord } from '@/modules/peanut-article/api';

  const { t } = useI18n();
  const route = useRoute();
  const canEdit = computed(
    () =>
      hasPermission('peanut.article.category.edit', route.path) &&
      hasPermission('peanut.article.category.detail', route.path)
  );
  const errorMessage = () => t('articleCate.error.request');
  const categoryList = useCrudTable<ArticleCateRecord, ArticleCategoryFilters>({
    initialFilters: {},
    initialPageSize: 25,
    load: articleCategoryResource.list,
    errorMessage,
  });
  const tableRows = computed(() => reactive([...categoryList.data.value]));
  categoryList.fetchList();

  const formAction = useAsyncAction(errorMessage);
  const currentTab = useCrudTab(formAction.cancel);
  const generateForm = () => ({ id: 0, name: '', sort: 0, is_show: 1 });
  const modal = useCrudModal<ReturnType<typeof generateForm>, ArticleCateRecord>({
    canOpen: (mode) => {
      if (mode === 'edit') return canEdit.value;
      return (
        mode === 'create' &&
        hasPermission('peanut.article.category.add', route.path)
      );
    },
    createForm: generateForm,
    editForm: (record) => ({
      id: record.id,
      name: record.name,
      sort: record.sort,
      is_show: record.is_show,
    }),
    save: (record, mode, signal) =>
      mode === 'edit'
        ? articleCategoryResource.update(record, signal)
        : articleCategoryResource.create(
            { name: record.name, sort: record.sort, is_show: record.is_show },
            signal
          ),
    errorMessage,
    onSaved: async () => {
      ElMessage.success(t('articleCate.message.success'));
      await categoryList.fetchList();
    },
  });
  const {
    visible: modalVisible,
    form,
    formRef,
    submitError,
    submitLoading,
  } = modal;
  const canRun = () =>
    currentTab.value && !formAction.loading.value && !submitLoading.value;

  const rules: FormRules = {
    name: [
      { required: true, message: t('articleCate.form.name.required') },
      {
        min: 1,
        max: 90,
        message: t('articleCate.form.name.length'),
      },
    ],
    sort: [
      {
        validator: (
          _rule: unknown,
          value: number,
          callback: (error?: Error) => void
        ) => {
          if (value == null || Number(value) < 0) {
            callback(new Error(t('articleCate.form.sort.min')));
            return;
          }
          callback();
        },
      },
    ],
  };

  const openAdd = () => canRun() && modal.create();

  const openEdit = async (record: ArticleCateRecord) => {
    if (!canRun() || !canEdit.value) return;
    const result = await formAction.run(({ signal }) =>
      articleCategoryResource.detail(record.id, signal)
    );
    if (result.status === 'completed' && currentTab.value)
      await modal.edit(result.data);
  };

  const onSubmit = () => canRun() && modal.submit();

  const onDelete = async (record: ArticleCateRecord) => {
    if (!canRun()) return;
    const result = await formAction.run(({ signal }) =>
      articleCategoryResource.remove(record.id, signal)
    );
    if (result.status !== 'completed' || !currentTab.value) return;
    ElMessage.success(t('articleCate.message.success'));
    await categoryList.fetchList();
  };

  const onStatusChange = async (record: ArticleCateRecord, val: unknown) => {
    if (!canRun()) return;
    const previousStatus = record.is_show;
    const nextStatus = val ? 1 : 0;
    const result = await formAction.run(({ signal }) =>
      articleCategoryResource.updateStatus(record.id, nextStatus, signal)
    );
    if (!currentTab.value) return;
    if (result.status !== 'completed') {
      record.is_show = previousStatus;
      return;
    }
    record.is_show = nextStatus;
    ElMessage.success(t('articleCate.message.success'));
  };

  const unregisterTenantDisposer = registerTenantDisposer(
    `peanut.article.category.page:${getTabKey(route)}`,
    () => {
      categoryList.clear();
      formAction.cancel();
      modalVisible.value = false;
      form.value = generateForm();
    }
  );
  onScopeDispose(unregisterTenantDisposer);
</script>

<script lang="ts">
  export default {
    name: 'ArticleCate',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .form-tip {
    margin-top: 4px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    line-height: 20px;
  }

  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
