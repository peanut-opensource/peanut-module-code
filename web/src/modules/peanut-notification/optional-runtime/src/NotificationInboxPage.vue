<script setup lang="ts">
  import {
    ElAlert,
    ElButton,
    ElCard,
    ElCheckbox,
    ElDialog,
    ElEmpty,
    ElMessage,
    ElPagination,
    ElTabPane,
    ElTabs,
    ElTag,
  } from 'element-plus';
  import { computed, onMounted, ref } from 'vue';
  import { useI18n } from 'vue-i18n';

  import type { NotificationFilter, NotificationMessage } from './contracts';
  import { useNotificationRuntime } from './runtime';

  const { t, locale } = useI18n();
  const runtime = useNotificationRuntime();
  const { state } = runtime;
  const canManage = computed(runtime.canManage);
  const busy = computed(() => state.loading || state.mutating);
  const detail = ref<NotificationMessage | null>(null);
  const detailVisible = ref(false);
  const filters: readonly NotificationFilter[] = [
    'all',
    'unread',
    'read',
    'archived',
  ];
  const selectedMessages = computed(() =>
    state.items.filter((message) => state.selected.has(message.messageKey))
  );
  const readableCount = computed(
    () =>
      selectedMessages.value.filter((message) => message.status === 'unread')
        .length
  );
  const archivableCount = computed(
    () =>
      selectedMessages.value.filter((message) => message.status !== 'archived')
        .length
  );
  const allSelected = computed(
    () => state.items.length > 0 && state.selected.size === state.items.length
  );
  const errorMessage = computed(() =>
    state.error?.messageKey
      ? t(state.error.messageKey)
      : state.error?.message ?? ''
  );
  const errorTitle = computed(() => {
    if (state.error?.status === 401)
      return t('notificationInbox.sessionExpired');
    if (state.error?.status === 403) return t('notificationInbox.forbidden');
    if (state.error?.status === 503) return t('notificationInbox.unavailable');
    return t('notificationInbox.loadError');
  });
  const statusType = (
    message: NotificationMessage
  ): 'primary' | 'success' | 'info' => {
    if (message.status === 'unread') return 'primary';
    if (message.status === 'read') return 'success';
    return 'info';
  };
  const formatTime = (value: string): string =>
    new Date(value).toLocaleString(locale.value);
  const changeStatus = (value: string | number): void => {
    const status = filters.find((filter) => filter === value);
    if (status !== undefined) runtime.setStatus(status);
  };
  const openMessage = (message: NotificationMessage): void => {
    detail.value = message;
    detailVisible.value = true;
  };
  const act = async (
    operation: () => Promise<boolean>,
    action: 'read' | 'archive'
  ): Promise<void> => {
    if (await operation()) {
      detailVisible.value = false;
      ElMessage.success(t(`notificationInbox.${action}Success`));
    }
  };

  onMounted(runtime.load);
</script>

<template>
  <div class="container notification-inbox-page">
    <ElCard class="general-card" shadow="never">
      <template #header>
        <div class="inbox-header">
          <div>
            <h1>{{ t('notificationInbox.title') }}</h1>
            <p>{{ t('notificationInbox.description') }}</p>
          </div>
          <ElButton
            :disabled="busy"
            :loading="state.loading"
            @click="runtime.load"
          >
            {{ t('notificationInbox.refresh') }}
          </ElButton>
        </div>
      </template>

      <ElTabs :model-value="state.status" @update:model-value="changeStatus">
        <ElTabPane
          v-for="filter in filters"
          :key="filter"
          :name="filter"
          :label="t(`notificationInbox.status.${filter}`)"
          :disabled="busy"
        />
      </ElTabs>
      <div class="inbox-toolbar">
        <div class="inbox-selection">
          <ElCheckbox
            :model-value="allSelected"
            :indeterminate="state.selected.size > 0 && !allSelected"
            :disabled="
              busy ||
              !!state.error ||
              !canManage ||
              state.items.length === 0 ||
              !!state.error
            "
            @update:model-value="runtime.selectPage($event === true)"
          >
            {{ t('notificationInbox.selectPage') }}
          </ElCheckbox>
          <span>{{
            t('notificationInbox.selected', { count: state.selected.size })
          }}</span>
        </div>
        <div class="inbox-actions">
          <ElButton
            :disabled="
              busy || !!state.error || !canManage || readableCount === 0
            "
            :loading="state.mutating"
            @click="act(() => runtime.bulk('read'), 'read')"
          >
            {{ t('notificationInbox.bulkRead', { count: readableCount }) }}
          </ElButton>
          <ElButton
            :disabled="
              busy || !!state.error || !canManage || archivableCount === 0
            "
            :loading="state.mutating"
            @click="act(() => runtime.bulk('archive'), 'archive')"
          >
            {{ t('notificationInbox.bulkArchive', { count: archivableCount }) }}
          </ElButton>
        </div>
      </div>
      <p v-if="!canManage" class="inbox-hint">{{
        t('notificationInbox.readOnly')
      }}</p>

      <section v-if="state.error" class="inbox-state" role="alert">
        <ElAlert
          :title="errorTitle"
          :description="errorMessage"
          type="error"
          :closable="false"
          show-icon
        />
        <p v-if="state.error.requestId"
          >{{ t('notificationInbox.requestId') }}：{{
            state.error.requestId
          }}</p
        >
        <ElButton :disabled="busy" @click="runtime.load">{{
          t('notificationInbox.retry')
        }}</ElButton>
      </section>
      <div
        v-else-if="state.loading"
        class="inbox-state"
        role="status"
        aria-live="polite"
        >{{ t('notificationInbox.loading') }}</div
      >
      <ElEmpty
        v-else-if="state.items.length === 0"
        :description="t('notificationInbox.empty')"
      >
        <p class="inbox-hint">{{ t('notificationInbox.emptyHint') }}</p>
      </ElEmpty>
      <section
        v-else
        class="inbox-list"
        :aria-label="t('notificationInbox.title')"
        :aria-busy="state.mutating"
      >
        <article
          v-for="message in state.items"
          :key="message.messageKey"
          class="inbox-item"
          :class="{ 'inbox-item--unread': message.status === 'unread' }"
        >
          <ElCheckbox
            :aria-label="
              t('notificationInbox.selectMessage', { subject: message.subject })
            "
            :disabled="busy || !!state.error || !canManage"
            :model-value="state.selected.has(message.messageKey)"
            @update:model-value="runtime.toggle(message.messageKey)"
          />
          <div class="inbox-item__content">
            <div class="inbox-item__heading">
              <button
                class="inbox-subject"
                type="button"
                @click="openMessage(message)"
                >{{ message.subject }}</button
              >
              <ElTag :type="statusType(message)">{{
                t(`notificationInbox.status.${message.status}`)
              }}</ElTag>
            </div>
            <p class="inbox-preview">{{ message.body }}</p>
            <div class="inbox-item__footer">
              <time :datetime="message.createdAt">{{
                formatTime(message.createdAt)
              }}</time>
              <div class="inbox-actions">
                <ElButton link type="primary" @click="openMessage(message)">{{
                  t('notificationInbox.open')
                }}</ElButton>
                <ElButton
                  v-if="message.status === 'unread'"
                  link
                  type="primary"
                  :disabled="busy || !!state.error || !canManage"
                  @click="act(() => runtime.markRead(message), 'read')"
                  >{{ t('notificationInbox.markRead') }}</ElButton
                >
                <ElButton
                  v-if="message.status !== 'archived'"
                  link
                  :disabled="busy || !!state.error || !canManage"
                  @click="act(() => runtime.archive(message), 'archive')"
                  >{{ t('notificationInbox.archive') }}</ElButton
                >
              </div>
            </div>
          </div>
        </article>
      </section>
      <div v-if="!state.error" class="inbox-pagination">
        <ElPagination
          :current-page="state.page"
          :page-size="state.pageSize"
          :page-sizes="[10, 20, 50]"
          :total="state.total"
          :disabled="busy"
          layout="total, sizes, prev, pager, next"
          @update:current-page="runtime.setPage"
          @update:page-size="runtime.setPageSize"
        />
      </div>
    </ElCard>

    <ElDialog
      v-model="detailVisible"
      :title="t('notificationInbox.detail')"
      width="640px"
      class="notification-detail"
    >
      <template v-if="detail">
        <h2 class="inbox-detail-subject">{{ detail.subject }}</h2>
        <div class="inbox-detail-meta">
          <ElTag :type="statusType(detail)">{{
            t(`notificationInbox.status.${detail.status}`)
          }}</ElTag>
          <time :datetime="detail.createdAt">{{
            formatTime(detail.createdAt)
          }}</time>
        </div>
        <p class="inbox-body">{{ detail.body }}</p>
        <section v-if="detail.attachments.length > 0">
          <h3>{{ t('notificationInbox.attachments') }}</h3>
          <ul>
            <li
              v-for="attachment in detail.attachments"
              :key="attachment.fileKey"
              >{{ attachment.originalName }}（{{ attachment.mediaType }}）</li
            >
          </ul>
        </section>
        <ElAlert
          v-if="state.error"
          :title="errorMessage"
          type="error"
          :closable="false"
        />
      </template>
      <template #footer>
        <ElButton @click="detailVisible = false">{{
          t('notificationInbox.close')
        }}</ElButton>
        <ElButton
          v-if="detail?.status === 'unread'"
          type="primary"
          :disabled="busy || !!state.error || !canManage"
          :loading="state.mutating"
          @click="act(() => runtime.markRead(detail!), 'read')"
          >{{ t('notificationInbox.markRead') }}</ElButton
        >
        <ElButton
          v-if="detail && detail.status !== 'archived'"
          :disabled="busy || !!state.error || !canManage"
          :loading="state.mutating"
          @click="act(() => runtime.archive(detail!), 'archive')"
          >{{ t('notificationInbox.archive') }}</ElButton
        >
      </template>
    </ElDialog>
  </div>
</template>

<style scoped>
  .container {
    padding: 20px;
  }
  .inbox-header,
  .inbox-toolbar,
  .inbox-selection,
  .inbox-actions,
  .inbox-item__heading,
  .inbox-item__footer,
  .inbox-detail-meta {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .inbox-header,
  .inbox-toolbar,
  .inbox-item__heading,
  .inbox-item__footer {
    justify-content: space-between;
  }
  .inbox-header h1 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }
  .inbox-header p,
  .inbox-hint {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .inbox-header p {
    margin: 8px 0 0;
  }
  .inbox-toolbar {
    flex-wrap: wrap;
    padding: 4px 0 16px;
  }
  .inbox-selection {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .inbox-list {
    border-top: 1px solid var(--el-border-color-lighter);
  }
  .inbox-item {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr);
    gap: 12px;
    padding: 20px 12px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }
  .inbox-item--unread {
    background: var(--el-color-primary-light-9);
    border-left: 3px solid var(--el-color-primary);
    padding-left: 9px;
  }
  .inbox-item__content {
    min-width: 0;
  }
  .inbox-item__heading {
    align-items: flex-start;
  }
  .inbox-subject {
    border: 0;
    padding: 0;
    background: transparent;
    color: var(--el-text-color-primary);
    font: inherit;
    font-size: 15px;
    font-weight: 600;
    text-align: left;
    cursor: pointer;
    overflow-wrap: anywhere;
  }
  .inbox-subject:hover {
    color: var(--el-color-primary);
  }
  .inbox-subject:focus-visible {
    outline: 2px solid var(--el-color-primary);
    outline-offset: 4px;
  }
  .inbox-preview {
    display: -webkit-box;
    overflow: hidden;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    color: var(--el-text-color-regular);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.7;
  }
  .inbox-item__footer,
  .inbox-detail-meta {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .inbox-pagination {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
    overflow-x: auto;
  }
  .inbox-state {
    min-height: 180px;
    padding: 24px 0;
  }
  .inbox-state > .el-button {
    margin-top: 16px;
  }
  .inbox-detail-subject {
    margin: 0 0 16px;
    font-size: 18px;
    overflow-wrap: anywhere;
  }
  .inbox-body {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.8;
    margin: 24px 0;
  }
  :deep(.notification-detail) {
    max-width: calc(100vw - 32px);
  }
  @media (max-width: 720px) {
    .container {
      padding: 12px;
    }
    .inbox-toolbar,
    .inbox-item__footer {
      align-items: flex-start;
      flex-direction: column;
    }
    .inbox-actions {
      flex-wrap: wrap;
      gap: 8px;
    }
    .inbox-pagination {
      justify-content: flex-start;
    }
  }
</style>
