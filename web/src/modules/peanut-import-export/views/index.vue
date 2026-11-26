<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.configurationTransfer']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.configurationTransfer') }}</template>

      <el-alert
        :title="$t('configurationTransfer.notice.title')"
        :description="$t('configurationTransfer.notice.description')"
        type="info"
        :closable="false"
        show-icon
      />

      <div class="toolbar">
        <el-button
          v-permission="['peanut.import-export.configuration.export']"
          type="primary"
          :loading="exporting"
          @click="handleExport"
        >
          {{ $t('configurationTransfer.operation.export') }}
        </el-button>
        <span class="toolbar-hint">{{
          $t('configurationTransfer.exportHint')
        }}</span>
      </div>

      <el-divider />

      <el-form label-position="top">
        <el-form-item :label="$t('configurationTransfer.field.package')">
          <el-input
            v-model="packageText"
            type="textarea"
            :rows="14"
            :placeholder="$t('configurationTransfer.field.package.placeholder')"
            spellcheck="false"
            @input="plan = null"
          />
        </el-form-item>

        <el-form-item :label="$t('configurationTransfer.field.conflictPolicy')">
          <el-radio-group v-model="conflictPolicy" @change="plan = null">
            <el-radio value="abort">{{
              $t('configurationTransfer.policy.abort')
            }}</el-radio>
            <el-radio value="overwrite">{{
              $t('configurationTransfer.policy.overwrite')
            }}</el-radio>
            <el-radio value="skip">{{
              $t('configurationTransfer.policy.skip')
            }}</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item
          v-if="secretReferences.length"
          :label="$t('configurationTransfer.field.secretBindings')"
        >
          <div class="secret-list">
            <div
              v-for="reference in secretReferences"
              :key="reference"
              class="secret-row"
            >
              <code>{{ reference }}</code>
              <el-input
                v-model="secretBindings[reference]"
                type="password"
                show-password
                clearable
                :placeholder="
                  $t('configurationTransfer.field.secret.placeholder')
                "
              />
            </div>
          </div>
          <div class="field-hint">{{
            $t('configurationTransfer.secretHint')
          }}</div>
        </el-form-item>

        <el-space>
          <el-button
            v-permission="['peanut.import-export.configuration.dry-run']"
            :loading="planning"
            @click="handleDryRun"
          >
            {{ $t('configurationTransfer.operation.dryRun') }}
          </el-button>
          <el-button
            v-permission="['peanut.import-export.configuration.apply']"
            type="success"
            :loading="applying"
            :disabled="!canApply"
            @click="handleApply"
          >
            {{ $t('configurationTransfer.operation.apply') }}
          </el-button>
        </el-space>
      </el-form>

      <el-card v-if="plan" class="plan-card" shadow="never">
        <template #header>
          <el-space>
            <span>{{ $t('configurationTransfer.plan.title') }}</span>
            <el-tag :type="statusType">{{
              $t(`configurationTransfer.status.${plan.status}`)
            }}</el-tag>
          </el-space>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item
            :label="$t('configurationTransfer.plan.checksum')"
          >
            <code>{{ plan.checksum }}</code>
          </el-descriptions-item>
          <el-descriptions-item :label="$t('configurationTransfer.plan.total')">
            {{ plan.counts.total }}
          </el-descriptions-item>
          <el-descriptions-item
            :label="$t('configurationTransfer.plan.conflicts')"
          >
            {{ plan.counts.conflict }}
          </el-descriptions-item>
        </el-descriptions>

        <el-alert
          v-if="plan.missing_secret_references.length"
          class="plan-alert"
          type="warning"
          :closable="false"
          :title="
            $t('configurationTransfer.plan.missingSecrets', {
              count: plan.missing_secret_references.length,
            })
          "
        />
        <el-alert
          v-if="plan.conflicts.length"
          class="plan-alert"
          type="warning"
          :closable="false"
          :title="
            $t('configurationTransfer.plan.conflictCount', {
              count: plan.conflicts.length,
            })
          "
        />

        <el-table :data="plan.entries" border>
          <el-table-column
            prop="adapter"
            :label="$t('configurationTransfer.plan.adapter')"
            width="190"
          />
          <el-table-column
            prop="key"
            :label="$t('configurationTransfer.plan.key')"
            min-width="220"
          />
          <el-table-column
            :label="$t('configurationTransfer.plan.action')"
            width="150"
          >
            <template #default="{ row }">
              <el-tag :type="actionType(row.action)">{{
                $t(`configurationTransfer.action.${row.action}`)
              }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column
            prop="current_revision"
            :label="$t('configurationTransfer.plan.revision')"
            width="130"
          />
        </el-table>
      </el-card>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref, watch } from 'vue';
  import { ElMessage, ElMessageBox } from 'element-plus';
  import { useI18n } from 'vue-i18n';
  import {
    applyTenantConfiguration,
    dryRunTenantConfiguration,
    exportTenantConfiguration,
    type ConfigurationTransferConflictPolicy,
    type ConfigurationTransferPlan,
  } from '../api';

  const { t } = useI18n();
  const packageText = ref('');
  const conflictPolicy = ref<ConfigurationTransferConflictPolicy>('abort');
  const secretBindings = reactive<Record<string, string>>({});
  const plan = ref<ConfigurationTransferPlan | null>(null);
  const exporting = ref(false);
  const planning = ref(false);
  const applying = ref(false);
  // A successful dry-run is a review of ONE immutable input revision, not
  // permission to apply any later package, secret or conflict policy.
  const inputGeneration = ref(0);
  const plannedGeneration = ref<number | null>(null);
  const canApply = computed(() =>
    plan.value?.can_apply === true &&
    plan.value.status === 'ready' &&
    plannedGeneration.value === inputGeneration.value &&
    !planning.value && !applying.value
  );

  const secretReferences = computed(() => {
    const references = new Set<string>();
    let value: unknown;
    try {
      value = JSON.parse(packageText.value);
    } catch {
      return [];
    }
    collectSecretReferences(value, references);
    return [...references].sort();
  });

  watch(
    secretReferences,
    (references) => {
      const allowed = new Set(references);
      Object.keys(secretBindings).forEach((reference) => {
        if (!allowed.has(reference)) delete secretBindings[reference];
      });
      references.forEach((reference) => {
        if (!Object.prototype.hasOwnProperty.call(secretBindings, reference)) {
          secretBindings[reference] = '';
        }
      });
    },
    { immediate: true, flush: 'sync' }
  );

  watch(
    [packageText, conflictPolicy, secretBindings],
    () => {
      inputGeneration.value += 1;
      plan.value = null;
      plannedGeneration.value = null;
    },
    { deep: true, flush: 'sync' }
  );

  const statusType = computed(() => {
    if (plan.value?.status === 'ready' || plan.value?.status === 'applied')
      return 'success';
    return 'warning';
  });

  const actionType = (action: string) => {
    if (action === 'conflict') return 'danger';
    if (
      action === 'create' ||
      action === 'replace' ||
      action === 'replace-secret'
    )
      return 'warning';
    return 'info';
  };

  const parsedPackage = (): Record<string, unknown> => {
    let value: unknown;
    try {
      value = JSON.parse(packageText.value);
    } catch {
      throw new Error(t('configurationTransfer.error.invalidJson'));
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new Error(t('configurationTransfer.error.invalidJson'));
    }
    return value as Record<string, unknown>;
  };

  const request = () => ({
    // Keep the canonical JSON text intact so the server owns schema/checksum
    // validation and the request remains within the generated API contract.
    package: (() => {
      parsedPackage();
      return packageText.value;
    })(),
    secret_bindings: { ...secretBindings },
    conflict_policy: conflictPolicy.value,
  });

  const handleExport = async () => {
    exporting.value = true;
    try {
      const { data } = await exportTenantConfiguration();
      const blob = new Blob([JSON.stringify(data, null, 2)], {
        type: 'application/json;charset=utf-8',
      });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `peanut-configuration-${new Date()
        .toISOString()
        .slice(0, 10)}.json`;
      anchor.click();
      URL.revokeObjectURL(url);
      ElMessage.success(t('configurationTransfer.message.exported'));
    } finally {
      exporting.value = false;
    }
  };

  const handleDryRun = async () => {
    if (planning.value || applying.value) return;
    planning.value = true;
    plan.value = null;
    plannedGeneration.value = null;
    const basis = inputGeneration.value;
    try {
      const payload = request();
      const { data } = await dryRunTenantConfiguration(payload);
      // A slow dry-run for an older package must not authorize current inputs.
      if (basis === inputGeneration.value) {
        plan.value = data;
        plannedGeneration.value = basis;
      }
    } catch (error) {
      if (basis === inputGeneration.value)
        ElMessage.error(
          error instanceof Error
            ? error.message
            : t('configurationTransfer.error.requestFailed')
        );
    } finally {
      planning.value = false;
    }
  };

  const handleApply = async () => {
    if (!canApply.value || !plan.value) return;
    const basis = inputGeneration.value;
    const checksum = plan.value.checksum;
    // Lock the entire review+confirm+HTTP lifecycle against double-clicks.
    applying.value = true;
    try {
      try {
        await ElMessageBox.confirm(
          t('configurationTransfer.confirm.apply'),
          t('configurationTransfer.confirm.title'),
          { type: 'warning' }
        );
      } catch {
        return;
      }
      if (basis !== inputGeneration.value ||
        plannedGeneration.value !== basis ||
        !plan.value?.can_apply || plan.value.status !== 'ready' ||
        plan.value.checksum !== checksum)
        return;
      const payload = request();
      const { data } = await applyTenantConfiguration(payload);
      const editedDuringRequest = basis !== inputGeneration.value;
      // Clear the secrets before assigning the final applied receipt; the
      // reactive input watcher invalidates the old preview synchronously.
      Object.keys(secretBindings).forEach((reference) => {
        secretBindings[reference] = '';
      });
      if (!editedDuringRequest) {
        plan.value = data;
        plannedGeneration.value = inputGeneration.value;
      } else {
        plan.value = null;
        plannedGeneration.value = null;
      }
      ElMessage.success(t('configurationTransfer.message.applied'));
    } catch (error) {
      plan.value = null;
      plannedGeneration.value = null;
      ElMessage.error(
        (error instanceof Error
          ? error.message
          : t('configurationTransfer.error.requestFailed')) +
          ' ' + t('configurationTransfer.error.applyUnconfirmed')
      );
    } finally {
      applying.value = false;
    }
  };

  const collectSecretReferences = (value: unknown, references: Set<string>) => {
    if (Array.isArray(value)) {
      value.forEach((item) => collectSecretReferences(item, references));
      return;
    }
    if (!value || typeof value !== 'object') return;
    const record = value as Record<string, unknown>;
    const marker = record.$secret;
    if (
      marker &&
      typeof marker === 'object' &&
      typeof (marker as Record<string, unknown>).reference === 'string'
    ) {
      references.add((marker as Record<string, unknown>).reference as string);
      return;
    }
    Object.values(record).forEach((item) =>
      collectSecretReferences(item, references)
    );
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemConfigurationTransfer',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .toolbar {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 20px;
  }

  .toolbar-hint,
  .field-hint {
    color: var(--color-neutral-6);
    font-size: 13px;
  }

  .secret-list {
    width: 100%;
    max-width: 760px;
  }

  .secret-row {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(280px, 2fr);
    gap: 12px;
    align-items: center;
    margin-bottom: 12px;
  }

  .plan-card {
    margin-top: 24px;
  }

  .plan-alert {
    margin-top: 16px;
  }

  code {
    word-break: break-all;
  }
</style>
