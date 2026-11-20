<template>
  <div>
    <el-alert
      v-if="articleAction.error.value"
      type="error"
      :closable="false"
      :title="articleAction.error.value"
    />
    <div :class="{ 'article-edit-form': article }">
      <el-card v-if="article" class="article-edit-body">
        <h2>{{ $t('article.field.content') }}</h2>
        <ArticleBodyEditor
          v-if="editableBody"
          ref="bodyEditor"
          :article-id="article.id"
          :context-key="contextKey"
          @saved="onBodySaved"
          @locked="bodyLocked = $event"
          @metadata-ready="bodyMetadataReady = $event"
          @metadata="onMetadataLoaded"
        />
        <ArticleHtmlMigration
          v-else-if="form.is_show === 0"
          :article-id="article.id"
          :context-key="contextKey"
          @locked="bodyLocked = $event"
          @completed="onHtmlMigrated"
        />
        <template v-else>
          <p role="status">{{ $t('article.edit.published') }}</p>
          <pre v-if="form.content.trim()">{{ form.content }}</pre>
          <ArticleBodyHistory
            v-if="form.body_document_id !== null"
            :article-id="article.id"
            :document-id="form.body_document_id"
            :context-key="contextKey"
          />
        </template>
      </el-card>
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        :class="{ 'article-form-fields': article }"
        :label-position="article ? 'top' : 'right'"
        label-width="auto"
        @submit.prevent="submit"
      >
        <h2 v-if="article">{{ $t('article.edit.metadata') }}</h2>
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
        <el-form-item v-if="!article" :label="$t('article.field.content')">
          <p role="status">{{ $t('article.edit.createHint') }}</p>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { Plus } from '@element-plus/icons-vue';
  import {
    ElMessage,
    type FormInstance,
    type FormRules,
    type UploadRequestOptions,
  } from 'element-plus';
  import { useAsyncAction } from '@peanut-admin/vue';
  import { getSessionSnapshot } from '@/utils/auth';
  import FilePicker from '@/components/file-picker/index.vue';
  import { uploadFile, type FileRecord } from '@/modules/peanut-file/api';
  import {
    addArticle,
    type ArticleRecord,
    type ArticleBodyEditor as ArticleEditorMetadata,
    type ArticleHtmlMigrated,
  } from '../api';
  import ArticleBodyEditor from './list/ArticleBodyEditor.vue';
  import ArticleBodyHistory from './list/ArticleBodyHistory.vue';
  import ArticleHtmlMigration from './list/ArticleHtmlMigration.vue';

  const props = withDefaults(
    defineProps<{
      article?: ArticleRecord;
      active?: boolean;
      contextKey: string;
      cateOptions: Array<{ label: string; value: number }>;
    }>(),
    { active: true }
  );
  const emit = defineEmits<{ (event: 'saved'): void }>();
  const { t } = useI18n();
  const formRef = ref<FormInstance>();
  const articleAction = useAsyncAction(() => t('article.error.request'));
  const bodyEditor = ref<InstanceType<typeof ArticleBodyEditor>>();
  const bodyLocked = ref(false);
  const bodyMetadataReady = ref(false);
  const coverUrl = ref(props.article?.image || '');
  const coverGeneration = ref(0);
  const form = ref({
    body_document_id: props.article?.body_document_id ?? null,
    cid: props.article?.cid,
    title: props.article?.title ?? '',
    desc: props.article?.desc ?? '',
    abstract: props.article?.abstract ?? '',
    image: '',
    author: props.article?.author ?? '',
    content: props.article?.content ?? '',
    click_virtual: props.article?.click_virtual ?? 0,
    sort: props.article?.sort ?? 0,
    is_show: props.article?.is_show ?? 0,
  });
  const editableBody = computed(
    () =>
      form.value.is_show === 0 &&
      (form.value.body_document_id !== null || !form.value.content.trim())
  );
  const busy = articleAction.loading;
  const locked = computed(() => busy.value || bodyLocked.value);
  const metadataDisabled = computed(
    () => locked.value || (!!props.article && !bodyMetadataReady.value)
  );
  const snapshot = () => JSON.stringify(form.value);
  const baseline = ref(snapshot());
  const hasUnsaved = computed(
    () => snapshot() !== baseline.value || bodyEditor.value?.hasUnsaved === true
  );
  const canSubmit = computed(
    () =>
      props.active &&
      !metadataDisabled.value &&
      (!props.article || editableBody.value)
  );

  watch(
    () => props.active,
    (active) => {
      if (active) return;
      coverGeneration.value += 1;
      articleAction.cancel();
    },
    { flush: 'sync' }
  );

  const onHtmlMigrated = (value: ArticleHtmlMigrated) => {
    if (value.article_id !== props.article?.id) return;
    form.value.body_document_id = value.document_id;
    baseline.value = snapshot();
    bodyMetadataReady.value = false;
    bodyLocked.value = false;
  };

  const onMetadataLoaded = (value: ArticleEditorMetadata) => {
    if (value.article_id !== props.article?.id) return;
    // Metadata and the original owner revision come from the same read.
    coverGeneration.value += 1;
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
    } = value.article_metadata;
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
    baseline.value = snapshot();
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
      { max: 64, message: t('article.validation.titleLength') },
    ],
    desc: [{ max: 200, message: t('article.validation.textLength') }],
    abstract: [{ max: 200, message: t('article.validation.textLength') }],
    sort: [{ validator: validateRange(0, 9999) }],
    click_virtual: [{ validator: validateRange(0) }],
  };

  const sessionGeneration = getSessionSnapshot().generation;
  let disposed = false;
  const sameSession = () =>
    sessionGeneration === getSessionSnapshot().generation;
  const applyCover = (file: FileRecord | undefined) => {
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
    const generation = coverGeneration.value;
    const current = () =>
      !disposed &&
      sameSession() &&
      generation === coverGeneration.value &&
      props.active &&
      !metadataDisabled.value;
    return {
      key: generation,
      upload: async (options: UploadRequestOptions) => {
        if (!current()) throw new Error('ARTICLE_COVER_CONTEXT_CHANGED');
        const file = await uploadFile(10, options);
        if (!current()) throw new Error('ARTICLE_COVER_CONTEXT_CHANGED');
        if (applyCover(file)) ElMessage.success(t('article.tip.uploadSuccess'));
        return file;
      },
      select: (_urls: string[], files: FileRecord[]) => {
        if (current()) applyCover(files[0]);
      },
    };
  });

  const onBodySaved = () => {
    bodyMetadataReady.value = false;
    baseline.value = snapshot();
    ElMessage.success(t('article.message.success'));
    emit('saved');
  };

  async function submit() {
    if (!canSubmit.value || !sameSession()) return;
    const result = await articleAction.run(async ({ signal }) => {
      const valid = await formRef.value?.validate().catch(() => false);
      const {
        cid,
        title,
        desc,
        abstract,
        image,
        author,
        sort,
        click_virtual: clickVirtual,
      } = form.value;
      if (!valid || cid === undefined || signal.aborted || !sameSession())
        return false;
      const metadata = {
        cid,
        title,
        desc,
        abstract,
        image,
        author,
        sort,
        click_virtual: clickVirtual,
        is_show: 0 as const,
      };
      if (props.article) {
        if (bodyLocked.value || !bodyMetadataReady.value || !editableBody.value)
          return false;
        return bodyEditor.value?.save(metadata);
      }
      return addArticle({ ...metadata, content: '' }, signal);
    });
    if (props.article || result.status !== 'completed' || !result.data) return;
    ElMessage.success(t('article.message.success'));
    emit('saved');
  }

  onBeforeUnmount(() => {
    disposed = true;
    articleAction.cancel();
  });

  defineExpose({ busy, locked, hasUnsaved, canSubmit, submit });
</script>

<style scoped lang="less">
  .article-edit-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(240px, 300px);
    gap: 16px;
    align-items: start;
  }

  .article-edit-form h2 {
    margin: 0 0 16px;
    font-size: 17px;
  }

  .article-edit-body {
    min-height: 440px;
  }

  .article-edit-body pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .article-edit-form .article-form-fields {
    padding: 16px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-light);
    border-radius: var(--el-border-radius-base);
  }

  .img-uploader {
    display: grid;
    width: 96px;
    height: 64px;
    overflow: hidden;
    border: 1px dashed var(--el-border-color);
    border-radius: var(--el-border-radius-base);
    place-items: center;
  }

  .img-uploader img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  @media (max-width: 860px) {
    .article-edit-form {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
