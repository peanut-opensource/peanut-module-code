<template>
  <PageContent class="pa-page-stack">
    <PageHeader>
      <h1>{{ recycled ? '文章回收站' : $t('menu.article.list') }}</h1>
      <template #actions>
        <el-button
          v-if="!recycled"
          v-permission="['peanut.article.add']"
          type="primary"
          :icon="Plus"
          :disabled="articleAction.loading.value"
          @click="openAdd"
          >{{ $t('article.button.add') }}</el-button
        >
        <el-button
          v-if="can('peanut.article.recycle.list') || recycled"
          :disabled="articleAction.loading.value"
          @click="switchList"
          >{{ recycled ? '返回文章' : '回收站' }}</el-button
        >
      </template>
    </PageHeader>
    <el-card class="general-card">
      <el-alert
        v-if="articleAction.error.value && !modalVisible"
        type="error"
        :closable="false"
        :title="articleAction.error.value"
      />
      <PageToolbar :label="$t('article.form.search')">
        <el-form
          class="pa-page-filters"
          :model="formModel"
          label-position="left"
          @submit.prevent="search"
        >
          <el-form-item prop="title" :label="$t('article.form.title')"
            ><el-input
              v-model="formModel.title"
              clearable
              :placeholder="$t('article.form.title.placeholder')"
              @keyup.enter="search"
          /></el-form-item>
          <el-form-item prop="cid" :label="$t('article.form.cate')"
            ><el-select
              v-model="formModel.cid"
              clearable
              :placeholder="$t('article.form.cate.placeholder')"
              ><el-option
                v-for="item in cateOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value" /></el-select
          ></el-form-item>
          <el-form-item prop="is_show" :label="$t('article.form.isShow')"
            ><el-select
              v-model="formModel.is_show"
              clearable
              :placeholder="$t('article.form.isShow.placeholder')"
              ><el-option
                v-for="item in showOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value" /></el-select
          ></el-form-item>
          <el-form-item class="pa-page-filters__actions"
            ><el-space
              ><el-button type="primary" :icon="Search" @click="search">{{
                $t('article.form.search')
              }}</el-button
              ><el-button :icon="Refresh" @click="reset">{{
                $t('article.form.reset')
              }}</el-button></el-space
            ></el-form-item
          >
        </el-form>
      </PageToolbar>
    </el-card>
    <el-card class="general-card">
      <div class="pa-page-results-toolbar pa-page-results-toolbar--standalone">
        <div class="pa-page-results-title"
          ><h2>{{ recycled ? '回收站' : '文章列表' }}</h2
          ><span>
            {{ pagination.total }} 条
            <span v-if="selected.length"
              >（已选 {{ selected.length }} 条）</span
            ></span
          ></div
        >
        <el-space wrap>
          <el-button
            v-if="recycled && can('peanut.article.restore')"
            :disabled="!selected.length || articleAction.loading.value"
            @click="batchRecycle('restore', selected)"
            >批量恢复</el-button
          >
          <el-button
            v-if="recycled && can('peanut.article.force-delete')"
            type="danger"
            plain
            :disabled="!selected.length || articleAction.loading.value"
            @click="batchRecycle('force-delete', selected)"
            >批量永久删除</el-button
          >
          <el-button
            v-if="can(listPermission)"
            :disabled="
              loading || articleAction.loading.value || !appliedFilters
            "
            @click="openExport"
            >导出</el-button
          >
          <el-button
            :icon="Refresh"
            :loading="loading"
            @click="fetchData(pagination.current)"
            >刷新</el-button
          >
          <el-dropdown trigger="click" :hide-on-click="false">
            <el-button>显示列</el-button>
            <template #dropdown
              ><el-dropdown-menu
                ><el-dropdown-item
                  v-for="column in optionalColumns"
                  :key="column.key"
                  ><el-checkbox
                    :model-value="visibleColumns.includes(column.key)"
                    @change="toggleColumn(column.key)"
                    :value="column.key"
                    :label="column.key"
                    >{{ column.label }}</el-checkbox
                  ></el-dropdown-item
                ></el-dropdown-menu
              ></template
            >
          </el-dropdown>
        </el-space>
      </div>
      <el-alert
        v-if="listError"
        :title="listError"
        type="error"
        :closable="false"
      />
      <el-alert
        v-if="batchResult"
        :type="batchResult.failed.length ? 'warning' : 'success'"
        :closable="true"
        @close="batchResult = undefined"
      >
        <template #title
          >处理 {{ batchResult.requested.length }} 条：成功
          {{ (batchResult.restored ?? batchResult.deleted ?? []).length }}
          条，已恢复 {{ batchResult.already_active.length }} 条，失败
          {{ batchResult.failed.length }} 条</template
        >
        <ul v-if="batchResult.failed.length"
          ><li v-for="failure in batchResult.failed" :key="failure.id"
            >#{{ failure.id }} · {{ failure.code }} · {{ failure.message }}</li
          ></ul
        >
      </el-alert>
      <el-table
        class="pa-page-table"
        v-loading="loading"
        row-key="id"
        :data="renderData"
        border
        max-height="max(240px, calc(100dvh - 380px))"
        scrollbar-tabindex="0"
        :aria-label="$t('article.table.accessibleName')"
        @selection-change="selected = $event"
      >
        <el-table-column
          v-if="recycled"
          type="selection"
          width="48"
          :fixed="leftFixed"
        />
        <el-table-column
          prop="id"
          :label="$t('article.columns.id')"
          width="80"
          :fixed="leftFixed"
        />
        <el-table-column
          prop="title"
          :label="$t('article.columns.title')"
          min-width="240"
          :fixed="leftFixed"
          show-overflow-tooltip
        />
        <el-table-column
          v-if="visibleColumns.includes('image')"
          :label="$t('article.columns.image')"
          width="90"
          ><template #default="{ row }"
            ><el-image
              v-if="row.image"
              :src="row.image"
              style="width: 60px; height: 40px"
              fit="cover"
            /><span v-else>-</span></template
          ></el-table-column
        >
        <el-table-column
          v-if="visibleColumns.includes('cate_name')"
          prop="cate_name"
          :label="$t('article.columns.cate')"
          width="140"
          show-overflow-tooltip
        />
        <el-table-column
          v-if="visibleColumns.includes('author')"
          prop="author"
          :label="$t('article.columns.author')"
          width="120"
          show-overflow-tooltip
        />
        <el-table-column
          v-if="visibleColumns.includes('click')"
          prop="click"
          :label="$t('article.columns.click')"
          width="100"
        />
        <el-table-column
          v-if="visibleColumns.includes('sort')"
          prop="sort"
          :label="$t('article.columns.sort')"
          width="80"
        />
        <el-table-column
          v-if="visibleColumns.includes('is_show')"
          :label="$t('article.columns.isShow')"
          width="100"
          ><template #default="{ row }"
            ><el-switch
              v-if="!recycled && can('peanut.article.update-status')"
              :model-value="row.is_show === 1"
              :disabled="articleAction.loading.value"
              @change="(value: boolean) => onStatusChange(row, value)"
            /><span v-else>{{
              row.is_show === 1 ? '显示' : '隐藏'
            }}</span></template
          ></el-table-column
        >
        <el-table-column
          v-if="visibleColumns.includes('create_time')"
          :label="$t('article.columns.createTime')"
          width="180"
          ><template #default="{ row }">{{
            formatTime(row.create_time)
          }}</template></el-table-column
        >
        <el-table-column
          :label="$t('article.columns.operations')"
          :width="narrow ? 160 : 210"
          fixed="right"
          ><template #default="{ row }"
            ><el-space>
              <template v-if="recycled">
                <el-button
                  v-if="can('peanut.article.restore')"
                  link
                  type="primary"
                  :disabled="articleAction.loading.value"
                  @click="batchRecycle('restore', [row])"
                  >恢复</el-button
                >
                <el-button
                  v-if="can('peanut.article.force-delete')"
                  link
                  type="danger"
                  :disabled="articleAction.loading.value"
                  @click="batchRecycle('force-delete', [row])"
                  >永久删除</el-button
                >
              </template>
              <template v-else>
                <el-button
                  v-permission="['peanut.article.edit']"
                  link
                  type="primary"
                  :disabled="articleAction.loading.value"
                  @click="openEdit(row)"
                  >{{ $t('article.button.edit') }}</el-button
                >
                <el-popconfirm
                  :title="`将文章 #${row.id}「${row.title}」移入回收站？`"
                  @confirm="onDelete(row)"
                  ><template #reference
                    ><el-button
                      v-permission="['peanut.article.delete']"
                      link
                      type="danger"
                      :disabled="articleAction.loading.value"
                      >{{ $t('article.button.delete') }}</el-button
                    ></template
                  ></el-popconfirm
                >
                <el-button
                  v-permission="['peanut.article.detail']"
                  link
                  @click="openHistory(row)"
                  >正文历史</el-button
                >
              </template>
            </el-space></template
          ></el-table-column
        >
        <template #empty
          ><el-empty :description="listError ? '加载失败，请重试' : '暂无文章'"
        /></template>
      </el-table>
      <div class="pa-page-pagination"
        ><el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :page-sizes="[15, 30, 50, 100]"
          :total="pagination.total"
          layout="total, sizes, prev, pager, next"
          @current-change="onPageChange"
          @size-change="onPageSizeChange"
      /></div>
      <el-dialog
        v-model="exportVisible"
        title="导出文章"
        width="min(520px, calc(100vw - 32px))"
      >
        <p v-if="exportInfo"
          >当前条件共 {{ exportInfo.count }} 条，最多可导出
          {{ exportInfo.max_page }} 页。</p
        >
        <el-form label-position="top"
          ><el-form-item label="起始页"
            ><el-input-number
              v-model="exportRange.page_start"
              :min="1"
              :max="exportInfo?.sum_page || 1" /></el-form-item
          ><el-form-item label="结束页"
            ><el-input-number
              v-model="exportRange.page_end"
              :min="exportRange.page_start"
              :max="
                Math.min(
                  exportInfo?.sum_page || 1,
                  exportRange.page_start + (exportInfo?.max_page || 1) - 1
                )
              " /></el-form-item
        ></el-form>
        <template #footer
          ><el-button @click="exportVisible = false">取消</el-button
          ><el-button
            type="primary"
            :loading="articleAction.loading.value"
            :disabled="!exportInfo?.count"
            @click="downloadExport"
            >生成并下载</el-button
          ></template
        >
      </el-dialog>
      <el-dialog
        v-model="modalVisible"
        class="article-dialog article-form-dialog"
        :title="form.id ? $t('article.modal.edit') : $t('article.modal.add')"
        width="min(900px, calc(100vw - 32px))"
        :close-on-click-modal="!articleAction.loading.value && !bodyLocked"
        :close-on-press-escape="!articleAction.loading.value && !bodyLocked"
        :show-close="!articleAction.loading.value && !bodyLocked"
        @closed="closeModal"
      >
        <el-alert
          v-if="articleAction.error.value"
          type="error"
          :closable="false"
          :title="articleAction.error.value"
          style="margin-bottom: 16px"
        />
        <el-form ref="formRef" :model="form" :rules="rules" label-width="auto">
          <el-form-item prop="cid" :label="$t('article.field.cate')">
            <el-select
              v-model="form.cid"
              :disabled="metadataDisabled"
              :placeholder="$t('article.form.cate.placeholder')"
              style="width: 100%"
              ><el-option
                v-for="item in cateOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
            /></el-select>
          </el-form-item>
          <el-form-item prop="title" :label="$t('article.field.title')">
            <el-input
              v-model="form.title"
              :disabled="metadataDisabled"
              :maxlength="64"
              show-word-limit
              :placeholder="$t('article.form.title.placeholder')"
            />
          </el-form-item>
          <el-form-item prop="desc" :label="$t('article.field.desc')">
            <el-input
              v-model="form.desc"
              type="textarea"
              :disabled="metadataDisabled"
              :maxlength="200"
              show-word-limit
              :autosize="{ minRows: 2, maxRows: 4 }"
            />
          </el-form-item>
          <el-form-item prop="abstract" :label="$t('article.field.abstract')">
            <el-input
              v-model="form.abstract"
              type="textarea"
              :disabled="metadataDisabled"
              :maxlength="200"
              show-word-limit
              :autosize="{ minRows: 2, maxRows: 4 }"
            />
          </el-form-item>
          <el-form-item prop="image" :label="$t('article.field.image')">
            <el-space alignment="flex-end">
              <el-upload
                :key="`${coverCallbacks.key}:upload`"
                :disabled="metadataDisabled"
                :http-request="coverCallbacks.upload"
                :show-file-list="false"
                accept="image/*"
              >
                <div class="img-uploader">
                  <img v-if="coverUrl" :src="coverUrl" alt="cover" />
                  <el-icon v-else><Plus /></el-icon>
                </div>
              </el-upload>
              <file-picker
                v-if="!metadataDisabled"
                :key="`${coverCallbacks.key}:picker`"
                :type="10"
                :limit="1"
                button-text="从素材库选择"
                @select="coverCallbacks.select"
              />
            </el-space>
          </el-form-item>
          <el-form-item prop="author" :label="$t('article.field.author')"
            ><el-input v-model="form.author" :disabled="metadataDisabled"
          /></el-form-item>
          <el-form-item prop="sort" :label="$t('article.field.sort')"
            ><el-input-number
              v-model="form.sort"
              :disabled="metadataDisabled"
              :min="0"
              :max="9999"
          /></el-form-item>
          <el-form-item
            prop="click_virtual"
            :label="$t('article.field.clickVirtual')"
          >
            <el-input-number
              v-model="form.click_virtual"
              :disabled="metadataDisabled"
              :min="0"
            />
          </el-form-item>
          <el-form-item prop="is_show" :label="$t('article.field.isShow')">
            <el-switch
              v-model="form.is_show"
              disabled
              :active-value="1"
              :inactive-value="0"
            />
          </el-form-item>
          <el-form-item prop="content" :label="$t('article.field.content')">
            <ArticleBodyEditor
              v-if="
                modalVisible &&
                form.id &&
                (form.body_document_id !== null || !form.content.trim()) &&
                form.is_show === 0
              "
              ref="bodyEditor"
              :key="form.id"
              :article-id="form.id"
              :context-key="contextKey"
              @saved="fetchData(pagination.current)"
              @locked="bodyLocked = $event"
              @metadata-ready="bodyMetadataReady = $event"
              @metadata="onMetadataLoaded"
            />
            <ArticleHtmlMigration
              v-else-if="
                modalVisible &&
                form.id &&
                form.body_document_id === null &&
                form.content.trim() &&
                form.is_show === 0
              "
              :key="form.id"
              :article-id="form.id"
              :context-key="contextKey"
              @locked="bodyLocked = $event"
              @completed="onHtmlMigrated"
            />
            <p v-else role="status">
              {{
                form.id
                  ? '公开资讯请先按原权限下架后编辑正文。'
                  : '新建资讯暂只创建元数据，正文请在创建后接入。'
              }}
            </p>
            <pre v-if="form.content.trim() && form.is_show !== 0">{{
              form.content
            }}</pre>
          </el-form-item>
        </el-form>
        <template #footer
          ><el-button
            :disabled="articleAction.loading.value || bodyLocked"
            @click="closeModal"
            >取消</el-button
          ><el-button
            type="primary"
            :loading="articleAction.loading.value"
            :disabled="bodyLocked || (form.id !== 0 && !bodyMetadataReady)"
            @click="onSubmit"
            >确定</el-button
          ></template
        >
      </el-dialog>
      <el-dialog
        v-model="historyVisible"
        class="article-dialog"
        title="资讯正文历史"
        width="min(900px, calc(100vw - 32px))"
        @closed="closeHistory"
      >
        <p v-if="historyError" role="alert">{{ historyError }}</p>
        <p v-else-if="historyLoading" role="status">正在核验资讯正文绑定。</p>
        <ArticleBodyHistory
          v-else-if="historyBinding"
          :article-id="historyBinding.articleId"
          :document-id="historyBinding.documentId"
          :context-key="contextKey"
        />
      </el-dialog>
    </el-card>
  </PageContent>
</template>

<script lang="ts" setup>
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
  import { PageContent, PageHeader, PageToolbar } from '@peanut-admin/ui-vue';
  import { useMediaQuery } from '@vueuse/core';
  import { useRoute } from 'vue-router';
  import { hasPermission } from '@/hooks/permission';
  import { useI18n } from 'vue-i18n';
  import {
    ElMessage,
    ElMessageBox,
    type FormInstance,
    type FormRules,
    type UploadRequestOptions,
  } from 'element-plus';
  import { Plus, Refresh, Search } from '@element-plus/icons-vue';
  import { useAsyncAction } from '@peanut-admin/vue';
  import useLoading from '@/hooks/loading';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot } from '@/utils/auth';
  import FilePicker from '@/components/file-picker/index.vue';
  import { uploadFile, type FileRecord } from '@/modules/peanut-file/api';
  import {
    getArticleList,
    getArticleExportInfo,
    exportArticles,
    recycleArticles,
    type ArticleBatchResult,
    type ArticleExportInfo,
    getArticleDetail,
    getArticleCateAll,
    addArticle,
    deleteArticle,
    updateArticleStatus,
    type ArticleRecord,
    type ArticleCreateRequest,
    type ArticleListParams,
    type ArticleBodyEditor as ArticleEditorMetadata,
    type ArticleBodyMetadata,
    type ArticleHtmlMigrated,
  } from '@/modules/peanut-article/api';
  import ArticleBodyEditor from './ArticleBodyEditor.vue';
  import ArticleHtmlMigration from './ArticleHtmlMigration.vue';
  import ArticleBodyHistory from './ArticleBodyHistory.vue';

  interface ArticleForm {
    id: number;
    body_document_id: number | null;
    cid: number | undefined;
    title: string;
    desc: string;
    abstract: string;
    image: string;
    author: string;
    content: string;
    click_virtual: number;
    sort: number;
    is_show: number;
  }

  const { t } = useI18n();
  const user = useUserStore();
  const route = useRoute();
  const can = (permission: string) => hasPermission(permission, route.path);
  const narrow = useMediaQuery('(max-width: 768px)');
  const leftFixed = computed(() => (narrow.value ? false : ('left' as const)));
  const recycled = ref(false);
  const listPermission = computed(() =>
    recycled.value ? 'peanut.article.recycle.list' : 'peanut.article.list'
  );
  const selected = ref<ArticleRecord[]>([]);
  const listError = ref('');
  const batchResult = ref<ArticleBatchResult>();
  const optionalColumns = computed(() => [
    { key: 'image', label: t('article.columns.image') },
    { key: 'cate_name', label: t('article.columns.cate') },
    { key: 'author', label: t('article.columns.author') },
    { key: 'click', label: t('article.columns.click') },
    { key: 'sort', label: t('article.columns.sort') },
    { key: 'is_show', label: t('article.columns.isShow') },
    { key: 'create_time', label: t('article.columns.createTime') },
  ]);
  const visibleColumns = ref([
    'image',
    'cate_name',
    'author',
    'click',
    'sort',
    'is_show',
    'create_time',
  ]);
  const toggleColumn = (column: string) => {
    visibleColumns.value = visibleColumns.value.includes(column)
      ? visibleColumns.value.filter((item) => item !== column)
      : [...visibleColumns.value, column];
  };
  const appliedFilters = ref<ArticleListParams>();
  const exportVisible = ref(false);
  const exportInfo = ref<ArticleExportInfo>();
  const exportRange = reactive({ page_start: 1, page_end: 1 });
  const openExport = async () => {
    if (!can(listPermission.value) || !appliedFilters.value) return;
    const result = await articleAction.run(() =>
      getArticleExportInfo(appliedFilters.value, recycled.value)
    );
    if (result.status !== 'completed') return;
    exportInfo.value = result.data.data;
    exportRange.page_start = 1;
    exportRange.page_end = Math.max(
      1,
      Math.min(result.data.data.page_end, result.data.data.max_page)
    );
    exportVisible.value = true;
  };
  const downloadExport = async () => {
    if (!can(listPermission.value) || !appliedFilters.value) return;
    const result = await articleAction.run(() =>
      exportArticles(
        { ...appliedFilters.value, ...exportRange },
        recycled.value
      )
    );
    if (result.status !== 'completed') return;
    const anchor = document.createElement('a');
    anchor.href = result.data.data.url;
    anchor.download = result.data.data.file_name;
    anchor.click();
    exportVisible.value = false;
  };
  const switchList = () => {
    if (!recycled.value && !can('peanut.article.recycle.list')) return;
    recycled.value = !recycled.value;
    renderData.value = [];
    selected.value = [];
    pagination.total = 0;
    pagination.current = 1;
    appliedFilters.value = undefined;
    exportVisible.value = false;
    batchResult.value = undefined;
    fetchData(1);
  };
  const batchRecycle = async (
    action: 'restore' | 'force-delete',
    records: ArticleRecord[]
  ) => {
    if (
      !can(`peanut.article.${action}`) ||
      !records.length ||
      articleAction.loading.value
    )
      return;
    const identity = contextKey.value;
    const ids = records.map((record) => record.id);
    try {
      await ElMessageBox.confirm(
        `${action === 'restore' ? '恢复' : '永久删除（不可撤销）'}以下 ${
          ids.length
        } 篇文章：${records
          .map((record) => `#${record.id}「${record.title}」`)
          .join('、')}？`,
        '确认文章操作',
        {
          type: 'warning',
          confirmButtonText: action === 'restore' ? '恢复' : '永久删除',
          cancelButtonText: '取消',
        }
      );
    } catch {
      return;
    }
    if (identity !== contextKey.value || !can(`peanut.article.${action}`))
      return;
    const result = await articleAction.run(({ signal }) =>
      recycleArticles(action, ids, signal)
    );
    if (result.status !== 'completed') return;
    batchResult.value = result.data.data;
    await fetchData(pagination.current);
  };

  const generation = ref(getSessionSnapshot().generation);
  const unsubscribe = user.$subscribe(
    () => {
      generation.value = getSessionSnapshot().generation;
    },
    { flush: 'sync' }
  );
  const contextKey = computed(() =>
    JSON.stringify([
      generation.value,
      user.id,
      user.accountId,
      user.tenantName,
      user.role,
      user.permissions,
    ])
  );
  let disposed = false;
  let listing: AbortController | undefined;
  let categories: AbortController | undefined;
  const active = (controller: AbortController, key: string) =>
    !disposed && !controller.signal.aborted && key === contextKey.value;
  const articleAction = useAsyncAction(() => t('article.error.request'));
  const historyVisible = ref(false);
  const historyLoading = ref(false);
  const historyError = ref('');
  const historyBinding = ref<{ articleId: number; documentId: number }>();
  let historyRequest: AbortController | undefined;
  let { generation: historyGeneration } = getSessionSnapshot();
  const closeHistory = () => {
    historyRequest?.abort();
    historyRequest = undefined;
    historyBinding.value = undefined;
    historyError.value = '';
    historyLoading.value = false;
  };
  watch(
    historyVisible,
    (visible) => {
      if (!visible) closeHistory();
    },
    { flush: 'sync' }
  );
  const openHistory = async (record: ArticleRecord) => {
    closeHistory();
    historyVisible.value = true;
    historyLoading.value = true;
    const controller = new AbortController();
    historyRequest = controller;
    const identity = contextKey.value;
    historyGeneration = getSessionSnapshot().generation;
    const expectedGeneration = historyGeneration;
    const liveHistory = () => {
      if (
        !active(controller, identity) ||
        historyRequest !== controller ||
        !historyVisible.value
      )
        return false;
      if (expectedGeneration !== getSessionSnapshot().generation) {
        historyVisible.value = false;
        closeHistory();
        return false;
      }
      return true;
    };
    try {
      const { data } = await getArticleDetail(record.id, controller.signal);
      if (!liveHistory()) return;
      if (!data || Array.isArray(data) || data.id !== record.id)
        throw new Error('ARTICLE_HISTORY_BINDING_INVALID');
      if (data.body_document_id === null) {
        historyError.value =
          '此资讯尚未绑定文档正文；查看历史不会自动创建文档。';
        return;
      }
      if (
        !Number.isSafeInteger(data.body_document_id) ||
        data.body_document_id < 1
      )
        throw new Error('ARTICLE_HISTORY_BINDING_INVALID');
      historyBinding.value = {
        articleId: record.id,
        documentId: data.body_document_id,
      };
    } catch {
      if (liveHistory())
        historyError.value = '正文绑定查询失败，请检查当前查看权限和服务状态。';
    } finally {
      if (historyRequest === controller) {
        historyLoading.value = false;
        historyRequest = undefined;
      }
    }
  };
  const historyStorage = (event: StorageEvent) => {
    if (event.storageArea !== null && event.storageArea !== localStorage)
      return;
    if (historyGeneration !== getSessionSnapshot().generation) {
      historyVisible.value = false;
      closeHistory();
    }
  };
  window.addEventListener('storage', historyStorage);
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<ArticleRecord[]>([]);
  const cateOptions = ref<Array<{ label: string; value: number }>>([]);

  const generateFormModel = () => ({
    title: '',
    cid: '' as number | string,
    is_show: '' as number | string,
  });
  const formModel = ref(generateFormModel());

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
  });

  const showOptions = computed(() => [
    { label: t('article.show.1'), value: 1 },
    { label: t('article.show.0'), value: 0 },
  ]);

  const formatTime = (value?: number | string) => {
    if (!value) return '-';
    if (typeof value === 'number') {
      return new Date(value * 1000).toLocaleString();
    }
    return value;
  };

  const fetchCateOptions = async () => {
    categories?.abort();
    const controller = new AbortController();
    categories = controller;
    const key = contextKey.value;
    try {
      const { data } = await getArticleCateAll(controller.signal);
      if (!active(controller, key)) return;
      cateOptions.value = data.map((item) => ({
        label: item.name,
        value: item.id,
      }));
    } catch {
      // The shared Axios interceptor displays the server error.
    }
  };

  const fetchData = async (page = 1) => {
    listing?.abort();
    const controller = new AbortController();
    listing = controller;
    const key = contextKey.value;
    setLoading(true);
    listError.value = '';
    selected.value = [];
    try {
      if (!can(listPermission.value)) {
        renderData.value = [];
        pagination.total = 0;
        return;
      }
      const params: ArticleListParams = {
        title: formModel.value.title || undefined,
        cid: formModel.value.cid === '' ? undefined : formModel.value.cid,
        is_show:
          formModel.value.is_show === '' ? undefined : formModel.value.is_show,
        page_no: page,
        page_size: pagination.pageSize,
        page_type: 1,
      };
      const { data } = await getArticleList(
        params,
        controller.signal,
        recycled.value
      );
      if (!active(controller, key)) return;
      renderData.value = data.lists;
      appliedFilters.value = { ...params, page_no: undefined };
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
    } catch (error) {
      if (active(controller, key)) {
        listError.value =
          error instanceof Error ? error.message : t('article.error.request');
      }
    } finally {
      if (listing === controller) setLoading(false);
    }
  };

  const search = () => fetchData(1);
  const onPageSizeChange = (size: number) => {
    pagination.pageSize = size;
    fetchData(1);
  };
  const onPageChange = (current: number) => fetchData(current);
  const reset = () => {
    formModel.value = generateFormModel();
    fetchData(1);
  };

  const formRef = ref<FormInstance>();
  const bodyEditor = ref<InstanceType<typeof ArticleBodyEditor>>();
  const bodyLocked = ref(false);
  const bodyMetadataReady = ref(false);
  const coverUrl = ref('');
  const modalVisible = ref(false);
  const coverGeneration = ref(0);
  watch(
    modalVisible,
    (visible) => {
      if (!visible) coverGeneration.value += 1;
    },
    { flush: 'sync' }
  );
  const generateForm = (): ArticleForm => ({
    id: 0,
    body_document_id: null,
    cid: undefined,
    title: '',
    desc: '',
    abstract: '',
    image: '',
    author: '',
    content: '',
    click_virtual: 0,
    sort: 0,
    is_show: 0,
  });
  const form = ref<ArticleForm>(generateForm());
  const onHtmlMigrated = (value: ArticleHtmlMigrated) => {
    if (!modalVisible.value || value.article_id !== form.value.id) return;
    form.value.body_document_id = value.document_id;
    bodyMetadataReady.value = false;
    bodyLocked.value = false;
    fetchData(pagination.current);
  };
  const metadataDisabled = computed(
    () => bodyLocked.value || (form.value.id !== 0 && !bodyMetadataReady.value)
  );
  const onMetadataLoaded = (value: ArticleEditorMetadata) => {
    if (!modalVisible.value || value.article_id !== form.value.id) return;
    // Explicit metadata reload also ends any earlier cover intent in this dialog.
    coverGeneration.value += 1;
    // Only an explicit initial load/reload replaces the form, from the same row as its CAS baseline.
    const metadata: ArticleBodyMetadata = value.article_metadata;
    const {
      cid,
      title,
      desc,
      abstract,
      image,
      image_url: imageUrl,
      author,
      click_virtual: clickVirtual,
      sort,
      is_show: isShow,
    } = metadata;
    coverUrl.value = imageUrl;
    Object.assign(form.value, {
      body_document_id: value.document_id,
      cid,
      title,
      desc,
      abstract,
      image,
      author,
      click_virtual: clickVirtual,
      sort,
      is_show: isShow,
    });
    formRef.value?.clearValidate();
  };

  const validateRange =
    (min: number, max?: number) =>
    (_rule: unknown, value: number, callback: (error?: Error) => void) => {
      const number = Number(value);
      if (
        !Number.isInteger(number) ||
        number < min ||
        (max !== undefined && number > max)
      ) {
        callback(
          new Error(
            max === undefined
              ? t('article.validation.nonNegative')
              : t('article.validation.sort')
          )
        );
        return;
      }
      callback();
    };

  const rules: FormRules = {
    cid: [{ required: true, message: t('article.form.cate.placeholder') }],
    title: [
      { required: true, message: t('article.form.title.placeholder') },
      {
        max: 64,
        message: t('article.validation.titleLength'),
      },
    ],
    desc: [{ max: 200, message: t('article.validation.textLength') }],
    abstract: [{ max: 200, message: t('article.validation.textLength') }],
    sort: [{ validator: validateRange(0, 9999) }],
    click_virtual: [{ validator: validateRange(0) }],
  };

  const openAdd = () => {
    if (bodyLocked.value) return;
    coverGeneration.value += 1;
    articleAction.cancel();
    form.value = generateForm();
    coverUrl.value = '';
    bodyMetadataReady.value = false;
    modalVisible.value = true;
    formRef.value?.clearValidate();
  };

  const openEdit = (record: ArticleRecord) => {
    if (bodyLocked.value) return;
    coverGeneration.value += 1;
    // The list only selects the existing object. Edit metadata/revision are loaded together by the owner editor.
    articleAction.cancel();
    bodyMetadataReady.value = false;
    coverUrl.value = record.image || '';
    form.value = {
      id: record.id,
      body_document_id: record.body_document_id ?? null,
      cid: record.cid,
      title: record.title,
      desc: record.desc || '',
      abstract: record.abstract || '',
      image: '',
      author: record.author || '',
      content: record.content || '',
      click_virtual: record.click_virtual || 0,
      sort: record.sort || 0,
      is_show: record.is_show,
    };
    modalVisible.value = true;
    formRef.value?.clearValidate();
  };

  const applyCover = (file: FileRecord | undefined) => {
    if (!modalVisible.value || metadataDisabled.value) return false;
    if (
      !file ||
      typeof file.file_key !== 'string' ||
      !/^file_[0-9a-f]{32}$/.test(file.file_key)
    ) {
      ElMessage.warning('素材缺少稳定文件引用，请重新选择。');
      return false;
    }
    form.value.image = file.file_key;
    coverUrl.value = file.url;
    return true;
  };
  const coverCallbacks = computed(() => {
    // Each component instance and request retains this originating identity/dialog/owner tuple.
    const identity = contextKey.value;
    const dialog = coverGeneration.value;
    const articleId = form.value.id;
    const currentCover = () =>
      !disposed &&
      identity === contextKey.value &&
      dialog === coverGeneration.value &&
      articleId === form.value.id &&
      modalVisible.value &&
      !metadataDisabled.value;
    return {
      key: JSON.stringify([identity, dialog, articleId]),
      upload: async (options: UploadRequestOptions) => {
        if (!currentCover()) throw new Error('ARTICLE_COVER_CONTEXT_CHANGED');
        const file = await uploadFile(10, options);
        if (!currentCover()) throw new Error('ARTICLE_COVER_CONTEXT_CHANGED');
        if (applyCover(file)) ElMessage.success(t('article.tip.uploadSuccess'));
        return file;
      },
      select: (_urls: string[], files: FileRecord[]) => {
        if (currentCover()) applyCover(files[0]);
      },
    };
  });

  const closeModal = () => {
    if (articleAction.loading.value || bodyLocked.value) return;
    articleAction.cancel();
    modalVisible.value = false;
  };

  const onSubmit = async () => {
    if (articleAction.loading.value) return;
    const result = await articleAction.run(async ({ signal }) => {
      const valid = await formRef.value?.validate().catch(() => false);
      if (!valid || form.value.cid === undefined) return false;
      const { id, cid, title, desc, abstract, image, author, content, sort } =
        form.value;
      const payload: ArticleCreateRequest = {
        cid,
        title,
        desc,
        abstract,
        image,
        author,
        content,
        click_virtual: form.value.click_virtual,
        sort,
        is_show: form.value.is_show,
      };
      if (id) {
        if (
          !bodyEditor.value ||
          !bodyMetadataReady.value ||
          (content.trim() && form.value.body_document_id === null) ||
          form.value.is_show !== 0
        )
          return false;
        // Owner Save binds a fixed Document version; never write legacy HTML.
        return bodyEditor.value.save({
          cid,
          title,
          desc,
          abstract,
          image,
          author,
          click_virtual: payload.click_virtual,
          sort,
          is_show: 0,
        });
      }
      return addArticle({ ...payload, content: '', is_show: 0 }, signal);
    });
    if (result.status !== 'completed' || !result.data) return;
    ElMessage.success(t('article.message.success'));
    if (!form.value.id) modalVisible.value = false;
    await fetchData(pagination.current);
  };

  const onDelete = async (record: ArticleRecord) => {
    if (recycled.value || !can('peanut.article.delete')) return;
    const result = await articleAction.run(({ signal }) =>
      deleteArticle(record.id, signal)
    );
    if (result.status !== 'completed') return;
    ElMessage.success(t('article.message.success'));
    await fetchData(pagination.current);
  };

  const onStatusChange = async (record: ArticleRecord, value: unknown) => {
    if (recycled.value || !can('peanut.article.update-status')) return;
    const isShow = value ? 1 : 0;
    // The original authorized API locks the current row and rejects managed publication.
    const result = await articleAction.run(({ signal }) =>
      updateArticleStatus(record.id, isShow, signal)
    );
    if (result.status !== 'completed') return;
    record.is_show = isShow;
    ElMessage.success(t('article.message.success'));
  };
  watch(
    contextKey,
    () => {
      historyVisible.value = false;
      closeHistory();
      coverGeneration.value += 1;
      listing?.abort();
      categories?.abort();
      articleAction.cancel();
      modalVisible.value = false;
      bodyLocked.value = false;
      bodyMetadataReady.value = false;
      coverUrl.value = '';
      form.value = generateForm();
      renderData.value = [];
      selected.value = [];
      batchResult.value = undefined;
      exportVisible.value = false;
      recycled.value = false;
      appliedFilters.value = undefined;
      cateOptions.value = [];
      formModel.value = generateFormModel();
      pagination.current = 1;
      pagination.total = 0;
      fetchCateOptions();
      fetchData();
    },
    { immediate: true, flush: 'sync' }
  );
  onBeforeUnmount(() => {
    disposed = true;
    window.removeEventListener('storage', historyStorage);
    closeHistory();
    coverGeneration.value += 1;
    listing?.abort();
    categories?.abort();
    articleAction.cancel();
    unsubscribe();
  });
</script>

<script lang="ts">
  export default {
    name: 'ArticleList',
  };
</script>

<style scoped lang="less">
  .img-uploader {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;

    img {
      max-width: 100%;
      max-height: 100%;
    }
  }

  .filter-actions {
    display: flex;
    justify-content: flex-end;
    padding-bottom: 18px;
  }
  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
  .table-scroll-hint {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  :deep(.el-table .el-scrollbar__wrap:focus-visible) {
    outline: 2px solid var(--el-color-primary);
    outline-offset: -2px;
  }
  :global(.article-dialog) {
    display: flex;
    flex-direction: column;
    max-height: calc(100dvh - 32px);
    margin: 16px auto;
  }
  :global(.article-dialog .el-dialog__body) {
    min-height: 0;
    overflow-y: auto;
  }
  :global(.article-dialog .el-dialog__footer) {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
  }
  :global(.article-dialog .el-dialog__footer .el-button) {
    margin-left: 0;
  }
  @media (max-width: 600px) {
    :global(.article-form-dialog .el-form-item__label) {
      width: 100%;
      justify-content: flex-start;
      margin-bottom: 6px;
    }
  }
</style>
