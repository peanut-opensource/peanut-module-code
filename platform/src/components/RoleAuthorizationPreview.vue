<template>
  <el-dialog
    :model-value="true"
    title="角色授权候选预览"
    width="min(880px, 94vw)"
    @update:model-value="onDialogChange"
  >
    <div v-loading="directoryLoading" class="preview-workspace">
      <el-alert v-if="directoryError" :title="directoryError" type="error" :closable="false" />
      <el-button v-if="directoryError" @click="loadDirectory">重新读取目录</el-button>
      <template v-if="directoryReady">
        <el-form label-position="top" @submit.prevent>
          <el-form-item label="只读范围">
            <el-radio-group v-model="targetMode">
              <el-radio value="operator">当前操作员的角色</el-radio>
              <el-radio v-if="canCatalogAudit()" value="catalog">完整 Platform 角色目录（含无成员角色）</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="targetMode === 'operator'" label="操作员">
            <el-select v-model="operatorId" filterable placeholder="选择操作员">
              <el-option
                v-for="operator in directory.operators"
                :key="operator.id"
                :label="operator.display_name + '（' + operator.id + '）'"
                :value="operator.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="候选来源">
            <el-radio-group v-model="candidateMode">
              <el-radio value="shell">Platform Shell 正式定义</el-radio>
              <el-radio value="manual">高级：自定义 JSON</el-radio>
            </el-radio-group>
            <p v-if="candidateMode === 'shell'" class="preview-help">
              候选显示 Platform Shell 授权页面和已接入的操作。概览沿用当前会话入口。
              存储空间与上传路由的两种操作分别使用分项读取或旧聚合读取。节点关联来自已登记的 Platform API 目录。范围外的现有能力可能显示为移除。
            </p>
          </el-form-item>
          <el-form-item v-if="candidateMode === 'manual'" label="自定义候选节点">
            <el-input
              v-model="candidateText"
              type="textarea"
              :rows="5"
              placeholder='[{"key":"platform.example","parent_key":null,"type":"page","api_ids":[]}]'
            />
            <p class="preview-help">
              输入候选 JSON，显式填写每个节点的 key、parent_key、type 和 api_ids。
              明确空关联填 []。候选只用于比较，不保存角色授权。
            </p>
          </el-form-item>
          <el-form-item v-if="candidateMode === 'manual'" label="查阅可关联的 Platform API ID">
            <el-select
              v-model="lookupApiId"
              filterable
              clearable
              placeholder="按方法或路径查找"
            >
              <el-option
                v-for="item in assignableApis"
                :key="item.id"
                :label="item.method + ' ' + item.path"
                :value="item.id"
              />
            </el-select>
            <p v-if="lookupApiId" class="preview-help"><code>{{ lookupApiId }}</code></p>
          </el-form-item>
        </el-form>

        <el-alert v-if="candidate.error" :title="candidate.error" type="error" :closable="false" />
        <el-alert
          v-if="candidate.notice"
          :title="candidate.notice"
          type="info"
          :closable="false"
        />

        <template v-if="targetMode === 'catalog' || selectedOperator">
          <template v-if="targetMode === 'catalog'">
            <p>角色目录共 {{ directory.roles.length }} 项；已提供 {{ selections.size }} 项选择，
              其中明确清空 {{ [...selections.values()].filter((keys) => keys.length === 0).length }} 项。
              未提供 {{ directory.roles.length - selections.size }} 项。目录核对不会修改角色或授权。</p>
            <el-table :data="directory.roles" max-height="230" size="small">
              <el-table-column prop="name" label="角色" min-width="150" />
              <el-table-column prop="revision" label="当前修订" width="100" />
              <el-table-column label="选择状态" min-width="125">
                <template #default="{ row }">{{ selectionLabel(row.id) }}</template>
              </el-table-column>
            </el-table>
          </template>
          <p v-if="targetMode === 'operator' && !targetRoles.length">当前目录未显示该操作员的角色。</p>
          <el-alert
            v-if="unlistedRoleKeys.length"
            :title="'目录中缺少角色：' + unlistedRoleKeys.join('、')"
            type="warning"
            :closable="false"
          />
          <el-form v-if="targetRoles.length" label-position="top" @submit.prevent>
            <el-form-item label="选择要编辑的候选角色关系">
              <el-select v-model="roleId" filterable placeholder="选择角色">
                <el-option
                  v-for="role in targetRoles"
                  :key="role.id"
                  :label="role.name + ' · ' + selectionLabel(role.id)"
                  :value="role.id"
                />
              </el-select>
            </el-form-item>
          </el-form>
          <div v-if="roleId !== undefined && candidate.data">
            <div class="preview-actions">
              <span>{{ selectionLabel(roleId) }}</span>
              <el-button @click="setRoleSelection(undefined)">不提供此角色选择</el-button>
              <el-button @click="setRoleSelection([])">明确清空此角色选择</el-button>
            </div>
            <el-tree
              :key="treeRevision + ':' + roleId"
              :data="candidate.data.tree"
              :default-checked-keys="selections.get(roleId) ?? []"
              node-key="key"
              show-checkbox
              check-strictly
              default-expand-all
              :expand-on-click-node="false"
              @check="changeCheckedNodes"
            >
              <template #default="{ data }">
                <span class="preview-tree-node">
                  <span>{{ nodeTypeLabel[data.type as NodeType] }} · {{ data.label ?? data.key }}</span>
                  <small v-if="data.source">来源 · {{ data.source }}</small>
                  <small v-if="data.api_ids.length">{{ describeApis(data.api_ids) }}</small>
                  <small v-else>明确空关联</small>
                </span>
              </template>
            </el-tree>
          </div>
        </template>

        <div class="preview-actions">
          <el-button
            type="primary"
            :disabled="!candidate.data || (targetMode === 'operator' ? !selectedOperator || previewLoading : !canCatalogAudit() || catalogLoading)"
            :loading="previewLoading || catalogLoading"
            @click="targetMode === 'catalog' ? runCatalogAudit() : runPreview()"
          >{{ targetMode === 'catalog' ? '审计完整角色目录' : '预览候选差异' }}</el-button>
        </div>
        <el-alert v-if="previewError" :title="previewError" type="error" :closable="false" />
        <el-alert v-if="catalogError" :title="catalogError" type="error" :closable="false" />
        <section v-if="catalogResult" class="preview-result" aria-live="polite">
          <h3>全目录逐角色只读审计</h3>
          <p>角色目录覆盖：{{ catalogResult.scope_covers_catalog ? '本次读取全部 ' + catalogResult.catalog_total + ' 项' : '不完整' }}</p>
          <p>当次等价：{{ catalogResult.equivalent ? '等价' : '不等价或有未解决项' }}</p>
          <p v-if="!catalogResult.atomic_snapshot">多批次读取不构成同一事务快照；任何正式迁移前必须重新校验目录与角色修订。</p>
          <p v-else>本次仅作只读比较，不允许直接用于迁移提交。</p>
          <el-table :data="catalogResult.roles" max-height="340" size="small">
            <el-table-column label="角色" min-width="135">
              <template #default="{ row }">{{ roleName(row.role_id) }} <small v-if="row.owner_dynamic">· 动态 Owner</small></template>
            </el-table-column>
            <el-table-column label="成员" width="65" prop="member_count" />
            <el-table-column label="修订（候选/当前）" min-width="125">
              <template #default="{ row }">{{ row.expected_revision ?? '未提供' }} / {{ row.observed_revision ?? '不存在' }}</template>
            </el-table-column>
            <el-table-column label="关系／能力" min-width="115">
              <template #default="{ row }">{{ row.relation_equal ? '相等' : '有差异' }} / {{ row.capability_equal ? '相等' : '有差异' }}</template>
            </el-table-column>
            <el-table-column label="关系 API 差异" min-width="160">
              <template #default="{ row }">新增：{{ describeApis(row.relation.added) }}；移除：{{ describeApis(row.relation.removed) }}</template>
            </el-table-column>
            <el-table-column label="有效能力差异" min-width="160">
              <template #default="{ row }">新增：{{ describeApis(row.capability.added) }}；移除：{{ describeApis(row.capability.removed) }}</template>
            </el-table-column>
            <el-table-column label="独立约束" min-width="150">
              <template #default="{ row }">{{ [...row.non_http_permission_keys, ...row.prerequisite_permission_keys].join('、') || '无' }}</template>
            </el-table-column>
          </el-table>
          <h4 v-if="catalogResult.unresolved.length">未解决项（{{ catalogResult.unresolved.length }}）</h4>
          <ul v-if="catalogResult.unresolved.length">
            <li v-for="(item, index) in catalogResult.unresolved" :key="index">
              {{ unresolvedLabel[item.code] ?? item.code }}
              <span v-if="item.role_id"> · {{ roleName(item.role_id) }}</span>
              <span v-if="item.node_key"> · {{ item.node_key }}</span>
              <span v-if="item.api_id"> · {{ item.api_id }}</span>
            </li>
          </ul>
        </section>
        <section v-if="result" class="preview-result" aria-live="polite">
          <h3>预览差异</h3>
          <p>操作员有效能力：{{ result.capability_equal ? '相等' : '有差异' }}</p>
          <p>逐角色关系：{{ result.relation_coverage_complete ? '相等且候选完整' : '关系有差异或候选未提供（详见下方）' }}</p>
          <p>新增 API：{{ describeApis(result.added) }}</p>
          <p>移除 API：{{ describeApis(result.removed) }}</p>
          <p v-if="result.owner_dynamic">此操作员包含动态 Owner 能力，继续按现有规则评估。</p>
          <p>保留的非 HTTP 权限：{{ result.non_http_permission_keys.join('、') || '无' }}</p>
          <el-table v-if="result.roles.length" :data="result.roles">
            <el-table-column label="角色" min-width="135">
              <template #default="{ row }">
                {{ roleName(row.role_id) }}
                <small v-if="row.owner_dynamic"> · 动态 Owner</small>
              </template>
            </el-table-column>
            <el-table-column label="关系" min-width="78">
              <template #default="{ row }">{{ row.relation_equal ? '相等' : '有差异' }}</template>
            </el-table-column>
            <el-table-column label="新增 API" min-width="120">
              <template #default="{ row }">{{ describeApis(row.added) }}</template>
            </el-table-column>
            <el-table-column label="移除 API" min-width="120">
              <template #default="{ row }">{{ describeApis(row.removed) }}</template>
            </el-table-column>
          </el-table>
          <template v-if="result.unresolved.length">
            <h4>未解决项</h4>
            <ul>
              <li v-for="(item, index) in result.unresolved" :key="index">
                {{ unresolvedLabel[item.code] ?? item.code }}
                <span v-if="item.role_id"> · {{ roleName(item.role_id) }}</span>
                <span v-if="item.node_key"> · {{ item.node_key }}</span>
                <span v-if="item.api_id !== undefined"> · {{ item.api_id }}</span>
                <span v-if="item.permission_key"> · {{ item.permission_key }}</span>
              </li>
            </ul>
          </template>
        </section>
      </template>
    </div>
    <template #footer>
      <el-button @click="close">关闭</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref, watch } from 'vue';
  import { useAsyncAction } from '@peanut-admin/vue';
  import { api } from '../api/platform';
  import {
    auditCompleteRoleCatalog,
    buildPlatformShellCandidate,
    buildRoleAuthorizationCandidate,
    readCandidateNodes,
    type RoleAuthorizationPreview,
    type RoleAuthorizationPreviewDirectory,
    type RoleAuthorizationCatalogAudit,
  } from '../api/role-authorization-preview';

  const props = defineProps<{
    canPreview: () => boolean;
    canCatalogAudit: () => boolean;
    loadDirectory: (signal: AbortSignal) => Promise<RoleAuthorizationPreviewDirectory>;
  }>();
  const emit = defineEmits<{ close: [] }>();
  const directory = ref<RoleAuthorizationPreviewDirectory>({
    operators: [],
    roles: [],
    apis: [],
  });
  const directoryReady = ref(false);
  const targetMode = ref<'operator' | 'catalog'>('operator');
  const operatorId = ref<string>();
  const roleId = ref<string>();
  const candidateMode = ref<'shell' | 'manual'>('shell');
  const candidateText = ref('');
  const lookupApiId = ref('');
  const selections = ref(new Map<string, string[]>());
  const treeRevision = ref(0);
  const result = ref<RoleAuthorizationPreview>();
  const catalogResult = ref<RoleAuthorizationCatalogAudit>();
  let previewRevision = 0;
  const message = (error: unknown) =>
    error instanceof Error ? error.message : '请求失败，请稍后重试。';
  const {
    loading: directoryLoading,
    error: directoryError,
    run: runDirectory,
    cancel: cancelDirectory,
  } = useAsyncAction(message);
  const {
    loading: previewLoading,
    error: previewError,
    run: requestPreview,
    cancel: cancelPreview,
  } = useAsyncAction(message);
  const {
    loading: catalogLoading,
    error: catalogError,
    run: requestCatalog,
    cancel: cancelCatalog,
  } = useAsyncAction(message);

  const nodeTypeLabel = {
    group: '分组',
    page: '菜单',
    action: '按钮',
    operation: '无菜单操作',
  };
  type NodeType = keyof typeof nodeTypeLabel;
  const unresolvedLabel: Record<string, string> = {
    operator_inactive: '操作员未启用',
    role_not_assigned_to_operator: '该角色未分配给操作员',
    role_assignment_missing: '该角色未提供候选选择',
    role_revision_mismatch: '角色修订已变化，请重新读取',
    role_assignment_outside_scope: '选择超出本次角色范围',
    role_missing: '现有角色不存在',
    role_inactive: '现有角色未启用',
    legacy_role_grant_not_active_platform: '现有授权并非有效 Platform 授权',
    role_node_not_assignable: '角色选中的节点不可分配',
    legacy_api_not_active_platform: '现有关联的 API 不是有效 Platform API',
    legacy_default_not_materialized: '现有默认关联未登记',
    registered_node_not_assignable: '已登记的节点不可分配',
    node_parent_invalid: '候选节点的父级无效',
    group_cannot_grant_api: '分组不能授予 API',
    api_not_active_platform: '候选 API 不是有效 Platform API',
  };
  const selectedOperator = computed(() =>
    directory.value.operators.find((operator) => operator.id === operatorId.value)
  );
  const targetRoles = computed(() =>
    targetMode.value === 'catalog' ? directory.value.roles :
    directory.value.roles.filter((role) => selectedOperator.value?.role_keys.includes(role.key))
  );
  const unlistedRoleKeys = computed(() =>
    (selectedOperator.value?.role_keys ?? []).filter(
      (key) => !targetRoles.value.some((role) => role.key === key)
    )
  );
  const assignableApis = computed(() =>
    directory.value.apis.filter(
      (item) =>
        item.audience === 'platform' &&
        item.status === 'active' &&
        item.access_mode === 'platform_permission'
    )
  );
  const apiLabels = computed(
    () => new Map(directory.value.apis.map((item) => [item.id, item.method + ' ' + item.path]))
  );
  const candidate = computed(() => {
    if (
      !directoryReady.value ||
      (candidateMode.value === 'manual' && !candidateText.value.trim())
    )
      return { data: null, error: '', notice: '' };
    try {
      if (candidateMode.value === 'shell') {
        const { notice, ...data } = buildPlatformShellCandidate(directory.value.apis);
        return { data, error: '', notice };
      }
      return { data: readCandidateNodes(candidateText.value), error: '', notice: '' };
    } catch (error) {
      return { data: null, error: message(error), notice: '' };
    }
  });

  function invalidate() {
    previewRevision += 1;
    cancelPreview();
    cancelCatalog();
    result.value = undefined;
    catalogResult.value = undefined;
  }
  function close() {
    cancelDirectory();
    invalidate();
    emit('close');
  }
  function onDialogChange(open: boolean) {
    if (!open) close();
  }
  watch(roleId, invalidate, { flush: 'sync' });
  watch(targetMode, () => {
    roleId.value = undefined;
    selections.value = new Map();
    treeRevision.value += 1;
    invalidate();
  }, { flush: 'sync' });
  watch(
    operatorId,
    () => {
      roleId.value = undefined;
      selections.value.clear();
      invalidate();
    },
    { flush: 'sync' }
  );
  watch(
    [candidateMode, candidateText],
    () => {
      selections.value.clear();
      treeRevision.value += 1;
      invalidate();
    },
    { flush: 'sync' }
  );
  async function loadDirectory() {
    if (!props.canPreview()) return;
    directoryReady.value = false;
    selections.value.clear();
    treeRevision.value += 1;
    invalidate();
    const outcome = await runDirectory(({ signal }) => props.loadDirectory(signal));
    if (outcome.status === 'completed' && props.canPreview()) {
      directory.value = outcome.data;
      directoryReady.value = true;
    }
  }
  onMounted(loadDirectory);

  function selectionLabel(id: string) {
    const count = selections.value.get(id)?.length;
    if (count === undefined) return '未提供选择';
    return count === 0 ? '明确空选择' : `已选 ${count} 项`;
  }
  function updateSelection(keys: string[] | undefined) {
    const id = roleId.value;
    if (id === undefined) return;
    if (keys === undefined) selections.value.delete(id);
    else selections.value.set(id, keys);
    invalidate();
  }
  function setRoleSelection(keys: string[] | undefined) {
    updateSelection(keys);
    treeRevision.value += 1;
  }
  function changeCheckedNodes(
    _node: unknown,
    state: { checkedKeys: (string | number)[] }
  ) {
    updateSelection(
      state.checkedKeys.filter((key): key is string => typeof key === 'string')
    );
  }
  function describeApis(ids: string[]) {
    return ids.map((id) => apiLabels.value.get(id) ?? id).join('、') || '无';
  }
  function roleName(id: string) {
    return directory.value.roles.find((role) => role.id === id)?.name ?? id;
  }
  async function runCatalogAudit() {
    const draft = candidate.value.data;
    if (!props.canPreview() || !props.canCatalogAudit() || !draft || catalogLoading.value) return;
    catalogResult.value = undefined;
    const revision = previewRevision;
    const outcome = await requestCatalog(({ signal }) =>
      auditCompleteRoleCatalog(directory.value, selections.value, api.auditRoleAuthorizationCatalog, signal)
    );
    if (outcome.status === 'completed' && revision === previewRevision && props.canPreview() && props.canCatalogAudit())
      catalogResult.value = outcome.data;
  }
  async function runPreview() {
    const operator = selectedOperator.value;
    const draft = candidate.value.data;
    if (!props.canPreview() || !operator || !draft || previewLoading.value) return;
    result.value = undefined;
    const revision = previewRevision;
    const outcome = await requestPreview(({ signal }) => {
      const input = buildRoleAuthorizationCandidate(
        operator.id,
        draft.nodes,
        targetRoles.value.map((role) => role.id),
        selections.value
      );
      return api.previewRoleAuthorization(input, signal);
    });
    if (
      outcome.status === 'completed' &&
      revision === previewRevision &&
      props.canPreview()
    )
      result.value = outcome.data;
  }
</script>

<style scoped>
  .preview-workspace {
    min-height: 4rem;
    display: grid;
    gap: 12px;
  }
  .preview-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-block: 8px;
  }
  .preview-help,
  .preview-tree-node,
  .preview-result {
    overflow-wrap: anywhere;
    min-width: 0;
  }
  .preview-tree-node {
    display: grid;
    min-width: 0;
    padding-block: 4px;
    white-space: normal;
  }
  .preview-tree-node small,
  .preview-help {
    color: var(--el-text-color-secondary);
  }
  .preview-workspace :deep(.el-tree-node__content) {
    height: auto;
    min-height: 26px;
  }
</style>
