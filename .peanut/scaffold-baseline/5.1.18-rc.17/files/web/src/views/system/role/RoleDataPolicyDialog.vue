<template>
  <el-dialog
    :model-value="true"
    :title="`${role.name} · 数据权限`"
    width="760px"
    :close-on-click-modal="false"
    @close="$emit('close')"
  >
    <p>角色动作权限不自动授予数据范围。组内条件同时满足，多个组取并集。</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <div v-loading="loading">
      <el-select
        v-model="selected"
        placeholder="选择资源操作"
        style="width: 100%"
        :disabled="saving"
        @change="loadPolicy"
      >
        <el-option
          v-for="(item, index) in operations"
          :key="`${item.resource_key}:${item.operation}`"
          :value="index"
          :label="`${item.resource_key} / ${item.operation}`"
        />
      </el-select>
      <el-pagination
        :disabled="saving || loading"
        :current-page="page"
        :page-size="20"
        :total="total"
        layout="prev, pager, next"
        @current-change="loadOperations"
      />
      <el-space v-if="selected !== null" style="margin: 12px 0">
        <el-button :disabled="saving || loading" @click="loadPolicy"
          >重新读取</el-button
        >
        <el-button
          v-permission="['core.role.data-policy.manage']"
          :disabled="saving || loading"
          @click="createPolicy"
          >新建策略</el-button
        >
        <span v-if="revision !== null">修订 {{ revision }}</span>
      </el-space>
      <el-form v-if="policy" label-width="90px" :disabled="saving || loading">
        <el-form-item label="状态"
          ><el-select v-model="policy.status"
            ><el-option value="active" label="启用" /><el-option
              value="disabled"
              label="停用" /></el-select
        ></el-form-item>
        <el-form-item label="原因"
          ><el-input v-model="policy.reason" maxlength="300"
        /></el-form-item>
        <el-form-item label="开始时间"
          ><el-date-picker
            :model-value="dateValue(policy.valid_from)"
            type="datetime"
            clearable
            @update:model-value="policy.valid_from = dateText($event)"
        /></el-form-item>
        <el-form-item label="结束时间"
          ><el-date-picker
            :model-value="dateValue(policy.valid_until)"
            type="datetime"
            clearable
            @update:model-value="policy.valid_until = dateText($event)"
        /></el-form-item>
        <el-card
          v-for="(group, gi) in policy.groups"
          :key="gi"
          style="margin-bottom: 12px"
        >
          <el-input
            v-model="group.name"
            placeholder="条件组名称"
            maxlength="120"
          />
          <div
            v-for="(condition, ci) in group.conditions"
            :key="ci"
            style="margin: 12px 0"
          >
            <el-select
              :model-value="conditionKey(condition)"
              placeholder="选择登记条件"
              style="width: 100%"
              @change="setCondition(condition, $event)"
            >
              <el-option
                v-for="(option, oi) in conditions"
                :key="oi"
                :value="oi"
                :label="`${option.condition_key} · ${option.target_mode}${
                  option.selector_resource_key
                    ? ' · ' + option.selector_resource_key
                    : ''
                }`"
              />
            </el-select>
            <template v-if="condition.target_set">
              <el-input
                v-model="condition.target_set.name"
                placeholder="目标集合名称"
                maxlength="120"
              />
              <p>目标类型：{{ condition.target_set.target_resource_key }}</p>
              <el-input
                :model-value="
                  condition.target_set.targets
                    .map((target) => target.target_id)
                    .join(',')
                "
                placeholder="目标编号，多个以逗号分隔"
                @update:model-value="
                  condition.target_set.targets = String($event)
                    .split(',')
                    .map((id) => id.trim())
                    .filter(Boolean)
                    .map((id) => ({ target_id: id }))
                "
              />
            </template>
            <el-button
              link
              type="danger"
              @click="group.conditions.splice(ci, 1)"
              >移除条件</el-button
            >
          </div>
          <el-space
            ><el-button
              :disabled="group.conditions.length >= 20"
              @click="
                group.conditions.push({ condition_key: '', target_set: null })
              "
              >添加条件</el-button
            ><el-button type="danger" @click="policy.groups.splice(gi, 1)"
              >移除组</el-button
            ></el-space
          >
        </el-card>
        <el-button
          :disabled="policy.groups.length >= 50"
          @click="policy.groups.push({ name: '', conditions: [] })"
          >添加条件组</el-button
        >
      </el-form>
    </div>
    <template #footer
      ><el-button @click="$emit('close')">关闭</el-button
      ><el-button
        v-permission="['core.role.data-policy.manage']"
        type="primary"
        :loading="saving"
        :disabled="!policy || loading"
        @click="save"
        >保存</el-button
      ></template
    >
  </el-dialog>
</template>

<script setup lang="ts">
  import { onBeforeUnmount, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import { registerTenantDisposer } from '@peanut-admin/vue';
  import { getSessionSnapshot } from '@/utils/auth';
  import {
    getPolicyOperations,
    getPolicyConditions,
    getDataPolicy,
    replaceDataPolicy,
    type DataPolicy,
    type PolicyOperation,
    type PolicyConditionOption,
    type PolicyCondition,
  } from '@/api/system/data-policy';

  const props = defineProps<{ role: { id: number; name: string } }>();
  const emit = defineEmits<{ (event: 'close'): void }>();
  const operations = ref<PolicyOperation[]>([]);
  const conditions = ref<PolicyConditionOption[]>([]);
  const selected = ref<number | null>(null);
  const policy = ref<DataPolicy | null>(null);
  const revision = ref<number | null>(null);
  const loading = ref(false);
  const saving = ref(false);
  const error = ref('');
  const page = ref(1);
  const total = ref(0);
  let generation = 0;
  const session = getSessionSnapshot().generation;
  const current = (request: number) =>
    request === generation && getSessionSnapshot().generation === session;
  const location = () => {
    const item =
      selected.value === null ? undefined : operations.value[selected.value];
    if (!item) throw new Error('请选择资源操作');
    return {
      role_id: props.role.id,
      resource_key: item.resource_key,
      operation: item.operation,
    };
  };
  function dateValue(value: string | null): Date | null {
    if (!value) return null;
    // Stored policy dates are UTC SQL timestamps; the picker displays local time.
    return new Date(
      value.includes('T') ? value : `${value.replace(' ', 'T')}Z`
    );
  }
  function dateText(value: Date | null): string | null {
    return value ? value.toISOString() : null;
  }
  function cleanPolicy(data: DataPolicy): DataPolicy {
    return {
      status: data.status,
      reason: data.reason,
      valid_from: dateText(dateValue(data.valid_from)),
      valid_until: dateText(dateValue(data.valid_until)),
      groups: data.groups.map((group) => ({
        name: group.name,
        conditions: group.conditions.map((condition) => ({
          condition_key: condition.condition_key,
          target_set: condition.target_set
            ? {
                name: condition.target_set.name,
                target_resource_key: condition.target_set.target_resource_key,
                targets: condition.target_set.targets.map((target) => ({
                  target_id: target.target_id,
                })),
              }
            : null,
        })),
      })),
    };
  }
  async function loadOperations(next = 1) {
    generation += 1;
    const request = generation;
    loading.value = true;
    error.value = '';
    selected.value = null;
    policy.value = null;
    conditions.value = [];
    try {
      const { data } = await getPolicyOperations(next);
      if (!current(request)) return;
      operations.value = data.items;
      total.value = data.total;
      page.value = next;
    } catch (failure) {
      if (current(request))
        error.value =
          failure instanceof Error ? failure.message : '资源目录读取失败';
    } finally {
      if (current(request)) loading.value = false;
    }
  }
  async function loadPolicy() {
    const target = location();
    generation += 1;
    const request = generation;
    loading.value = true;
    error.value = '';
    policy.value = null;
    revision.value = null;
    conditions.value = [];
    try {
      const catalog = await getPolicyConditions(target);
      if (!current(request)) return;
      conditions.value = catalog.data.conditions;
      const { data } = await getDataPolicy(target);
      if (!current(request)) return;
      policy.value = cleanPolicy(data);
      revision.value = Number(data.revision);
    } catch (failure) {
      if (current(request))
        error.value = `${
          failure instanceof Error ? failure.message : '策略读取失败'
        }。尚未配置时可选择新建策略；已有策略请重新读取。`;
    } finally {
      if (current(request)) loading.value = false;
    }
  }
  function createPolicy() {
    if (getSessionSnapshot().generation !== session) return;
    revision.value = null;
    error.value = '';
    policy.value = {
      status: 'active',
      reason: null,
      valid_from: null,
      valid_until: null,
      groups: [],
    };
  }
  function conditionKey(condition: PolicyCondition) {
    return conditions.value.findIndex(
      (option) =>
        option.condition_key === condition.condition_key &&
        (option.target_mode === 'none'
          ? condition.target_set === null
          : condition.target_set?.target_resource_key ===
            (option.target_mode === 'department'
              ? 'core.department'
              : option.selector_resource_key))
    );
  }
  function setCondition(condition: PolicyCondition, index: number) {
    const option = conditions.value[index];
    if (!option) return;
    condition.condition_key = option.condition_key;
    condition.target_set =
      option.target_mode === 'none'
        ? null
        : {
            name: '',
            target_resource_key:
              option.target_mode === 'department'
                ? 'core.department'
                : option.selector_resource_key ?? '',
            targets: [],
          };
  }
  async function save() {
    if (
      !policy.value ||
      saving.value ||
      getSessionSnapshot().generation !== session
    )
      return;
    const target = location();
    const request = generation;
    saving.value = true;
    error.value = '';
    try {
      const { data } = await replaceDataPolicy(
        target,
        cleanPolicy(policy.value),
        revision.value
      );
      if (!current(request)) return;
      policy.value = cleanPolicy(data);
      revision.value = Number(data.revision);
      ElMessage.success('策略已保存');
    } catch (failure) {
      if (current(request))
        error.value =
          failure instanceof Error
            ? failure.message
            : '保存失败，请保留编辑内容并核对修订';
    } finally {
      if (current(request)) saving.value = false;
    }
  }
  const unregister = registerTenantDisposer('identity-role-data-policy', () => {
    generation += 1;
    policy.value = null;
    operations.value = [];
    conditions.value = [];
    emit('close');
  });
  onBeforeUnmount(() => {
    generation += 1;
    unregister();
  });
  loadOperations();
</script>
