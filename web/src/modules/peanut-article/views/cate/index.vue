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
        :data="categoryList.items.value"
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
                v-permission="['peanut.article.category.edit']"
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
          @current-change="onPageChange"
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
          v-if="formAction.error.value"
          type="error"
          :closable="false"
          :title="formAction.error.value"
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
            :loading="formAction.loading.value"
            @click="onSubmit"
            >确定</el-button
          >
        </template>
      </el-dialog>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { nextTick, onUnmounted, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
  import { Plus } from '@element-plus/icons-vue';
  import {
    registerTenantDisposer,
    useAsyncAction,
    useAsyncList,
  } from '@peanut-admin/vue';
  import {
    articleCategoryResource,
    type ArticleCategoryFilters,
  } from '@/modules/peanut-article/category-resource';
  import { type ArticleCateRecord } from '@/modules/peanut-article/api';

  const { t } = useI18n();
  const errorMessage = () => t('articleCate.error.request');
  const categoryList = useAsyncList<ArticleCateRecord, ArticleCategoryFilters>({
    initialFilters: {},
    initialPageSize: 25,
    load: articleCategoryResource.list,
    errorMessage,
  });
  const formAction = useAsyncAction(errorMessage);
  categoryList.load();

  const onPageChange = (current: number) => categoryList.load(current);

  const formRef = ref<FormInstance>();
  const modalVisible = ref(false);
  const generateForm = () => ({ id: 0, name: '', sort: 0, is_show: 1 });
  const form = ref(generateForm());

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

  const openAdd = async () => {
    form.value = generateForm();
    modalVisible.value = true;
    await nextTick();
    formRef.value?.clearValidate();
  };

  const openEdit = async (record: ArticleCateRecord) => {
    const result = await formAction.run(({ signal }) =>
      articleCategoryResource.detail(record.id, signal)
    );
    if (result.status !== 'completed') return;
    const { data } = result;
    form.value = {
      id: data.id,
      name: data.name,
      sort: data.sort,
      is_show: data.is_show,
    };
    modalVisible.value = true;
    await nextTick();
    formRef.value?.clearValidate();
  };

  const onSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    const result = await formAction.run(({ signal }) =>
      form.value.id
        ? articleCategoryResource.update(form.value, signal)
        : articleCategoryResource.create(
            {
              name: form.value.name,
              sort: form.value.sort,
              is_show: form.value.is_show,
            },
            signal
          )
    );
    if (result.status !== 'completed') return;
    ElMessage.success(t('articleCate.message.success'));
    modalVisible.value = false;
    await categoryList.reload();
  };

  const onDelete = async (record: ArticleCateRecord) => {
    const result = await formAction.run(({ signal }) =>
      articleCategoryResource.remove(record.id, signal)
    );
    if (result.status !== 'completed') return;
    ElMessage.success(t('articleCate.message.success'));
    await categoryList.reload();
  };

  const onStatusChange = async (record: ArticleCateRecord, val: unknown) => {
    const previousStatus = record.is_show;
    const nextStatus = val ? 1 : 0;
    const result = await formAction.run(({ signal }) =>
      articleCategoryResource.updateStatus(record.id, nextStatus, signal)
    );
    if (result.status !== 'completed') {
      record.is_show = previousStatus;
      return;
    }
    record.is_show = nextStatus;
    ElMessage.success(t('articleCate.message.success'));
  };

  const unregisterTenantDisposer = registerTenantDisposer(
    'peanut.article.category.page',
    () => {
      categoryList.clear();
      formAction.cancel();
      modalVisible.value = false;
      form.value = generateForm();
    }
  );
  onUnmounted(unregisterTenantDisposer);
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
