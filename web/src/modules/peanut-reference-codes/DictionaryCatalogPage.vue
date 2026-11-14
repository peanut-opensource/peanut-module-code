<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue';
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
  const saving = ref(false);
  const canEdit = computed(() => detail.value?.type.mode === 'extend');
  const modeOptions = computed(() => [
    { value: 'system', label: t('officialReferenceCodes.catalog.system') },
    { value: 'extend', label: t('officialReferenceCodes.catalog.extend') },
    { value: 'tenant', label: t('officialReferenceCodes.catalog.tenant') },
  ]);

  const base = '/adminapi/api/v1/dictionary-catalog';
  const url = () => `${base}/${encodeURIComponent(selected.value)}`;
  const loadTypes = async () => {
    loading.value = true;
    try {
      const { data } = await axios.get<SystemType[]>(base);
      types.value = data;
      if (!types.value.some((type) => type.code === selected.value)) {
        selected.value = types.value[0]?.code ?? '';
      }
      await loadDetail();
    } finally {
      loading.value = false;
    }
  };
  const loadDetail = async () => {
    if (!selected.value) {
      detail.value = null;
      return;
    }
    const { data } = await axios.get<{
      type: SystemType;
      items: SystemEntry[];
    }>(url());
    detail.value = data;
  };
  const setMode = async (mode: Mode) => {
    if (!detail.value) return;
    saving.value = true;
    try {
      await axios.post(`${url()}/mode`, { mode });
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
    saving.value = true;
    try {
      if (action === 'clear') {
        await axios.post(`${url()}/override/clear`, { value: item.value });
      } else {
        await axios.post(`${url()}/override`, {
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
      <el-empty
        v-if="!types.length"
        :description="t('officialReferenceCodes.catalog.noDefaults')"
      />
      <template v-else>
        <el-form label-position="top">
          <el-form-item :label="t('officialReferenceCodes.catalog.type')">
            <el-select v-model="selected" filterable @change="loadDetail">
              <el-option
                v-for="type in types"
                :key="type.code"
                :label="`${type.name} (${type.code})`"
                :value="type.code"
              />
            </el-select>
          </el-form-item>
          <el-form-item
            v-if="detail"
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
        <el-table v-if="detail" :data="detail.items" row-key="value" border>
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
