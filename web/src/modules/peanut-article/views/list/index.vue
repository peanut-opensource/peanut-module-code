<template>
  <div class="container">
    <Breadcrumb :items="['menu.article', 'menu.article.list']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.article.list') }}</template>
      <el-alert
        v-if="articleAction.error.value && !modalVisible"
        type="error"
        :closable="false"
        :title="articleAction.error.value"
        style="margin-bottom: 16px"
      />
      <el-form :model="formModel" label-position="top">
        <el-row :gutter="16">
          <el-col :span="7">
            <el-form-item prop="title" :label="$t('article.form.title')">
              <el-input
                v-model="formModel.title"
                clearable
                :placeholder="$t('article.form.title.placeholder')"
              />
            </el-form-item>
          </el-col>
          <el-col :span="7">
            <el-form-item prop="cid" :label="$t('article.form.cate')">
              <el-select
                v-model="formModel.cid"
                clearable
                :placeholder="$t('article.form.cate.placeholder')"
                style="width: 100%"
                ><el-option
                  v-for="item in cateOptions"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
              /></el-select>
            </el-form-item>
          </el-col>
          <el-col :span="7">
            <el-form-item prop="is_show" :label="$t('article.form.isShow')">
              <el-select
                v-model="formModel.is_show"
                clearable
                :placeholder="$t('article.form.isShow.placeholder')"
                style="width: 100%"
                ><el-option
                  v-for="item in showOptions"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
              /></el-select>
            </el-form-item>
          </el-col>
          <el-col :span="3" class="filter-actions"
            ><el-space direction="vertical" :size="12"
              ><el-button type="primary" :icon="Search" @click="search">{{
                $t('article.form.search')
              }}</el-button
              ><el-button :icon="Refresh" @click="reset">{{
                $t('article.form.reset')
              }}</el-button></el-space
            ></el-col
          >
        </el-row>
      </el-form>
      <el-divider style="margin-top: 0" />
      <el-button
        v-permission="['peanut.article.add']"
        type="primary"
        :icon="Plus"
        :disabled="articleAction.loading.value"
        style="margin-bottom: 16px"
        @click="openAdd"
      >
        {{ $t('article.button.add') }}
      </el-button>
      <p class="table-scroll-hint" role="note">
        {{ $t('article.table.scrollHint') }}
      </p>
      <el-table
        v-loading="loading"
        row-key="id"
        :data="renderData"
        border
        scrollbar-tabindex="0"
        :aria-label="$t('article.table.accessibleName')"
      >
        <el-table-column
          prop="id"
          :label="$t('article.columns.id')"
          width="70"
        />
        <el-table-column :label="$t('article.columns.image')" width="90">
          <template #default="{ row }">
            <el-image
              v-if="row.image"
              :src="row.image"
              width="60"
              height="40"
              fit="cover"
            />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="title" :label="$t('article.columns.title')" />
        <el-table-column
          prop="cate_name"
          :label="$t('article.columns.cate')"
          width="120"
        />
        <el-table-column
          prop="author"
          :label="$t('article.columns.author')"
          width="100"
        />
        <el-table-column
          prop="click"
          :label="$t('article.columns.click')"
          width="100"
        />
        <el-table-column
          prop="sort"
          :label="$t('article.columns.sort')"
          width="80"
        />
        <el-table-column :label="$t('article.columns.isShow')" width="90"
          ><template #default="{ row }"
            ><el-switch
              v-permission="['peanut.article.update-status']"
              :model-value="row.is_show === 1"
              :disabled="articleAction.loading.value"
              @change="(value: boolean) => onStatusChange(row, value)" /></template
        ></el-table-column>
        <el-table-column :label="$t('article.columns.createTime')" width="170"
          ><template #default="{ row }">{{
            formatTime(row.create_time)
          }}</template></el-table-column
        >
        <el-table-column
          :label="$t('article.columns.operations')"
          width="150"
          fixed="right"
          ><template #default="{ row }"
            ><el-space>
              <el-button
                v-permission="['peanut.article.edit']"
                link
                type="primary"
                size="small"
                :disabled="articleAction.loading.value"
                @click="openEdit(row)"
              >
                {{ $t('article.button.edit') }}
              </el-button>
              <el-popconfirm
                :title="$t('article.confirm.delete')"
                @confirm="onDelete(row)"
              >
                <template #reference
                  ><el-button
                    v-permission="['peanut.article.delete']"
                    link
                    type="danger"
                    size="small"
                    :disabled="articleAction.loading.value"
                  >
                    {{ $t('article.button.delete') }}
                  </el-button></template
                >
              </el-popconfirm>
              <el-button
                v-permission="['peanut.article.detail']"
                link
                size="small"
                @click="openHistory(row)"
                >正文历史</el-button
              >
            </el-space></template
          ></el-table-column
        >
      </el-table>
      <div class="pagination-wrapper"
        ><el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="onPageChange"
      /></div>

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
                !form.content.trim() &&
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
            <p v-else-if="form.id && form.content.trim()" role="status">
              已有 HTML 正文需显式迁移，当前只读；此处不会转换或覆盖原正文。
            </p>
            <p v-else role="status">
              {{
                form.id
                  ? '公开资讯请先按原权限下架后编辑正文。'
                  : '新建资讯暂只创建元数据，正文请在创建后接入。'
              }}
            </p>
            <pre v-if="form.content.trim()">{{ form.content }}</pre>
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
  </div>
</template>

<script lang="ts" setup>
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
  import { useI18n } from 'vue-i18n';
  import {
    ElMessage,
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
  } from '@/modules/peanut-article/api';
  import ArticleBodyEditor from './ArticleBodyEditor.vue';
  import ArticleBodyHistory from './ArticleBodyHistory.vue';

  interface ArticleForm {
    id: number;
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
    try {
      const params: ArticleListParams = {
        title: formModel.value.title || undefined,
        cid: formModel.value.cid === '' ? undefined : formModel.value.cid,
        is_show:
          formModel.value.is_show === '' ? undefined : formModel.value.is_show,
        page_no: page,
        page_size: pagination.pageSize,
        page_type: 1,
      };
      const { data } = await getArticleList(params, controller.signal);
      if (!active(controller, key)) return;
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
    } catch {
      // The shared Axios interceptor displays the server error.
    } finally {
      if (listing === controller) setLoading(false);
    }
  };

  const search = () => fetchData(1);
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
          content.trim() ||
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
    const result = await articleAction.run(({ signal }) =>
      deleteArticle(record.id, signal)
    );
    if (result.status !== 'completed') return;
    ElMessage.success(t('article.message.success'));
    await fetchData(pagination.current);
  };

  const onStatusChange = async (record: ArticleRecord, value: unknown) => {
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
  .container {
    padding: 0 20px 20px 20px;
  }

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
