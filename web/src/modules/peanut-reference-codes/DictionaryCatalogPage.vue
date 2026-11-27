<script setup lang="ts">
  import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
  import axios from 'axios';
  import { ElMessage } from 'element-plus';
  import { hasPermission } from '@/hooks/permission';
  import { useRoute } from 'vue-router';
  import { useI18n } from 'vue-i18n';

  type Mode = 'system' | 'extend' | 'tenant' | 'disabled';
  interface SystemType {
    code: string;
    name: string;
    is_disable: number;
    tenant_mode: Mode;
    mode: Mode;
    remark: string;
  }
  interface SystemEntry {
    value: string;
    name: string;
    sort: number;
    is_disable: number;
    override: null | { action: 'hide' | 'rename'; name: string | null };
  }

  const types = ref<SystemType[]>([]);
  const { t } = useI18n();
  const route = useRoute();
  const canManage = computed(() =>
    hasPermission('peanut.reference-codes.manage', route.path)
  );
  const selected = ref('');
  const detail = ref<{ type: SystemType; items: SystemEntry[] } | null>(null);
  const loading = ref(false);
  const detailLoading = ref(false);
  const loadError = ref<'types' | 'detail' | null>(null);
  const saving = ref(false);
  const canEdit = computed(() => !loadError.value && detail.value?.type.mode === 'extend');
  const modeOptions = computed(() => [
    { value: 'system', label: t('officialReferenceCodes.catalog.system') },
    { value: 'extend', label: t('officialReferenceCodes.catalog.extend') },
    { value: 'tenant', label: t('officialReferenceCodes.catalog.tenant') },
  ]);

  const base = '/adminapi/api/v1/dictionary-catalog';
  const url = (code = selected.value) => `${base}/${encodeURIComponent(code)}`;
  let typeRequest = 0;
  let detailRequest = 0;
  let disposed = false;
  const loadTypes = async () => {
    const request = ++typeRequest;
    loading.value = true;
    loadError.value = null;
    try {
      const { data } = await axios.get<SystemType[]>(base);
      if (disposed || request !== typeRequest) return;
      types.value = data;
      if (!types.value.some((type) => type.code === selected.value)) {
        selected.value = types.value[0]?.code ?? '';
      }
      await loadDetail();
    } catch {
      if (!disposed && request === typeRequest) loadError.value = 'types';
    } finally {
      if (!disposed && request === typeRequest) loading.value = false;
    }
  };
  const loadDetail = async () => {
    const request = ++detailRequest;
    const code = selected.value;
    detail.value = null;
    loadError.value = null;
    if (!code) {
      detailLoading.value = false;
      return;
    }
    detailLoading.value = true;
    try {
      const { data } = await axios.get<{
        type: SystemType;
        items: SystemEntry[];
      }>(url(code));
      if (disposed || request !== detailRequest || selected.value !== code) return;
      if (data.type.code !== code) {
        loadError.value = 'detail';
        return;
      }
      detail.value = data;
    } catch {
      if (!disposed && request === detailRequest && selected.value === code)
        loadError.value = 'detail';
    } finally {
      if (!disposed && request === detailRequest) detailLoading.value = false;
    }
  };
  const retryLoad = () => {
    if (loadError.value === 'types') void loadTypes();
    else void loadDetail();
  };
  onBeforeUnmount(() => {
    disposed = true;
    ++typeRequest;
    ++detailRequest;
  });
  const setMode = async (mode: Mode) => {
    if (!detail.value || saving.value || loadError.value) return;
    const code = selected.value;
    saving.value = true;
    try {
      await axios.post(`${url(code)}/mode`, { mode });
      await loadTypes();
      ElMessage.success(t('officialReferenceCodes.catalog.modeSaved'));
    } finally {
      saving.value = false;
    }
  };
  const setOverride = async (
    item: SystemEntry,
    action: 'hide' | 'rename' | 'clear'
  ) => {
    if (!detail.value || saving.value || loadError.value) return;
    const code = selected.value;
    saving.value = true;
    try {
      if (action === 'clear') {
        await axios.post(`${url(code)}/override/clear`, { value: item.value });
      } else {
        await axios.post(`${url(code)}/override`, {
          value: item.value,
          action,
          name: action === 'rename' ? item.override?.name ?? item.name : null,
        });
      }
      await loadDetail();
      ElMessage.success(t('officialReferenceCodes.catalog.overrideSaved'));
    } finally {
      saving.value = false;
    }
  };
  const draftName = (item: SystemEntry) => {
    if (!item.override || item.override.action !== 'rename') {
      item.override = { action: 'rename', name: item.name };
    }
  };
  onMounted(loadTypes);
</script>

<template>
  <div class="pa-page-stack">
    <div class="general-card" style="padding: 20px">
      <div class="pa-page-results-toolbar">
        <div>
          <h1 style="margin: 0 0 8px">{{
            t('officialReferenceCodes.catalog.title')
          }}</h1>
          <p style="margin: 0; color: var(--el-text-color-secondary)">
            {{ t('officialReferenceCodes.catalog.description') }}
          </p>
        </div>
        <el-button @click="$router.push('/system/dict')">{{
          t('officialReferenceCodes.catalog.manageCustom')
        }}</el-button>
      </div>
    </div>
    <div class="general-card" style="padding: 20px" v-loading="loading">
      <el-alert
        v-if="loadError"
        type="error"
        :closable="false"
        :title="t('officialReferenceCodes.loadError')"
      >
        <el-button @click="retryLoad">{{ t('officialReferenceCodes.action.retry') }}</el-button>
      </el-alert>
      <el-empty
        v-if="!types.length && !loadError && !loading"
        :description="t('officialReferenceCodes.catalog.noDefaults')"
      />
      <template v-else-if="types.length">
        <el-form label-position="top">
          <el-form-item :label="t('officialReferenceCodes.catalog.type')">
            <el-select
              v-model="selected"
              filterable
              :disabled="saving || loading"
              :loading="detailLoading"
              @change="loadDetail"
            >
              <el-option
                v-for="type in types"
                :key="type.code"
                :label="`${type.name} (${type.code})`"
                :value="type.code"
              />
            </el-select>
          </el-form-item>
          <el-form-item
            v-if="detail && !detailLoading && !loadError"
            :label="t('officialReferenceCodes.catalog.mode')"
          >
            <el-radio-group
              :model-value="detail.type.mode"
              :disabled="
                !canManage ||
                saving ||
                detail.type.tenant_mode === 'system' ||
                detail.type.is_disable !== 0
              "
              @change="setMode($event as Mode)"
            >
              <el-radio
                v-for="option in modeOptions"
                :key="option.value"
                :label="option.value"
                >{{ option.label }}</el-radio
              >
            </el-radio-group>
          </el-form-item>
        </el-form>
        <el-alert
          v-if="detail?.type.tenant_mode === 'system'"
          type="info"
          :closable="false"
          :title="t('officialReferenceCodes.catalog.locked')"
          style="margin-bottom: 16px"
        />
        <el-table v-if="detail && !detailLoading && !loadError" :data="detail.items" row-key="value" border>
          <el-table-column
            prop="value"
            :label="t('officialReferenceCodes.catalog.value')"
            min-width="160"
          />
          <el-table-column
            prop="name"
            :label="t('officialReferenceCodes.catalog.platformName')"
            min-width="150"
          />
          <el-table-column
            :label="t('officialReferenceCodes.catalog.tenantName')"
            min-width="210"
          >
            <template #default="{ row }">
              <el-input
                v-if="
                  canEdit &&
                  row.is_disable === 0 &&
                  row.override?.action === 'rename'
                "
                v-model="row.override.name"
                maxlength="100"
                show-word-limit
              />
              <span v-else>{{
                row.override?.action === 'hide'
                  ? t('officialReferenceCodes.catalog.hidden')
                  : row.override?.name ||
                    t('officialReferenceCodes.catalog.inherited')
              }}</span>
            </template>
          </el-table-column>
          <el-table-column
            :label="t('officialReferenceCodes.catalog.actions')"
            width="270"
          >
            <template #default="{ row }">
              <template v-if="canEdit && row.is_disable === 0">
                <el-button
                  v-permission="['peanut.reference-codes.manage']"
                  link
                  @click="draftName(row)"
                  >{{ t('officialReferenceCodes.catalog.rename') }}</el-button
                >
                <el-button
                  v-if="row.override?.action === 'rename'"
                  v-permission="['peanut.reference-codes.manage']"
                  link
                  :disabled="saving"
                  @click="setOverride(row, 'rename')"
                  >{{ t('officialReferenceCodes.catalog.save') }}</el-button
                >
                <el-button
                  v-if="row.override?.action !== 'hide'"
                  v-permission="['peanut.reference-codes.manage']"
                  link
                  :disabled="saving"
                  @click="setOverride(row, 'hide')"
                  >{{ t('officialReferenceCodes.catalog.hide') }}</el-button
                >
                <el-button
                  v-if="row.override"
                  v-permission="['peanut.reference-codes.manage']"
                  link
                  :disabled="saving"
                  @click="setOverride(row, 'clear')"
                  >{{ t('officialReferenceCodes.catalog.restore') }}</el-button
                >
              </template>
              <span v-else>{{
                t('officialReferenceCodes.catalog.readonly')
              }}</span>
            </template>
          </el-table-column>
        </el-table>
      </template>
    </div>
  </div>
</template>
