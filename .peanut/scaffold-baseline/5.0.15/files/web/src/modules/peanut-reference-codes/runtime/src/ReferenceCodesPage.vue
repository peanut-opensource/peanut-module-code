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
  import {
    ElButton,
    ElCheckbox,
    ElDialog,
    ElInput,
    ElInputNumber,
  } from 'element-plus';
  import { computed, onMounted, ref } from 'vue';
  import { useI18n } from 'vue-i18n';

  import type { ReferenceCodeEntry } from './contracts';
  import { useReferenceCodesRuntime } from './runtime';

  const runtime = useReferenceCodesRuntime();
  const { t } = useI18n();
  const { state } = runtime;
  const asOfInput = ref('');
  const canManage = computed(() => runtime.canManage());
  const hasPendingMutation = computed(() => state.pendingResources.size > 0);
  const selectedSetValue = computed(() =>
    state.selectedSet === null
      ? ''
      : `${state.selectedSet.moduleKey}\u001f${state.selectedSet.setKey}`
  );
  const pageCount = computed(() =>
    Math.max(1, Math.ceil(state.total / state.pageSize))
  );

  const run = async (operation: () => Promise<void>): Promise<void> => {
    try {
      await operation();
    } catch {
      // Runtime state contains the request error for the page to display.
    }
  };

  const loadSets = async (): Promise<void> => {
    await run(runtime.loadSets);
    asOfInput.value = state.asOf;
  };

  const selectSet = async (value: unknown): Promise<void> => {
    if (typeof value !== 'string' || value === '') return;
    const [moduleKey, setKey] = value.split('\u001f');
    if (moduleKey === undefined || setKey === undefined) return;
    await run(() => runtime.selectSet(moduleKey, setKey));
  };

  const selectValue = (event: Event): string | null => {
    const target = event.currentTarget;
    return target instanceof HTMLSelectElement ? target.value : null;
  };

  const selectSetFromEvent = async (event: Event): Promise<void> => {
    const value = selectValue(event);
    if (value !== null) await selectSet(value);
  };

  const applyAsOf = async (): Promise<void> => {
    await run(async () => {
      await runtime.setAsOf(asOfInput.value);
      asOfInput.value = state.asOf;
    });
  };

  const setEffectiveStatus = async (value: unknown): Promise<void> => {
    if (value !== 'active' && value !== 'inactive' && value !== 'all') return;
    await run(() => runtime.setFilters(value, state.includeRetired));
  };

  const setIncludeRetired = async (value: unknown): Promise<void> => {
    await run(() => runtime.setFilters(state.effectiveStatus, value === true));
  };

  const setPage = async (page: number): Promise<void> => {
    await run(() => runtime.setPage(page));
  };

  const dateLabel = (value: string | null): string =>
    value ?? t('officialReferenceCodes.value.none');
  const effectiveLabel = (entry: ReferenceCodeEntry): string =>
    entry.effective?.label ??
    t('officialReferenceCodes.value.noEffectiveVersion');
  const statusLabel = (entry: ReferenceCodeEntry): string =>
    entry.effective?.status === undefined
      ? t('officialReferenceCodes.value.none')
      : t(`officialReferenceCodes.status.${entry.effective.status}`);
  const metadataLabel = (entry: ReferenceCodeEntry): string =>
    JSON.stringify(entry.effective?.metadata ?? {});
  const canAppend = (entry: ReferenceCodeEntry): boolean =>
    canManage.value &&
    entry.lifecycle === 'active' &&
    entry.effective !== null &&
    !runtime.isPending(entry.code);

  const updateCreateStatus = (value: unknown): void => {
    if (value === 'active' || value === 'inactive')
      runtime.updateCreateDraft({ status: value });
  };

  const updateCreateStatusFromEvent = (event: Event): void =>
    updateCreateStatus(selectValue(event));

  const updateAppendStatus = (value: unknown): void => {
    if (value === 'active' || value === 'inactive')
      runtime.updateAppendDraft({ status: value });
  };

  const updateAppendStatusFromEvent = (event: Event): void =>
    updateAppendStatus(selectValue(event));

  const normalizedNullable = (value: string): string | null =>
    value === '' ? null : value;

  onMounted(loadSets);
</script>

<template>
  <PageContent class="reference-codes-page">
    <PageHeader>
      {{ $t('officialReferenceCodes.title') }}
      <template #actions>
        <ElButton
          :aria-label="$t('officialReferenceCodes.action.reloadCodes')"
          :aria-disabled="hasPendingMutation"
          :disabled="hasPendingMutation"
          :loading="state.loading"
          @click="
            state.selectedSet === null ? loadSets() : run(runtime.loadEntries)
          "
        >
          {{ $t('officialReferenceCodes.action.reload') }}
        </ElButton>
        <ElButton
          :aria-label="$t('officialReferenceCodes.action.createCode')"
          type="primary"
          :disabled="
            !canManage || state.selectedSet === null || hasPendingMutation
          "
          @click="runtime.beginCreate"
        >
          {{ $t('officialReferenceCodes.action.createCode') }}
        </ElButton>
      </template>
    </PageHeader>

    <PageToolbar
      v-if="!state.errors.page"
      :label="$t('officialReferenceCodes.controls')"
    >
      <div class="reference-controls">
        <label for="reference-set">{{
          $t('officialReferenceCodes.field.ownerSet')
        }}</label>
        <select
          id="reference-set"
          :value="selectedSetValue"
          @change="selectSetFromEvent"
        >
          <option disabled value="">
            {{ $t('officialReferenceCodes.selectSet') }}
          </option>
          <option
            v-for="set in state.sets"
            :key="`${set.moduleKey}/${set.setKey}`"
            :value="`${set.moduleKey}\u001f${set.setKey}`"
          >
            {{ set.moduleKey }} / {{ set.name }}
          </option>
        </select>

        <label for="reference-as-of">{{
          $t('officialReferenceCodes.field.asOf')
        }}</label>
        <ElInput
          id="reference-as-of"
          v-model="asOfInput"
          placeholder="2026-07-20T00:00:00.000Z"
          :disabled="state.selectedSet === null || hasPendingMutation"
        />
        <ElButton
          :disabled="state.selectedSet === null || hasPendingMutation"
          @click="applyAsOf"
        >
          {{ $t('officialReferenceCodes.action.applyAsOf') }}
        </ElButton>

        <span id="reference-status-label">{{
          $t('officialReferenceCodes.field.effectiveStatus')
        }}</span>
        <div
          class="reference-segmented"
          aria-labelledby="reference-status-label"
          role="group"
        >
          <ElButton
            :aria-pressed="state.effectiveStatus === 'all'"
            :disabled="state.selectedSet === null || hasPendingMutation"
            :type="state.effectiveStatus === 'all' ? 'primary' : 'default'"
            @click="setEffectiveStatus('all')"
          >
            {{ $t('officialReferenceCodes.status.all') }}
          </ElButton>
          <ElButton
            :aria-pressed="state.effectiveStatus === 'active'"
            :disabled="state.selectedSet === null || hasPendingMutation"
            :type="state.effectiveStatus === 'active' ? 'primary' : 'default'"
            @click="setEffectiveStatus('active')"
          >
            {{ $t('officialReferenceCodes.status.active') }}
          </ElButton>
          <ElButton
            :aria-pressed="state.effectiveStatus === 'inactive'"
            :disabled="state.selectedSet === null || hasPendingMutation"
            :type="state.effectiveStatus === 'inactive' ? 'primary' : 'default'"
            @click="setEffectiveStatus('inactive')"
          >
            {{ $t('officialReferenceCodes.status.inactive') }}
          </ElButton>
        </div>

        <ElCheckbox
          class="retired-checkbox"
          :disabled="state.selectedSet === null || hasPendingMutation"
          :model-value="state.includeRetired"
          @update:model-value="setIncludeRetired"
        >
          {{ $t('officialReferenceCodes.field.includeRetired') }}
        </ElCheckbox>
      </div>
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
      data-reference-codes-state="not-found"
      :title="$t('officialReferenceCodes.notAvailable')"
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
      @action="loadSets"
    />
    <section
      v-else-if="state.errors.page"
      class="reference-state"
      data-reference-codes-state="error"
      role="alert"
      aria-live="polite"
    >
      <h2>{{ $t('officialReferenceCodes.loadError') }}</h2>
      <p>{{ state.errors.page.message }}</p>
      <p v-if="state.errors.page.requestId !== null">
        {{ $t('officialReferenceCodes.requestId') }}:
        {{ state.errors.page.requestId }}
      </p>
      <ElButton
        @click="
          state.selectedSet === null ? loadSets() : run(runtime.loadEntries)
        "
      >
        {{ $t('officialReferenceCodes.action.retry') }}
      </ElButton>
    </section>

    <div
      v-else-if="state.loading"
      class="reference-state"
      data-reference-codes-state="loading"
      role="status"
      aria-live="polite"
    >
      {{ $t('officialReferenceCodes.loading') }}
    </div>

    <EmptyState
      v-else-if="state.sets.length === 0"
      data-reference-codes-state="empty-sets"
      :title="$t('officialReferenceCodes.empty.setsTitle')"
      :message="$t('officialReferenceCodes.empty.setsMessage')"
    />
    <EmptyState
      v-else-if="state.selectedSet === null"
      data-reference-codes-state="select-set"
      :title="$t('officialReferenceCodes.empty.selectTitle')"
      :message="$t('officialReferenceCodes.empty.selectMessage')"
    />
    <EmptyState
      v-else-if="state.entries.length === 0"
      data-reference-codes-state="empty-entries"
      :title="$t('officialReferenceCodes.empty.entriesTitle')"
      :message="$t('officialReferenceCodes.empty.entriesMessage')"
    />

    <section
      v-else
      class="reference-results"
      :aria-label="$t('officialReferenceCodes.entries')"
    >
      <div class="reference-results__summary">
        <span>{{
          $t('officialReferenceCodes.count', { count: state.total })
        }}</span>
        <span>{{
          $t('officialReferenceCodes.asOfValue', { value: state.asOf })
        }}</span>
      </div>
      <div class="reference-table-wrap">
        <table class="reference-table">
          <thead>
            <tr>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.code') }}
              </th>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.label') }}
              </th>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.status') }}
              </th>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.sort') }}
              </th>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.interval') }}
              </th>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.revision') }}
              </th>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.lifecycle') }}
              </th>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.metadata') }}
              </th>
              <th scope="col">
                {{ $t('officialReferenceCodes.field.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="entry in state.entries"
              :key="entry.code"
              :data-reference-code="entry.code"
            >
              <td :data-label="$t('officialReferenceCodes.field.code')">
                <code>{{ entry.code }}</code>
              </td>
              <td :data-label="$t('officialReferenceCodes.field.label')">
                {{ effectiveLabel(entry) }}
              </td>
              <td :data-label="$t('officialReferenceCodes.field.status')">
                {{ statusLabel(entry) }}
              </td>
              <td :data-label="$t('officialReferenceCodes.field.sort')">
                {{
                  entry.effective?.sortOrder ??
                  $t('officialReferenceCodes.value.none')
                }}
              </td>
              <td :data-label="$t('officialReferenceCodes.field.interval')">
                <span>{{
                  dateLabel(entry.effective?.effectiveAt ?? null)
                }}</span>
                <span>
                  {{ $t('officialReferenceCodes.value.to') }}
                  {{ dateLabel(entry.effective?.expiresAt ?? null) }}</span
                >
              </td>
              <td :data-label="$t('officialReferenceCodes.field.revision')">
                {{ entry.revision }}
              </td>
              <td :data-label="$t('officialReferenceCodes.field.lifecycle')">
                {{ $t(`officialReferenceCodes.lifecycle.${entry.lifecycle}`) }}
              </td>
              <td :data-label="$t('officialReferenceCodes.field.metadata')">
                <code>{{ metadataLabel(entry) }}</code>
              </td>
              <td :data-label="$t('officialReferenceCodes.field.actions')">
                <div class="reference-actions">
                  <ElButton
                    :aria-label="
                      $t('officialReferenceCodes.action.appendVersion')
                    "
                    :disabled="!canAppend(entry)"
                    @click="runtime.beginAppend(entry)"
                  >
                    {{ $t('officialReferenceCodes.action.appendVersion') }}
                  </ElButton>
                  <ElButton
                    :aria-label="$t('officialReferenceCodes.action.retire')"
                    :disabled="
                      !canManage ||
                      entry.lifecycle === 'retired' ||
                      runtime.isPending(entry.code)
                    "
                    @click="runtime.beginRetire(entry)"
                  >
                    {{ $t('officialReferenceCodes.action.retire') }}
                  </ElButton>
                </div>
                <div
                  v-if="state.stale[entry.code]"
                  class="reference-stale"
                  role="alert"
                >
                  <span>{{ state.stale[entry.code]?.message }}</span>
                  <ElButton
                    :disabled="hasPendingMutation"
                    @click="run(() => runtime.reloadStale(entry.code))"
                  >
                    {{ $t('officialReferenceCodes.action.reloadStale') }}
                  </ElButton>
                </div>
                <p
                  v-if="state.errors[entry.code]"
                  class="reference-error"
                  role="alert"
                >
                  {{ state.errors[entry.code]?.message }}
                </p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav
        class="reference-pagination"
        :aria-label="$t('officialReferenceCodes.pages')"
      >
        <ElButton
          :aria-label="$t('officialReferenceCodes.action.previous')"
          :disabled="state.page <= 1 || state.loading || hasPendingMutation"
          @click="setPage(state.page - 1)"
        >
          {{ $t('officialReferenceCodes.action.previous') }}
        </ElButton>
        <span>{{
          $t('officialReferenceCodes.pageOf', {
            page: state.page,
            count: pageCount,
          })
        }}</span>
        <ElButton
          :aria-label="$t('officialReferenceCodes.action.next')"
          :disabled="
            state.page >= pageCount || state.loading || hasPendingMutation
          "
          @click="setPage(state.page + 1)"
        >
          {{ $t('officialReferenceCodes.action.next') }}
        </ElButton>
      </nav>
    </section>

    <ElDialog
      class="reference-codes-dialog"
      :model-value="state.createDraft !== null"
      :title="$t('officialReferenceCodes.dialog.create')"
      width="min(640px, calc(100vw - 32px))"
      :close-on-click-modal="false"
      :transition="{ name: 'dialog-fade', css: false }"
      @close="runtime.cancelCreate"
    >
      <form
        v-if="state.createDraft !== null"
        class="reference-form"
        @submit.prevent="run(runtime.create)"
      >
        <label for="reference-create-code">{{
          $t('officialReferenceCodes.field.code')
        }}</label>
        <ElInput
          id="reference-create-code"
          :model-value="state.createDraft.code"
          autocomplete="off"
          @update:model-value="runtime.updateCreateDraft({ code: $event })"
        />
        <label for="reference-create-label">{{
          $t('officialReferenceCodes.field.label')
        }}</label>
        <ElInput
          id="reference-create-label"
          :model-value="state.createDraft.label"
          @update:model-value="runtime.updateCreateDraft({ label: $event })"
        />
        <label for="reference-create-metadata">{{
          $t('officialReferenceCodes.field.metadataJson')
        }}</label>
        <ElInput
          id="reference-create-metadata"
          :model-value="state.createDraft.metadataText"
          :rows="4"
          type="textarea"
          @update:model-value="
            runtime.updateCreateDraft({ metadataText: $event })
          "
        />
        <label for="reference-create-status">{{
          $t('officialReferenceCodes.field.status')
        }}</label>
        <select
          id="reference-create-status"
          :value="state.createDraft.status"
          @change="updateCreateStatusFromEvent"
        >
          <option value="active">
            {{ $t('officialReferenceCodes.status.active') }}
          </option>
          <option value="inactive">
            {{ $t('officialReferenceCodes.status.inactive') }}
          </option>
        </select>
        <label for="reference-create-sort">{{
          $t('officialReferenceCodes.field.sort')
        }}</label>
        <ElInputNumber
          id="reference-create-sort"
          :max="1000000"
          :min="-1000000"
          :model-value="state.createDraft.sortOrder"
          @update:model-value="
            $event !== undefined &&
              runtime.updateCreateDraft({ sortOrder: $event })
          "
        />
        <label for="reference-create-effective">{{
          $t('officialReferenceCodes.field.effectiveAt')
        }}</label>
        <ElInput
          id="reference-create-effective"
          :model-value="state.createDraft.effectiveAt"
          @update:model-value="
            runtime.updateCreateDraft({ effectiveAt: $event })
          "
        />
        <label for="reference-create-expires">{{
          $t('officialReferenceCodes.field.expiresAt')
        }}</label>
        <ElInput
          id="reference-create-expires"
          :model-value="state.createDraft.expiresAt ?? ''"
          :placeholder="$t('officialReferenceCodes.value.none')"
          @update:model-value="
            runtime.updateCreateDraft({ expiresAt: normalizedNullable($event) })
          "
        />
        <p v-if="state.errors.create" class="reference-error" role="alert">
          {{ state.errors.create.message }}
        </p>
        <div
          v-if="state.stale[state.createDraft.code]"
          class="reference-stale"
          data-reference-create-reload
          role="alert"
        >
          <span>{{ state.stale[state.createDraft.code]?.message }}</span>
          <ElButton
            :disabled="hasPendingMutation"
            @click="run(runtime.loadEntries)"
          >
            {{ $t('officialReferenceCodes.action.reloadEntries') }}
          </ElButton>
        </div>
        <div class="reference-form__actions">
          <ElButton @click="runtime.cancelCreate">
            {{ $t('officialReferenceCodes.action.cancel') }}
          </ElButton>
          <ElButton
            native-type="submit"
            type="primary"
            :loading="runtime.isPending(state.createDraft.code)"
          >
            {{ $t('officialReferenceCodes.action.create') }}
          </ElButton>
        </div>
      </form>
    </ElDialog>

    <ElDialog
      class="reference-codes-dialog"
      :model-value="state.appendDraft !== null"
      :title="$t('officialReferenceCodes.dialog.append')"
      width="min(640px, calc(100vw - 32px))"
      :close-on-click-modal="false"
      :transition="{ name: 'dialog-fade', css: false }"
      @close="runtime.cancelAppend"
    >
      <form
        v-if="state.appendDraft !== null"
        class="reference-form"
        @submit.prevent="run(runtime.appendVersion)"
      >
        <label for="reference-append-code">{{
          $t('officialReferenceCodes.field.code')
        }}</label>
        <ElInput
          id="reference-append-code"
          :model-value="state.appendDraft.code"
          disabled
        />
        <label for="reference-append-label">{{
          $t('officialReferenceCodes.field.label')
        }}</label>
        <ElInput
          id="reference-append-label"
          :model-value="state.appendDraft.label"
          @update:model-value="runtime.updateAppendDraft({ label: $event })"
        />
        <label for="reference-append-metadata">{{
          $t('officialReferenceCodes.field.metadataJson')
        }}</label>
        <ElInput
          id="reference-append-metadata"
          :model-value="state.appendDraft.metadataText"
          :rows="4"
          type="textarea"
          @update:model-value="
            runtime.updateAppendDraft({ metadataText: $event })
          "
        />
        <label for="reference-append-status">{{
          $t('officialReferenceCodes.field.status')
        }}</label>
        <select
          id="reference-append-status"
          :value="state.appendDraft.status"
          @change="updateAppendStatusFromEvent"
        >
          <option value="active">
            {{ $t('officialReferenceCodes.status.active') }}
          </option>
          <option value="inactive">
            {{ $t('officialReferenceCodes.status.inactive') }}
          </option>
        </select>
        <label for="reference-append-sort">{{
          $t('officialReferenceCodes.field.sort')
        }}</label>
        <ElInputNumber
          id="reference-append-sort"
          :max="1000000"
          :min="-1000000"
          :model-value="state.appendDraft.sortOrder"
          @update:model-value="
            $event !== undefined &&
              runtime.updateAppendDraft({ sortOrder: $event })
          "
        />
        <label for="reference-append-effective">{{
          $t('officialReferenceCodes.field.effectiveAt')
        }}</label>
        <ElInput
          id="reference-append-effective"
          :model-value="state.appendDraft.effectiveAt"
          @update:model-value="
            runtime.updateAppendDraft({ effectiveAt: $event })
          "
        />
        <label for="reference-append-expires">{{
          $t('officialReferenceCodes.field.expiresAt')
        }}</label>
        <ElInput
          id="reference-append-expires"
          :model-value="state.appendDraft.expiresAt ?? ''"
          :placeholder="$t('officialReferenceCodes.value.none')"
          @update:model-value="
            runtime.updateAppendDraft({ expiresAt: normalizedNullable($event) })
          "
        />
        <div
          v-if="state.stale[state.appendDraft.code]"
          class="reference-stale"
          role="alert"
        >
          <span>{{ state.stale[state.appendDraft.code]?.message }}</span>
          <ElButton
            :disabled="hasPendingMutation"
            @click="run(() => runtime.reloadStale(state.appendDraft!.code))"
          >
            {{ $t('officialReferenceCodes.action.reloadStale') }}
          </ElButton>
        </div>
        <p
          v-if="state.errors[state.appendDraft.code]"
          class="reference-error"
          role="alert"
        >
          {{ state.errors[state.appendDraft.code]?.message }}
        </p>
        <div class="reference-form__actions">
          <ElButton @click="runtime.cancelAppend">
            {{ $t('officialReferenceCodes.action.cancel') }}
          </ElButton>
          <ElButton
            native-type="submit"
            type="primary"
            :loading="runtime.isPending(state.appendDraft.code)"
          >
            {{ $t('officialReferenceCodes.action.appendVersion') }}
          </ElButton>
        </div>
      </form>
    </ElDialog>

    <ElDialog
      class="reference-codes-dialog"
      :model-value="state.retireCode !== null"
      :title="$t('officialReferenceCodes.dialog.retire')"
      width="min(520px, calc(100vw - 32px))"
      :close-on-click-modal="false"
      :transition="{ name: 'dialog-fade', css: false }"
      @close="runtime.cancelRetire"
    >
      <div v-if="state.retireCode !== null" class="reference-retire">
        <p>
          {{
            $t('officialReferenceCodes.retireWarning', {
              code: state.retireCode,
            })
          }}
        </p>
        <p
          v-if="state.errors[state.retireCode]"
          class="reference-error"
          role="alert"
        >
          {{ state.errors[state.retireCode]?.message }}
        </p>
        <div
          v-if="state.stale[state.retireCode]"
          class="reference-stale"
          role="alert"
        >
          <span>{{ state.stale[state.retireCode]?.message }}</span>
          <ElButton
            :disabled="hasPendingMutation"
            @click="run(() => runtime.reloadStale(state.retireCode!))"
          >
            {{ $t('officialReferenceCodes.action.reloadStale') }}
          </ElButton>
        </div>
        <div class="reference-form__actions">
          <ElButton @click="runtime.cancelRetire">
            {{ $t('officialReferenceCodes.action.cancel') }}
          </ElButton>
          <ElButton
            type="danger"
            :loading="runtime.isPending(state.retireCode)"
            @click="run(runtime.retire)"
          >
            {{ $t('officialReferenceCodes.action.retirePermanently') }}
          </ElButton>
        </div>
      </div>
    </ElDialog>
  </PageContent>
</template>

<style scoped>
  .retired-checkbox {
    position: relative;
  }

  .retired-checkbox :deep(.el-checkbox__original) {
    inset: 0;
    width: 100%;
    height: 100%;
    z-index: 2;
    cursor: pointer;
  }

  .retired-checkbox.is-disabled :deep(.el-checkbox__original) {
    cursor: not-allowed;
  }

  .reference-codes-page {
    min-width: 0;
  }

  .reference-controls {
    display: grid;
    grid-template-columns: auto minmax(220px, 1fr) auto minmax(250px, 1fr) auto;
    align-items: center;
    gap: 10px;
    min-width: 0;
    width: 100%;
  }

  .reference-controls :deep(.el-select),
  .reference-controls :deep(.el-input),
  .reference-controls select {
    min-width: 0;
    width: 100%;
  }

  .reference-controls select,
  .reference-form select {
    min-height: 32px;
    padding: 0 10px;
    border: 1px solid var(--el-border-color);
    border-radius: 4px;
    background: var(--el-bg-color);
    color: var(--el-text-color-primary);
  }

  .reference-segmented {
    display: inline-flex;
  }

  .reference-segmented :deep(.el-button) {
    margin-left: 0;
    border-radius: 0;
  }

  .reference-segmented :deep(.el-button:first-child) {
    border-radius: 4px 0 0 4px;
  }

  .reference-segmented :deep(.el-button:last-child) {
    border-radius: 0 4px 4px 0;
  }

  .reference-state {
    padding: 18px 0;
  }

  .reference-state h2,
  .reference-state p {
    margin: 0 0 8px;
    letter-spacing: 0;
    overflow-wrap: anywhere;
  }

  .reference-results {
    min-width: 0;
  }

  .reference-results__summary {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 8px 16px;
    margin: 16px 0 10px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .reference-table-wrap {
    max-width: 100%;
    overflow-x: auto;
  }

  .reference-table {
    width: 100%;
    min-width: 1080px;
    border-collapse: collapse;
    table-layout: fixed;
  }

  .reference-table th,
  .reference-table td {
    padding: 12px 10px;
    border-bottom: 1px solid var(--el-border-color-lighter);
    text-align: left;
    vertical-align: top;
    overflow-wrap: anywhere;
  }

  .reference-table th {
    color: var(--el-text-color-secondary);
    font-size: 12px;
    font-weight: 600;
  }

  .reference-table code,
  .reference-retire code {
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .reference-actions,
  .reference-form__actions,
  .reference-pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .reference-stale,
  .reference-error {
    margin: 8px 0 0;
    color: var(--el-color-danger);
  }

  .reference-stale {
    display: grid;
    gap: 8px;
    padding: 10px;
    border: 1px solid var(--el-color-danger-light-5);
    border-radius: 6px;
    background: var(--el-color-danger-light-9);
  }

  .reference-pagination {
    align-items: center;
    justify-content: flex-end;
    margin-top: 16px;
  }

  .reference-form {
    display: grid;
    grid-template-columns: minmax(110px, 150px) minmax(0, 1fr);
    align-items: center;
    gap: 12px;
    min-width: 0;
  }

  .reference-form :deep(.el-select),
  .reference-form :deep(.el-input),
  .reference-form :deep(.el-input-number),
  .reference-form select {
    width: 100%;
    max-width: 100%;
  }

  .reference-form .reference-error,
  .reference-form .reference-stale,
  .reference-form__actions {
    grid-column: 1 / -1;
  }

  .reference-form__actions {
    justify-content: flex-end;
    margin-top: 4px;
  }

  .reference-retire p {
    margin: 0 0 14px;
    letter-spacing: 0;
    overflow-wrap: anywhere;
  }

  :global(.reference-codes-dialog) {
    display: flex;
    max-height: calc(100dvh - 32px);
    flex-direction: column;
  }

  :global(.reference-codes-dialog .el-dialog__body) {
    min-height: 0;
    overflow-y: auto;
  }

  @media (max-width: 860px) {
    .reference-controls {
      grid-template-columns: minmax(0, 1fr);
      align-items: stretch;
    }

    .reference-table {
      min-width: 0;
    }

    .reference-table thead {
      display: none;
    }

    .reference-table,
    .reference-table tbody,
    .reference-table tr,
    .reference-table td {
      display: block;
      width: 100%;
    }

    .reference-table tr {
      margin-bottom: 12px;
      padding: 8px 12px;
      border: 1px solid var(--el-border-color-lighter);
      border-radius: 6px;
    }

    .reference-table td {
      display: grid;
      grid-template-columns: minmax(100px, 35%) minmax(0, 1fr);
      gap: 10px;
      padding: 8px 0;
    }

    .reference-table td::before {
      content: attr(data-label);
      color: var(--el-text-color-secondary);
      font-size: 12px;
      font-weight: 600;
    }

    .reference-pagination {
      justify-content: space-between;
    }
  }

  @media (max-width: 560px) {
    .reference-form {
      grid-template-columns: minmax(0, 1fr);
    }

    .reference-form .reference-error,
    .reference-form .reference-stale,
    .reference-form__actions {
      grid-column: 1;
    }

    .reference-table td {
      grid-template-columns: minmax(84px, 32%) minmax(0, 1fr);
    }
  }
</style>
