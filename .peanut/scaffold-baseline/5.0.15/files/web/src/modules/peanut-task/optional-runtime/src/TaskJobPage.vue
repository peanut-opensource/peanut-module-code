<script setup lang="ts">
  import {
    EmptyState,
    ForbiddenState,
    ModuleUnavailableState,
    PageContent,
    PageHeader,
    PageToolbar,
    SessionExpiredState,
  } from '@peanut-admin/ui-vue';
  import { ElButton, ElPopconfirm } from 'element-plus';
  import { computed, onMounted } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useTaskJobRuntime } from './runtime';

  const runtime = useTaskJobRuntime();
  const { t } = useI18n();
  const { state } = runtime;
  const canManage = computed(runtime.canManage);
  const errorMessage = computed(() =>
    state.error?.messageKey
      ? t(state.error.messageKey, { status: state.error.status ?? '' })
      : state.error?.message ?? ''
  );
  const statusLabel = (status: string): string =>
    t(`taskJobs.status.${status}`);
  const statuses = [
    'queued',
    'running',
    'succeeded',
    'dead',
    'cancelled',
  ] as const;

  onMounted(runtime.load);
</script>

<template>
  <PageContent class="task-job-page">
    <PageHeader>
      {{ $t('taskJobs.title') }}
      <template #actions>
        <ElButton
          :aria-label="$t('taskJobs.action.reload')"
          :loading="state.loading"
          :disabled="state.mutating"
          @click="runtime.load"
        >
          {{ $t('taskJobs.action.reload') }}
        </ElButton>
      </template>
    </PageHeader>
    <PageToolbar :label="$t('taskJobs.filter.status')">
      <ElButton
        v-for="status in statuses"
        :key="status"
        :type="state.status === status ? 'primary' : 'default'"
        :aria-label="
          $t('taskJobs.aria.filterStatus', { status: statusLabel(status) })
        "
        :aria-pressed="state.status === status"
        @click="runtime.setStatus(status)"
      >
        {{ statusLabel(status) }}
      </ElButton>
    </PageToolbar>

    <SessionExpiredState
      v-if="state.error?.status === 401"
      :message="errorMessage"
    />
    <ForbiddenState
      v-else-if="state.error?.status === 403"
      :message="errorMessage"
    />
    <ModuleUnavailableState
      v-else-if="state.error?.status === 503"
      :message="errorMessage"
      @action="runtime.load"
    />
    <section v-else-if="state.error" role="alert" class="task-state">
      <h2>{{ $t('taskJobs.error.title') }}</h2>
      <p>{{ errorMessage }}</p>
      <p v-if="state.error.requestId">
        {{ $t('taskJobs.error.requestId') }}: {{ state.error.requestId }}
      </p>
    </section>
    <div v-else-if="state.loading" class="task-state" role="status">
      {{ $t('taskJobs.loading') }}
    </div>
    <EmptyState
      v-else-if="state.items.length === 0"
      :title="$t('taskJobs.empty.title')"
      :message="$t('taskJobs.empty.message')"
    />
    <div v-else class="task-table-wrap">
      <table class="task-table" :aria-label="$t('taskJobs.table.label')">
        <thead
          ><tr
            ><th>{{ $t('taskJobs.column.type') }}</th
            ><th>{{ $t('taskJobs.column.status') }}</th
            ><th>{{ $t('taskJobs.column.attempts') }}</th
            ><th>{{ $t('taskJobs.column.error') }}</th
            ><th>{{ $t('taskJobs.column.updated') }}</th
            ><th>{{ $t('taskJobs.column.actions') }}</th></tr
          ></thead
        >
        <tbody>
          <tr v-for="job in state.items" :key="job.jobKey">
            <td>{{ job.taskType }}</td
            ><td>{{ statusLabel(job.status) }}</td>
            <td>{{ job.attemptCount }} / {{ job.maxAttempts }}</td>
            <td>{{ job.lastErrorCode ?? '-' }}</td
            ><td>{{ job.updatedAt }}</td>
            <td>
              <ElPopconfirm
                v-if="job.status === 'queued'"
                :title="$t('taskJobs.confirm.cancel')"
                :confirm-button-text="$t('taskJobs.action.confirm')"
                :cancel-button-text="$t('taskJobs.action.keep')"
                @confirm="runtime.cancel(job)"
              >
                <template #reference>
                  <ElButton
                    text
                    :aria-label="
                      $t('taskJobs.aria.cancel', { name: job.taskType })
                    "
                    :disabled="!canManage || state.mutating"
                  >
                    {{ $t('taskJobs.action.cancel') }}
                  </ElButton>
                </template>
              </ElPopconfirm>
              <ElPopconfirm
                v-if="job.status === 'dead'"
                :title="$t('taskJobs.confirm.retry')"
                :confirm-button-text="$t('taskJobs.action.confirm')"
                :cancel-button-text="$t('taskJobs.action.keep')"
                @confirm="runtime.retry(job)"
              >
                <template #reference>
                  <ElButton
                    text
                    :aria-label="
                      $t('taskJobs.aria.retry', { name: job.taskType })
                    "
                    :disabled="!canManage || state.mutating"
                  >
                    {{ $t('taskJobs.action.retry') }}
                  </ElButton>
                </template>
              </ElPopconfirm>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </PageContent>
</template>

<style scoped>
  .task-state {
    padding: 24px 0;
  }
  .task-table-wrap {
    overflow-x: auto;
  }
  .task-table {
    width: 100%;
    border-collapse: collapse;
  }
  .task-table th,
  .task-table td {
    padding: 10px 8px;
    border-bottom: 1px solid var(--el-border-color);
    text-align: left;
  }
</style>
