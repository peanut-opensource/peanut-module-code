<template>
  <PageContent class="pa-page-stack">
    <FocusRegion
      v-slot="{ active, enter }"
      :exit-label="$t('article.edit.exitFocus')"
    >
      <div
        class="article-edit-surface pa-page-stack"
        :class="{ 'article-edit-surface-focused': active }"
      >
        <PageHeader>
          <h1>{{ $t('article.modal.edit') }}</h1>
          <template #actions>
            <el-button :disabled="form?.locked" @click="returnToList">
              {{ $t('article.edit.back') }}
            </el-button>
            <el-button v-if="article" v-show="!active" @click="enter">
              {{ $t('article.edit.focus') }}
            </el-button>
            <el-button
              v-if="article && article.is_show === 0"
              type="primary"
              :disabled="!form?.canSubmit"
              :loading="form?.busy"
              @click="form?.submit()"
            >
              {{ $t('article.edit.save') }}
            </el-button>
          </template>
        </PageHeader>
        <p v-if="state === 'loading'" role="status">
          {{ $t('article.edit.loading') }}
        </p>
        <el-alert
          v-else-if="errorMessage"
          :title="errorMessage"
          type="error"
          :closable="false"
        >
          <el-button
            v-if="state === 'requestFailed'"
            link
            type="primary"
            @click="retry"
          >
            {{ $t('article.edit.retry') }}
          </el-button>
        </el-alert>
        <ArticleForm
          v-if="article"
          :key="contextKey"
          ref="form"
          :article="article"
          :context-key="contextKey"
          :cate-options="cateOptions"
        />
      </div>
    </FocusRegion>
  </PageContent>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
  import { ElMessageBox } from 'element-plus';
  import {
    onBeforeRouteLeave,
    onBeforeRouteUpdate,
    useRoute,
    useRouter,
  } from 'vue-router';
  import { useI18n } from 'vue-i18n';
  import { PageContent, PageHeader } from '@peanut-admin/ui-vue';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot } from '@/utils/auth';
  import {
    getArticleCateAll,
    getArticleDetail,
    type ArticleRecord,
  } from '../../api';
  import canEditArticle from '../../edit-access';
  import FocusRegion from '../../components/FocusRegion.vue';
  import ArticleForm from '../ArticleForm.vue';

  const route = useRoute();
  const router = useRouter();
  const user = useUserStore();
  const { t } = useI18n();
  const initialGeneration = getSessionSnapshot().generation;
  const generation = ref(initialGeneration);
  const updateGeneration = () => {
    generation.value = getSessionSnapshot().generation;
  };
  const unsubscribe = user.$subscribe(updateGeneration, { flush: 'sync' });
  window.addEventListener('storage', updateGeneration);

  const contextKey = computed(() =>
    JSON.stringify([
      route.params.id,
      generation.value,
      user.id,
      user.accountId,
      user.tenantName,
      user.role,
      user.permissions,
    ])
  );
  const article = shallowRef<ArticleRecord>();
  const cateOptions = shallowRef<Array<{ label: string; value: number }>>([]);
  const form = ref<InstanceType<typeof ArticleForm>>();
  const state = ref<
    | 'loading'
    | 'ready'
    | 'invalidId'
    | 'missing'
    | 'requestFailed'
    | 'unauthorized'
    | 'contextChanged'
  >('loading');
  const errorMessage = computed(() =>
    state.value === 'loading' || state.value === 'ready'
      ? ''
      : t(`article.edit.${state.value}`)
  );
  const reload = ref(0);
  const retry = () => {
    reload.value += 1;
  };

  watch(
    [contextKey, reload],
    async ([key], _, onCleanup) => {
      article.value = undefined;
      cateOptions.value = [];
      if (initialGeneration !== getSessionSnapshot().generation) {
        state.value = 'contextChanged';
        return;
      }
      if (!canEditArticle(user.permissions)) {
        state.value = 'unauthorized';
        return;
      }
      const raw = route.params.id;
      const id = Number(raw);
      if (
        typeof raw !== 'string' ||
        !/^[1-9]\d*$/.test(raw) ||
        !Number.isSafeInteger(id)
      ) {
        state.value = 'invalidId';
        return;
      }
      state.value = 'loading';
      const controller = new AbortController();
      onCleanup(() => controller.abort());
      const current = () =>
        !controller.signal.aborted &&
        contextKey.value === key &&
        initialGeneration === getSessionSnapshot().generation;
      try {
        const { data } = await getArticleDetail(id, controller.signal);
        if (!current()) return;
        if (Array.isArray(data) && data.length === 0) {
          state.value = 'missing';
          return;
        }
        if (
          !data ||
          Array.isArray(data) ||
          data.id !== id ||
          typeof data.content !== 'string' ||
          ![0, 1].includes(data.is_show) ||
          (data.body_document_id !== null &&
            (!Number.isSafeInteger(data.body_document_id) ||
              data.body_document_id < 1))
        ) {
          state.value = 'requestFailed';
          return;
        }
        article.value = data;
        state.value = 'ready';
        const categories = await getArticleCateAll(controller.signal);
        if (!current()) return;
        cateOptions.value = categories.data.map(({ name, id: value }) => ({
          label: name,
          value,
        }));
      } catch {
        // Keep the editor available if only the category options request fails.
        if (current() && !article.value) state.value = 'requestFailed';
      }
    },
    { immediate: true }
  );

  const returnToList = () => router.push({ name: 'ArticleList' });

  async function confirmLeave() {
    if (form.value?.locked) return false;
    if (!form.value?.hasUnsaved) return true;
    try {
      await ElMessageBox.confirm(
        t('article.edit.leaveWarning'),
        t('article.edit.leaveTitle'),
        {
          type: 'warning',
          confirmButtonText: t('article.edit.leave'),
          cancelButtonText: t('article.edit.stay'),
        }
      );
      return true;
    } catch {
      return false;
    }
  }

  onBeforeRouteLeave(confirmLeave);
  onBeforeRouteUpdate(
    (to, from) => to.fullPath === from.fullPath || confirmLeave()
  );

  function beforeUnload(event: BeforeUnloadEvent) {
    if (!form.value?.locked && !form.value?.hasUnsaved) return;
    event.preventDefault();
    event.returnValue = '';
  }

  window.addEventListener('beforeunload', beforeUnload);
  onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
    window.removeEventListener('storage', updateGeneration);
    unsubscribe();
  });
</script>

<style scoped>
  .article-edit-surface-focused {
    padding: 16px;
  }
</style>
