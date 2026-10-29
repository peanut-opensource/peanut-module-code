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
  import { ElButton, ElInput, ElInputNumber, ElSwitch } from 'element-plus';
  import { computed, onMounted } from 'vue';
  import { useI18n } from 'vue-i18n';

  import { settingEditorKind } from './contracts';
  import type { SettingRecord, SettingScalar } from './contracts';
  import { useSettingsRuntime } from './runtime';

  const runtime = useSettingsRuntime();
  const { t } = useI18n();
  const { state } = runtime;
  const canManage = computed(() => runtime.canManage());
  const hasPendingMutation = computed(() => state.pendingResources.size > 0);

  const keyOf = (record: SettingRecord): string =>
    `${record.moduleKey}/${record.settingKey}`;
  const headingId = (record: SettingRecord): string =>
    `setting-heading-${record.moduleKey.length}-${record.moduleKey}-${record.settingKey.length}-${record.settingKey}`;
  const commandLabel = (command: string, record: SettingRecord): string =>
    `${command} ${record.name} (${keyOf(record)})`;
  const enumOptions = (record: SettingRecord): readonly SettingScalar[] =>
    (record.schema.enum ?? []) as readonly SettingScalar[];
  const enumIndex = (record: SettingRecord): number =>
    enumOptions(record).findIndex(
      (option) => option === state.forms[keyOf(record)]?.value
    );
  const hasBooleanValue = (record: SettingRecord): boolean =>
    typeof state.forms[keyOf(record)]?.value === 'boolean';
  const numberValue = (record: SettingRecord): number | null => {
    const value = state.forms[keyOf(record)]?.value;
    return typeof value === 'number' ? value : null;
  };
  const updateEnum = (record: SettingRecord, event: Event): void => {
    const target = event.currentTarget;
    if (!(target instanceof HTMLSelectElement)) return;
    const value = enumOptions(record)[Number(target.value)];
    if (value !== undefined) runtime.updateForm(keyOf(record), value);
  };
  const typeLabel = (record: SettingRecord): string => {
    if (record.secret) return t('officialSettings.type.secret');
    const { type } = record.schema;
    return typeof type === 'string' ? type : type.join(' | ');
  };
  const sourceLabel = (record: SettingRecord): string =>
    record.sourceScope === null
      ? t('officialSettings.value.notConfigured')
      : t(`officialSettings.source.${record.sourceScope}`);
  const dateLabel = (value: string | null): string =>
    value ?? t('officialSettings.value.none');
  const canSave = (record: SettingRecord): boolean => {
    if (!canManage.value || runtime.isPending(keyOf(record))) return false;
    if (state.forms[keyOf(record)]?.dirty !== true) return false;
    if (!record.secret) return true;
    const value = state.forms[keyOf(record)]?.value;
    return typeof value === 'string' && value !== '';
  };

  const load = async (): Promise<void> => {
    try {
      await runtime.load();
    } catch {
      // Runtime state contains the request error for the page to display.
    }
  };

  const save = async (key: string): Promise<void> => {
    try {
      await runtime.save(key);
    } catch {
      // Runtime state contains the request error for the page to display.
    }
  };

  const unset = async (key: string): Promise<void> => {
    try {
      await runtime.unset(key);
    } catch {
      // Runtime state contains the request error for the page to display.
    }
  };

  const reload = async (key: string): Promise<void> => {
    try {
      await runtime.reload(key);
    } catch {
      // Runtime state contains the request error for the page to display.
    }
  };

  onMounted(load);
</script>

<template>
  <PageContent class="settings-page">
    <PageHeader>
      {{ $t('officialSettings.title') }}
      <template #actions>
        <ElButton
          :aria-label="$t('officialSettings.action.reloadSettings')"
          :aria-disabled="hasPendingMutation"
          :disabled="hasPendingMutation"
          :loading="state.loading"
          @click="load"
        >
          {{ $t('officialSettings.action.reload') }}
        </ElButton>
      </template>
    </PageHeader>

    <PageToolbar v-if="!state.errors.page" :label="$t('officialSettings.view')">
      <span>{{
        $t('officialSettings.count', { count: state.records.length })
      }}</span>
    </PageToolbar>

    <SessionExpiredState
      v-if="state.errors.page?.status === 401"
      v-bind="
        state.errors.page.requestId === null
          ? {}
          : { requestId: state.errors.page.requestId }
      "
      :message="state.errors.page.message"
    />

    <ForbiddenState
      v-else-if="state.errors.page?.status === 403"
      v-bind="
        state.errors.page.requestId === null
          ? {}
          : { requestId: state.errors.page.requestId }
      "
      :message="state.errors.page.message"
    />

    <EmptyState
      v-else-if="state.errors.page?.status === 404"
      data-settings-state="not-found"
      :title="$t('officialSettings.notAvailable')"
      v-bind="
        state.errors.page.requestId === null
          ? {}
          : { requestId: state.errors.page.requestId }
      "
      :message="state.errors.page.message"
    />

    <ModuleUnavailableState
      v-else-if="state.errors.page?.status === 503"
      v-bind="
        state.errors.page.requestId === null
          ? {}
          : { requestId: state.errors.page.requestId }
      "
      :message="state.errors.page.message"
      @action="load"
    />

    <section
      v-else-if="state.errors.page"
      class="settings-page__request-error"
      data-settings-state="request-error"
      role="alert"
      aria-live="polite"
    >
      <h2>{{ $t('officialSettings.loadError') }}</h2>
      <p>{{ state.errors.page.message }}</p>
      <p v-if="state.errors.page.requestId !== null">
        {{ $t('officialSettings.requestId') }}:
        {{ state.errors.page.requestId }}
      </p>
      <ElButton @click="load">
        {{ $t('officialSettings.action.retry') }}
      </ElButton>
    </section>

    <EmptyState
      v-else-if="!state.loading && state.groups.length === 0"
      :title="$t('officialSettings.empty.title')"
      :message="$t('officialSettings.empty.message')"
    />

    <section
      v-for="group in state.groups"
      :key="group.moduleKey"
      class="settings-group"
      :data-settings-group="group.moduleKey"
    >
      <h2>{{ group.moduleKey }}</h2>

      <article
        v-for="record in group.definitions"
        :key="keyOf(record)"
        :aria-labelledby="headingId(record)"
        class="setting-item"
        :data-setting-key="keyOf(record)"
      >
        <header class="setting-item__header">
          <div>
            <h3 :id="headingId(record)">
              {{ record.name }}
            </h3>
            <p>{{ record.description }}</p>
          </div>
          <span class="setting-item__type">{{ typeLabel(record) }}</span>
        </header>

        <dl class="setting-item__metadata">
          <div>
            <dt>{{ $t('officialSettings.field.source') }}</dt>
            <dd>{{ sourceLabel(record) }}</dd>
          </div>
          <div>
            <dt>{{ $t('officialSettings.field.configured') }}</dt>
            <dd>{{
              $t(
                record.configured
                  ? 'officialSettings.value.yes'
                  : 'officialSettings.value.no'
              )
            }}</dd>
          </div>
          <div>
            <dt>{{ $t('officialSettings.field.revision') }}</dt>
            <dd>{{ record.revision }}</dd>
          </div>
          <div>
            <dt>{{ $t('officialSettings.field.effective') }}</dt>
            <dd>{{ dateLabel(record.effectiveAt) }}</dd>
          </div>
          <div>
            <dt>{{ $t('officialSettings.field.expires') }}</dt>
            <dd>{{ dateLabel(record.expiresAt) }}</dd>
          </div>
        </dl>

        <div
          v-if="settingEditorKind(record) === 'boolean'"
          class="setting-item__editor"
          data-editor-kind="boolean"
        >
          <label
            v-if="hasBooleanValue(record)"
            :for="`setting-${keyOf(record)}`"
          >
            {{ record.name }}
          </label>
          <span v-else>{{ record.name }}</span>
          <ElSwitch
            v-if="hasBooleanValue(record)"
            :id="`setting-${keyOf(record)}`"
            :model-value="Boolean(state.forms[keyOf(record)]?.value)"
            :disabled="!canManage || runtime.isPending(keyOf(record))"
            @update:model-value="runtime.updateForm(keyOf(record), $event)"
          />
          <div
            v-else
            class="setting-item__boolean-unconfigured"
            :data-boolean-unconfigured="keyOf(record)"
            role="status"
          >
            <span>{{ $t('officialSettings.value.notConfigured') }}</span>
            <ElButton
              :aria-label="
                commandLabel(t('officialSettings.aria.setFalse'), record)
              "
              :disabled="!canManage || runtime.isPending(keyOf(record))"
              @click="runtime.updateForm(keyOf(record), false)"
            >
              {{ $t('officialSettings.action.useFalse') }}
            </ElButton>
            <ElButton
              :aria-label="
                commandLabel(t('officialSettings.aria.setTrue'), record)
              "
              :disabled="!canManage || runtime.isPending(keyOf(record))"
              @click="runtime.updateForm(keyOf(record), true)"
            >
              {{ $t('officialSettings.action.useTrue') }}
            </ElButton>
          </div>
        </div>

        <div
          v-else-if="settingEditorKind(record) === 'number'"
          class="setting-item__editor"
          data-editor-kind="number"
        >
          <label :for="`setting-${keyOf(record)}`">{{ record.name }}</label>
          <ElInputNumber
            :id="`setting-${keyOf(record)}`"
            :model-value="numberValue(record)"
            :disabled="!canManage || runtime.isPending(keyOf(record))"
            controls-position="right"
            @update:model-value="runtime.updateForm(keyOf(record), $event)"
          />
        </div>

        <div
          v-else-if="settingEditorKind(record) === 'enum'"
          class="setting-item__editor"
          data-editor-kind="enum"
        >
          <label :for="`setting-${keyOf(record)}`">{{ record.name }}</label>
          <select
            :id="`setting-${keyOf(record)}`"
            :value="enumIndex(record)"
            :disabled="!canManage || runtime.isPending(keyOf(record))"
            @change="updateEnum(record, $event)"
          >
            <option
              v-for="(option, index) in enumOptions(record)"
              :key="`${typeof option}:${String(option)}`"
              :value="index"
            >
              {{ String(option) }}
            </option>
          </select>
        </div>

        <div
          v-else-if="settingEditorKind(record) === 'string'"
          class="setting-item__editor"
          data-editor-kind="string"
        >
          <label :for="`setting-${keyOf(record)}`">{{ record.name }}</label>
          <ElInput
            :id="`setting-${keyOf(record)}`"
            :model-value="String(state.forms[keyOf(record)]?.value ?? '')"
            :disabled="!canManage || runtime.isPending(keyOf(record))"
            @update:model-value="runtime.updateForm(keyOf(record), $event)"
          />
        </div>

        <div
          v-else-if="settingEditorKind(record) === 'secret'"
          class="setting-item__editor"
          data-editor-kind="secret"
        >
          <label :for="`setting-${keyOf(record)}`">{{ record.name }}</label>
          <ElInput
            :id="`setting-${keyOf(record)}`"
            autocomplete="new-password"
            :disabled="!canManage || runtime.isPending(keyOf(record))"
            :model-value="String(state.forms[keyOf(record)]?.value ?? '')"
            :placeholder="$t('officialSettings.secret.placeholder')"
            :type="runtime.isSecretVisible(keyOf(record)) ? 'text' : 'password'"
            @update:model-value="runtime.updateForm(keyOf(record), $event)"
          />
          <ElButton
            :aria-label="
              commandLabel(
                runtime.isSecretVisible(keyOf(record))
                  ? t('officialSettings.aria.hideSecret')
                  : t('officialSettings.aria.showSecret'),
                record
              )
            "
            :disabled="!canManage || runtime.isPending(keyOf(record))"
            text
            @click="
              runtime.setSecretVisible(
                keyOf(record),
                !runtime.isSecretVisible(keyOf(record))
              )
            "
          >
            {{
              runtime.isSecretVisible(keyOf(record))
                ? $t('officialSettings.action.hideSecret')
                : $t('officialSettings.action.showSecret')
            }}
          </ElButton>
        </div>

        <div
          v-else
          class="setting-item__unsupported"
          data-editor-kind="unsupported"
          role="status"
        >
          {{ $t('officialSettings.readOnly') }}
        </div>

        <div
          v-if="state.errors[keyOf(record)]"
          class="setting-item__error"
          role="alert"
        >
          {{ state.errors[keyOf(record)]?.message }}
        </div>

        <div
          v-if="state.conflicts[keyOf(record)]"
          class="setting-item__conflict"
          role="alert"
        >
          <span>{{ state.conflicts[keyOf(record)]?.message }}</span>
          <ElButton
            :aria-label="
              commandLabel(t('officialSettings.action.reload'), record)
            "
            :aria-disabled="hasPendingMutation"
            :disabled="hasPendingMutation"
            @click="reload(keyOf(record))"
          >
            {{ $t('officialSettings.action.reloadCurrent') }}
          </ElButton>
        </div>

        <div
          v-if="settingEditorKind(record) !== 'unsupported'"
          class="setting-item__actions"
        >
          <ElButton
            :aria-label="
              commandLabel(t('officialSettings.action.save'), record)
            "
            type="primary"
            :disabled="!canSave(record)"
            @click="save(keyOf(record))"
          >
            {{ $t('officialSettings.action.save') }}
          </ElButton>
          <ElButton
            :aria-label="
              commandLabel(t('officialSettings.action.unset'), record)
            "
            :disabled="
              !canManage ||
              runtime.isPending(keyOf(record)) ||
              !state.etags[keyOf(record)]
            "
            @click="unset(keyOf(record))"
          >
            {{ $t('officialSettings.action.unset') }}
          </ElButton>
        </div>
      </article>
    </section>
  </PageContent>
</template>

<style scoped>
  .settings-page {
    min-width: 0;
  }

  .settings-page__request-error {
    padding: 16px 0;
  }

  .settings-page__request-error h2,
  .settings-page__request-error p {
    margin: 0 0 8px;
    letter-spacing: 0;
    overflow-wrap: anywhere;
  }

  .settings-group {
    margin-block: 24px;
  }

  .settings-group > h2 {
    margin: 0 0 12px;
    font-size: 18px;
    letter-spacing: 0;
    overflow-wrap: anywhere;
  }

  .setting-item {
    display: grid;
    min-width: 0;
    gap: 16px;
    padding: 18px 0;
    border-top: 1px solid var(--el-border-color-lighter);
  }

  .setting-item__header {
    display: flex;
    min-width: 0;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
  }

  .setting-item__header h3,
  .setting-item__header p {
    margin: 0;
    letter-spacing: 0;
    overflow-wrap: anywhere;
  }

  .setting-item__header h3 {
    font-size: 16px;
  }

  .setting-item__header p {
    margin-top: 4px;
    color: var(--el-text-color-secondary);
  }

  .setting-item__type {
    flex: none;
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .setting-item__metadata {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    margin: 0;
  }

  .setting-item__metadata div {
    min-width: 0;
  }

  .setting-item__metadata dt {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }

  .setting-item__metadata dd {
    margin: 3px 0 0;
    overflow-wrap: anywhere;
  }

  .setting-item__editor {
    display: grid;
    grid-template-columns: minmax(120px, 180px) minmax(0, 420px) auto;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }

  .setting-item__unsupported,
  .setting-item__error,
  .setting-item__conflict {
    padding: 12px;
    border-radius: 6px;
    background: var(--el-fill-color-light);
  }

  .setting-item__error,
  .setting-item__conflict {
    color: var(--el-color-danger);
  }

  .setting-item__conflict {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }

  .setting-item__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  @media (max-width: 720px) {
    .setting-item__header,
    .setting-item__conflict {
      align-items: stretch;
      flex-direction: column;
    }

    .setting-item__metadata {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .setting-item__editor {
      grid-template-columns: minmax(0, 1fr);
    }

    .setting-item__editor :deep(.el-input),
    .setting-item__editor :deep(.el-input-number),
    .setting-item__editor select {
      width: 100%;
      max-width: 100%;
    }
  }
</style>
