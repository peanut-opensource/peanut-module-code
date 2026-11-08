<script setup lang="ts">
  import { computed, onMounted, reactive, ref } from 'vue';
  import type { FormInstance, FormRules } from 'element-plus';
  import { useI18n } from 'vue-i18n';
  import { useIntegrationSecurityRuntime } from './runtime';

  const { t } = useI18n();
  const errorMessage = (error: { code: string; message: string }) =>
    t(`integrationSecurity.errors.${error.code}`, error.message);
  const runtime = useIntegrationSecurityRuntime();
  const machineDialog = ref(false);
  const webhookDialog = ref(false);
  const attemptDialog = ref(false);
  const machineFormRef = ref<FormInstance>();
  const webhookFormRef = ref<FormInstance>();
  const machineForm = reactive({ name: '', scopes: '', expiresAt: '' });
  const webhookForm = reactive({ name: '', url: '', events: '' });
  const csv = (value: string) => [
    ...new Set(
      value
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean)
    ),
  ];
  const nameRules: FormRules = {
    name: [
      {
        required: true,
        whitespace: true,
        message: t('integrationSecurity.required'),
      },
      { max: 120, message: t('integrationSecurity.nameTooLong') },
    ],
  };
  const machineRules: FormRules = {
    ...nameRules,
    scopes: [
      {
        validator: (_rule, value: string, callback) => {
          const scopes = csv(value);
          if (
            scopes.length < 1 ||
            scopes.length > 32 ||
            scopes.some((scope) => scope.length > 96)
          ) {
            callback(new Error(t('integrationSecurity.invalidScopes')));
            return;
          }
          callback();
        },
      },
    ],
  };
  const webhookRules: FormRules = {
    ...nameRules,
    url: [
      {
        required: true,
        whitespace: true,
        message: t('integrationSecurity.required'),
      },
      {
        validator: (_rule, value: string, callback) => {
          try {
            const url = new URL(value);
            if (url.protocol === 'https:' && value.length <= 2048) {
              callback();
              return;
            }
          } catch {
            // Report invalid and non-HTTPS destinations with the same field message.
          }
          callback(new Error(t('integrationSecurity.invalidHttpsUrl')));
        },
      },
    ],
    events: [
      {
        validator: (_rule, value: string, callback) => {
          const events = csv(value);
          const validEvent = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/;
          if (
            events.length < 1 ||
            events.length > 32 ||
            events.some((event) => event.length > 96 || !validEvent.test(event))
          ) {
            callback(new Error(t('integrationSecurity.invalidEvents')));
            return;
          }
          callback();
        },
      },
    ],
  };
  const activeMachines = computed(
    () =>
      runtime.state.machines.items.filter((item) => item.status === 'active')
        .length
  );
  const activeWebhooks = computed(
    () =>
      runtime.state.webhooks.items.filter((item) => item.status === 'active')
        .length
  );
  const createMachine = async () => {
    if (!(await machineFormRef.value?.validate().catch(() => false))) return;
    await runtime.createMachine({
      name: machineForm.name,
      scopes: csv(machineForm.scopes),
      expires_at: machineForm.expiresAt || null,
    });
    if (runtime.state.machines.error === null) {
      machineDialog.value = false;
      machineFormRef.value?.resetFields();
    }
  };
  const createWebhook = async () => {
    if (!(await webhookFormRef.value?.validate().catch(() => false))) return;
    await runtime.createWebhook({
      name: webhookForm.name,
      url: webhookForm.url,
      events: csv(webhookForm.events),
    });
    if (runtime.state.webhooks.error === null) {
      webhookDialog.value = false;
      webhookFormRef.value?.resetFields();
    }
  };
  const showAttempts = async (deliveryKey: string) => {
    attemptDialog.value = true;
    await runtime.loadAttempts(deliveryKey);
  };
  onMounted(runtime.load);
</script>

<template>
  <main class="integration-security-page">
    <header class="page-header">
      <div
        ><h1>{{ t('integrationSecurity.title') }}</h1
        ><p>{{ t('integrationSecurity.description') }}</p></div
      >
      <el-button @click="runtime.load">{{
        t('integrationSecurity.refresh')
      }}</el-button>
    </header>
    <el-alert
      v-if="runtime.state.disclosure"
      type="warning"
      :closable="false"
      show-icon
    >
      <template #title>
        {{ t('integrationSecurity.storeThis') }}
        {{
          t(
            runtime.state.disclosure.kind === 'machine-token'
              ? 'integrationSecurity.token'
              : 'integrationSecurity.secret'
          )
        }}
        {{ t('integrationSecurity.willNotShowAgain') }}
      </template>
      <code class="disclosure">{{ runtime.state.disclosure.value }}</code>
      <el-button text @click="runtime.clearDisclosure">{{
        t('integrationSecurity.dismiss')
      }}</el-button>
    </el-alert>
    <section class="summary" :aria-label="t('integrationSecurity.summary')">
      <div
        ><strong>{{ activeMachines }}</strong
        ><span>{{ t('integrationSecurity.activeMachines') }}</span></div
      >
      <div
        ><strong>{{ activeWebhooks }}</strong
        ><span>{{ t('integrationSecurity.activeWebhooks') }}</span></div
      >
      <div
        ><strong>{{ runtime.state.deliveries.total }}</strong
        ><span>{{ t('integrationSecurity.webhookDeliveries') }}</span></div
      >
      <div
        ><strong>{{ runtime.state.sessions.items.length }}</strong
        ><span>{{ t('integrationSecurity.signedInDevices') }}</span></div
      >
    </section>

    <section>
      <div class="section-header">
        <h2>{{ t('integrationSecurity.machineIdentities') }}</h2
        ><el-button
          v-if="runtime.can.canManageMachines()"
          @click="machineDialog = true"
        >
          {{ t('integrationSecurity.create') }}
        </el-button>
      </div>
      <el-alert
        v-if="runtime.state.machines.error"
        type="error"
        :title="errorMessage(runtime.state.machines.error)"
        :closable="false"
      />
      <el-table
        v-loading="runtime.state.machines.loading"
        :data="runtime.state.machines.items"
        :empty-text="t('integrationSecurity.noMachineIdentities')"
      >
        <el-table-column
          prop="name"
          :label="t('integrationSecurity.name')"
          min-width="180"
        /><el-table-column
          prop="status"
          :label="t('integrationSecurity.status')"
          width="120"
        />
        <el-table-column
          :label="t('integrationSecurity.token')"
          min-width="180"
        >
          <template #default="{ row }">
            {{ row.tokenPrefix }}...{{ row.tokenLastFour }}
          </template>
        </el-table-column>
        <el-table-column
          :label="t('integrationSecurity.scopes')"
          min-width="240"
        >
          <template #default="{ row }">
            {{ row.scopes.join(', ') }}
          </template>
        </el-table-column>
        <el-table-column
          prop="expiresAt"
          :label="t('integrationSecurity.expiresAt')"
          min-width="190"
        />
        <el-table-column
          prop="lastUsedAt"
          :label="t('integrationSecurity.lastUsedAt')"
          min-width="190"
        />
        <el-table-column
          v-if="runtime.can.canManageMachines()"
          label=""
          width="170"
          align="right"
        >
          <template #default="{ row }">
            <el-button
              text
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.rotateMachine(row)"
            >
              {{ t('integrationSecurity.rotate') }} </el-button
            ><el-button
              text
              type="danger"
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.revokeMachine(row)"
            >
              {{ t('integrationSecurity.revoke') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section>
      <div class="section-header">
        <h2>{{ t('integrationSecurity.webhookEndpoints') }}</h2
        ><el-button
          v-if="runtime.can.canManageWebhooks()"
          @click="webhookDialog = true"
        >
          {{ t('integrationSecurity.create') }}
        </el-button>
      </div>
      <el-alert
        v-if="runtime.state.webhooks.error"
        type="error"
        :title="errorMessage(runtime.state.webhooks.error)"
        :closable="false"
      />
      <el-table
        v-loading="runtime.state.webhooks.loading"
        :data="runtime.state.webhooks.items"
        :empty-text="t('integrationSecurity.noWebhookEndpoints')"
      >
        <el-table-column
          prop="name"
          :label="t('integrationSecurity.name')"
          min-width="160"
        /><el-table-column
          prop="url"
          :label="t('integrationSecurity.httpsDestination')"
          min-width="280"
          show-overflow-tooltip
        />
        <el-table-column
          prop="status"
          :label="t('integrationSecurity.status')"
          width="120"
        /><el-table-column
          :label="t('integrationSecurity.events')"
          min-width="220"
        >
          <template #default="{ row }">
            {{ row.events.join(', ') }}
          </template>
        </el-table-column>
        <el-table-column
          v-if="runtime.can.canManageWebhooks()"
          label=""
          width="180"
          align="right"
        >
          <template #default="{ row }">
            <el-button
              text
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.rotateWebhook(row)"
            >
              {{ t('integrationSecurity.rotateSecret') }} </el-button
            ><el-button
              text
              type="danger"
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.disableWebhook(row)"
            >
              {{ t('integrationSecurity.disable') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section>
      <h2>{{ t('integrationSecurity.webhookDeliveries') }}</h2>
      <el-alert
        v-if="runtime.state.deliveries.error"
        type="error"
        :title="errorMessage(runtime.state.deliveries.error)"
        :closable="false"
      />
      <el-table
        v-loading="runtime.state.deliveries.loading"
        :data="runtime.state.deliveries.items"
        :empty-text="t('integrationSecurity.noWebhookDeliveries')"
      >
        <el-table-column
          prop="eventType"
          :label="t('integrationSecurity.event')"
          min-width="180"
        /><el-table-column
          prop="status"
          :label="t('integrationSecurity.status')"
          width="150"
        />
        <el-table-column
          prop="attemptCount"
          :label="t('integrationSecurity.attempts')"
          width="100"
        /><el-table-column
          prop="lastStatusCode"
          :label="t('integrationSecurity.http')"
          width="90"
        />
        <el-table-column
          prop="lastErrorCode"
          :label="t('integrationSecurity.result')"
          min-width="190"
        /><el-table-column label="" width="100" align="right">
          <template #default="{ row }">
            <el-button text @click="showAttempts(row.deliveryKey)">
              {{ t('integrationSecurity.attempts') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-if="
          runtime.state.deliveries.total > runtime.state.deliveries.pageSize
        "
        layout="prev, pager, next"
        :page-size="runtime.state.deliveries.pageSize"
        :total="runtime.state.deliveries.total"
        :current-page="runtime.state.deliveries.page"
        @current-change="runtime.loadDeliveries"
      />
    </section>

    <section>
      <h2>{{ t('integrationSecurity.signedInDevices') }}</h2>
      <el-alert
        v-if="runtime.state.sessions.error"
        type="error"
        :title="errorMessage(runtime.state.sessions.error)"
        :closable="false"
      />
      <el-table
        v-loading="runtime.state.sessions.loading"
        :data="runtime.state.sessions.items"
        :empty-text="t('integrationSecurity.noSessions')"
      >
        <el-table-column
          :label="t('integrationSecurity.device')"
          min-width="180"
        >
          <template #default="{ row }">
            {{ row.clientKey
            }}<span v-if="row.current">
              ({{ t('integrationSecurity.current') }})</span
            >
          </template>
        </el-table-column>
        <el-table-column
          prop="maskedIp"
          :label="t('integrationSecurity.network')"
          min-width="140"
        />
        <el-table-column
          prop="status"
          :label="t('integrationSecurity.status')"
          width="120"
        /><el-table-column
          prop="lastSeenAt"
          :label="t('integrationSecurity.lastSeen')"
          min-width="190"
        />
        <el-table-column
          v-if="runtime.can.canRevokeSession()"
          label=""
          width="112"
          align="right"
        >
          <template #default="{ row }">
            <el-button
              text
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.revokeSession(row)"
            >
              {{ t('integrationSecurity.revoke') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <el-dialog
      v-model="machineDialog"
      :title="t('integrationSecurity.createMachineIdentity')"
      width="min(520px, 92vw)"
    >
      <el-alert
        v-if="runtime.state.machines.error"
        type="error"
        :title="errorMessage(runtime.state.machines.error)"
        :closable="false"
      />
      <el-form
        ref="machineFormRef"
        :model="machineForm"
        :rules="machineRules"
        label-position="top"
      >
        <el-form-item prop="name" :label="t('integrationSecurity.name')">
          <el-input v-model="machineForm.name" /> </el-form-item
        ><el-form-item prop="scopes" :label="t('integrationSecurity.scopes')">
          <el-input
            v-model="machineForm.scopes"
            :placeholder="t('integrationSecurity.scopesPlaceholder')"
          /> </el-form-item
        ><el-form-item
          prop="expiresAt"
          :label="t('integrationSecurity.expiresAt')"
        >
          <el-input
            v-model="machineForm.expiresAt"
            :placeholder="t('integrationSecurity.expiresAtPlaceholder')"
          />
        </el-form-item> </el-form
      ><template #footer>
        <el-button @click="machineDialog = false">{{
          t('integrationSecurity.cancel')
        }}</el-button
        ><el-button
          type="primary"
          :loading="runtime.state.mutating"
          @click="createMachine"
        >
          {{ t('integrationSecurity.create') }}
        </el-button>
      </template>
    </el-dialog>
    <el-dialog
      v-model="webhookDialog"
      :title="t('integrationSecurity.createWebhookEndpoint')"
      width="min(520px, 92vw)"
    >
      <el-alert
        v-if="runtime.state.webhooks.error"
        type="error"
        :title="errorMessage(runtime.state.webhooks.error)"
        :closable="false"
      />
      <el-form
        ref="webhookFormRef"
        :model="webhookForm"
        :rules="webhookRules"
        label-position="top"
      >
        <el-form-item prop="name" :label="t('integrationSecurity.name')">
          <el-input v-model="webhookForm.name" /> </el-form-item
        ><el-form-item prop="url" :label="t('integrationSecurity.httpsUrl')">
          <el-input v-model="webhookForm.url" /> </el-form-item
        ><el-form-item prop="events" :label="t('integrationSecurity.events')">
          <el-input
            v-model="webhookForm.events"
            :placeholder="t('integrationSecurity.eventsPlaceholder')"
          />
        </el-form-item> </el-form
      ><template #footer>
        <el-button @click="webhookDialog = false">{{
          t('integrationSecurity.cancel')
        }}</el-button
        ><el-button
          type="primary"
          :loading="runtime.state.mutating"
          @click="createWebhook"
        >
          {{ t('integrationSecurity.create') }}
        </el-button>
      </template>
    </el-dialog>
    <el-dialog
      v-model="attemptDialog"
      :title="t('integrationSecurity.deliveryAttempts')"
      width="min(720px, 94vw)"
    >
      <p class="delivery-key">{{ runtime.state.attempts.deliveryKey }}</p>
      <el-alert
        v-if="runtime.state.attempts.error"
        type="error"
        :title="errorMessage(runtime.state.attempts.error)"
        :closable="false"
      /><el-table
        v-loading="runtime.state.attempts.loading"
        :data="runtime.state.attempts.items"
        :empty-text="t('integrationSecurity.noAttempts')"
      >
        <el-table-column
          prop="attemptNumber"
          label="#"
          width="64"
        /><el-table-column
          prop="outcome"
          :label="t('integrationSecurity.outcome')"
          min-width="150"
        /><el-table-column
          prop="responseStatus"
          :label="t('integrationSecurity.http')"
          width="90"
        /><el-table-column
          prop="errorCode"
          :label="t('integrationSecurity.result')"
          min-width="190"
        /><el-table-column
          prop="durationMs"
          :label="t('integrationSecurity.durationMs')"
          width="90"
        />
        <el-table-column
          prop="attemptedAt"
          :label="t('integrationSecurity.attemptedAt')"
          min-width="190"
        />
      </el-table>
      <template #footer>
        <el-button
          :disabled="
            !runtime.state.attempts.deliveryKey ||
            runtime.state.attempts.loading
          "
          @click="
            runtime.state.attempts.deliveryKey &&
              runtime.loadAttempts(runtime.state.attempts.deliveryKey)
          "
          >{{ t('integrationSecurity.refresh') }}</el-button
        >
      </template>
    </el-dialog>
  </main>
</template>

<style scoped>
  .integration-security-page {
    display: grid;
    gap: 24px;
    max-width: 1280px;
    margin: 0 auto;
    padding: 24px;
  }
  .page-header,
  .section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
  }
  .page-header h1,
  .section-header h2 {
    margin: 0;
  }
  .page-header h1 {
    font-size: 24px;
  }
  .page-header p {
    margin: 6px 0 0;
    color: var(--el-text-color-secondary);
  }
  .summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    border: 1px solid var(--el-border-color);
    border-radius: 6px;
  }
  .summary div {
    display: grid;
    gap: 4px;
    padding: 16px;
    border-right: 1px solid var(--el-border-color);
  }
  .summary div:last-child {
    border-right: 0;
  }
  .summary strong {
    font-size: 20px;
  }
  .summary span {
    color: var(--el-text-color-secondary);
  }
  section h2 {
    font-size: 16px;
    margin: 0 0 12px;
  }
  .disclosure,
  .delivery-key {
    display: block;
    overflow-wrap: anywhere;
    margin-top: 8px;
  }
  @media (max-width: 720px) {
    .integration-security-page {
      padding: 16px;
    }
    .summary {
      grid-template-columns: 1fr;
    }
    .summary div {
      border-right: 0;
      border-bottom: 1px solid var(--el-border-color);
    }
    .summary div:last-child {
      border-bottom: 0;
    }
  }
</style>
