<template>
  <section v-loading="(section === 'modules' || section === 'diagnostics') && loading">
    <el-alert
      title="仅平台实例级工具；此导航不授权租户业务操作，模块安装与成员授权仍为独立流程。"
      type="info"
      :closable="false"
    />
    <div v-if="section === 'modules' || section === 'diagnostics'" class="toolbar developer-toolbar">
      <el-select
        v-model="selected"
        clearable
        filterable
        placeholder="全部模块"
        @change="load()"
      >
        <el-option
          v-for="item in moduleChoices"
          :key="item.key"
          :label="moduleLabel(item.key, item.name)"
          :value="item.key"
        />
      </el-select>
      <el-button :loading="loading" @click="load">刷新目录</el-button>
    </div>
    <div v-if="section === 'installations'" class="toolbar developer-toolbar">
      <el-input
        v-model="selected"
        clearable
        aria-label="安装状态模块标识"
        placeholder="按 Module key 筛选，留空展示全部安装模块"
        @keyup.enter="loadRuntime(1)"
      />
      <el-button :loading="runtimeLoading" @click="loadRuntime(1)">刷新安装状态</el-button>
    </div>
    <el-alert v-if="(section === 'modules' || section === 'diagnostics') && error" :title="error" type="error" show-icon />
    <el-alert v-if="section === 'installations' && runtimeError" :title="runtimeError" type="error" show-icon />
    <el-card v-if="section === 'official' && canReadUpdates" class="backup-center-card">
      <template #header>官方模块目录与更新（开发者）</template>
      <div class="toolbar developer-toolbar">
        <el-select
          v-model="releaseChannel"
          :disabled="updatesLoading || officialBusy"
          aria-label="官方发行渠道"
          @change="resetOfficialSelection"
        >
          <el-option label="仅稳定版" value="stable" />
          <el-option label="允许预发行版（RC）" value="prerelease" />
        </el-select>
        <el-button
          :loading="updatesLoading"
          :disabled="officialBusy"
          @click="checkOfficialUpdates"
          >{{ platformDeveloperOfficialUpdatesRead.label }}</el-button
        >
      </div>
      <template v-if="officialUpdates">
        <p
          >脚手架：{{
            officialUpdates.scaffold.current_version
          }}；当前渠道可用版本：{{
            officialUpdates.scaffold.available_version || '暂无'
          }}。</p
        >
        <p
          >脚手架升级使用
          <code>peanut upgrade --check</code>
          和原生升级计划；模块升级不会替换应用自有代码。</p
        >
        <p v-if="officialUpdates.withdrawal_policy === 'legacy-index'">
          此官方目录未提供撤回名单，不能据此确认版本尚未撤回。
        </p>
        <h3>可发现的官方模块</h3>
        <el-input
          v-model="catalogSearch"
          clearable
          aria-label="筛选官方模块"
          placeholder="按模块中文名称或标识筛选"
        />
        <el-table
          :data="visibleOfficialCatalog"
          empty-text="当前渠道没有匹配模块"
        >
          <el-table-column label="模块名称 / 标识" min-width="260">
            <template #default="{ row }">{{ moduleLabel(row.key) }}</template>
          </el-table-column>
          <el-table-column prop="version" label="发行版本" min-width="140" />
          <el-table-column label="本地源码" min-width="150">
            <template #default="{ row }">{{
              row.source_version || '未采用'
            }}</template>
          </el-table-column>
          <el-table-column label="兼容性" min-width="190">
            <template #default="{ row }">
              {{ catalogAvailability(row) }}
              <div>Kernel {{ row.kernel_constraint }}</div>
            </template>
          </el-table-column>
          <el-table-column label="前置模块" min-width="240">
            <template #default="{ row }">{{
              catalogDependencies(row)
            }}</template>
          </el-table-column>
          <el-table-column label="操作" min-width="140">
            <template #default="{ row }">
              <el-button
                v-if="canInstallOfficial"
                :disabled="row.availability !== 'available'"
                :loading="officialBusy"
                @click="previewCatalog(row)"
                >预检安装</el-button
              >
            </template>
          </el-table-column>
        </el-table>
        <h3>已采用模块的更新</h3>
        <el-table :data="officialUpdates.modules">
          <el-table-column label="模块名称 / 标识" min-width="260">
            <template #default="{ row }">{{ moduleLabel(row.key) }}</template>
          </el-table-column>
          <el-table-column
            prop="source_version"
            label="当前源码锁版本"
            min-width="160"
          />
          <el-table-column
            prop="available_version"
            label="可用版本"
            min-width="140"
          />
          <el-table-column label="状态" min-width="160">
            <template #default="{ row }">{{
              updateLabels[row.status] || row.status
            }}</template>
          </el-table-column>
          <el-table-column label="操作" min-width="160">
            <template #default="{ row }">
              <el-button
                v-if="canUpdateOfficial && row.status === 'update_available'"
                :loading="officialBusy"
                @click="previewOfficial(row)"
                >预检升级</el-button
              >
            </template>
          </el-table-column>
        </el-table>
        <p
          >发现新版不代表已兼容或已安装。已采用源码的模块仍可预检安装；预检核对源码、依赖、Edition
          和迁移计划，任务执行时再次核对安装状态。版本及 SHA-256 始终固定。</p
        >
      </template>
    </el-card>
    <el-alert
      v-if="section === 'official' && updatesError"
      :title="updatesError"
      type="error"
      show-icon
    />
    <el-card
      v-if="section === 'operations' && (officialSelection || officialPreview)"
      class="backup-center-card"
    >
      <template #header>官方模块预检与确认提交</template>
      <template v-if="officialSelection && (officialPreview || updatesError)">
        <h3
          >{{ officialSelection.module_key }} →
          {{ officialSelection.version }}</h3
        >
        <p
          >发行目录：{{ officialSelection.catalog_tag }}；包 SHA-256：<code>{{
            officialSelection.sha256
          }}</code></p
        >
        <el-alert
          v-if="updatesError"
          :title="updatesError"
          type="error"
          show-icon
        />
        <StructuredDetails
          v-if="officialPreview"
          :value="officialPreview.plan"
        />
        <el-button
          v-if="officialPreview && canSubmitOfficial"
          type="primary"
          :disabled="packageLoading"
          :loading="officialBusy"
          @click="applyOfficial"
          >确认所选版本并提交{{
            officialOperation === 'install' ? '安装' : '升级'
          }}任务</el-button
        >
      </template>
    </el-card>
    <el-card
      v-if="section === 'operations' && (canInstall || canUpdate || packageTask)"
      class="backup-center-card"
    >
      <template #header>上传固定模块包安装或升级</template>
      <el-form
        v-if="canInstall || canUpdate"
        label-position="top"
        @submit.prevent="submitPackage"
      >
        <el-form-item label="操作">
          <el-select
            v-model="packageOperation"
            @change="packageIdempotency = ''"
          >
            <el-option v-if="canInstall" label="安装" value="install" />
            <el-option v-if="canUpdate" label="升级" value="update" />
          </el-select>
        </el-form-item>
        <el-form-item label=".tar 文件">
          <input
            type="file"
            accept=".tar,application/x-tar"
            @change="selectPackage"
          />
        </el-form-item>
        <el-form-item label="期望 SHA-256"
          ><el-input
            v-model="packageSha"
            maxlength="64"
            @input="packageIdempotency = ''"
        /></el-form-item>
        <el-form-item label="签名 key id（可选）"
          ><el-input
            v-model="packageSignature"
            @input="packageIdempotency = ''"
        /></el-form-item>
        <el-button
          type="primary"
          :loading="packageLoading"
          :disabled="officialBusy"
          @click="submitPackage"
          >校验并提交任务</el-button
        >
      </el-form>
      <el-alert
        v-if="packageError"
        :title="packageError"
        type="error"
        show-icon
      />
      <template v-if="packageTask">
        <p
          >{{ packageTask.task_key }} · {{ packageTask.status }} ·
          {{ packageTask.current_step }}</p
        >
        <p v-if="packageTask.status === 'queued'"
          >任务已排队，等待当前应用的模块任务处理。</p
        >
        <p v-if="packageTask.status === 'succeeded'"
          >依赖、模块生命周期及当前支持的客户端采用已完成。</p
        >
        <el-button
          v-if="canReadTasks"
          :loading="packageLoading"
          :disabled="officialBusy"
          @click="refreshPackageTask"
          >刷新任务状态</el-button
        >
        <el-alert
          v-if="packageTask.last_error_code"
          :title="packageTask.last_error_code"
          type="error"
        />
        <StructuredDetails
          v-if="packageTask.result"
          :value="packageTask.result"
        />
        <StructuredDetails
          v-if="packageTask.recovery_pointer"
          :value="packageTask.recovery_pointer"
        />
      </template>
    </el-card>
    <el-card v-if="section === 'operations' && canReadTasks" class="backup-center-card">
      <template #header>按任务编号查询安装 / 升级结果</template>
      <div class="toolbar developer-toolbar">
        <el-input
          v-model="taskLookupKey"
          aria-label="模块任务编号"
          clearable
          placeholder="输入当前实例的 task_key 查询既有任务"
          @keyup.enter="lookupTask"
        />
        <el-button :loading="packageLoading" :disabled="!taskLookupKey.trim()" @click="lookupTask">
          查询任务
        </el-button>
      </div>
      <el-alert v-if="packageError && !packageTask" :title="packageError" type="error" show-icon />
      <StructuredDetails v-if="lookedUpTask" :value="lookedUpTask" />
    </el-card>
    <el-card v-if="section === 'installations' && runtimeLoaded" class="backup-center-card">
      <template #header>部署安装状态（不代表租户或成员已获授权）</template>
      <el-table :data="runtimeRows">
        <el-table-column label="模块名称 / 标识" min-width="260">
          <template #default="{ row }">{{
            moduleLabel(row.module_key, row.name)
          }}</template>
        </el-table-column>
        <el-table-column prop="version" label="源码版本" />
        <el-table-column prop="status" label="安装状态" />
        <el-table-column prop="tenant_enabled_count" label="开通租户数" />
        <el-table-column label="必需 / 可选能力及不可用原因" min-width="340">
          <template #default="{ row }">
            <StructuredDetails
              :value="{
                required: row.dependencies,
                optional: row.optional_dependencies,
              }"
            />
          </template>
        </el-table-column>
        <el-table-column label="失败待办" min-width="220">
          <template #default="{ row }">{{
            row.pending_actions.join('；') || '—'
          }}</template>
        </el-table-column>
      </el-table>
      <el-pagination
        :current-page="runtimePage"
        :page-size="20"
        :total="runtimeTotal"
        layout="prev, pager, next, total"
        @current-change="loadRuntime"
      />
    </el-card>
    <template v-if="section === 'diagnostics' && snapshot">
      <div class="metric-grid">
        <el-card
          ><span>声明模块</span
          ><strong>{{ snapshot.summary.modules }}</strong></el-card
        >
        <el-card
          ><span>严格发现</span
          ><strong>{{ snapshot.summary.discovered }}</strong></el-card
        >
        <el-card
          ><span>已编译注册</span
          ><strong>{{ snapshot.summary.registered }}</strong></el-card
        >
        <el-card
          ><span>生成 API</span
          ><strong>{{
            snapshot.summary.generated_api_operations
          }}</strong></el-card
        >
      </div>
      <el-card class="backup-center-card">
        <template #header>证据边界</template>
        <el-table :data="statusRows">
          <el-table-column prop="label" label="阶段" width="120" />
          <el-table-column label="状态" width="130">
            <template #default="{ row }"
              ><el-tag :type="tagType(row.status)">{{
                row.status
              }}</el-tag></template
            >
          </el-table-column>
          <el-table-column prop="code" label="原因码" min-width="230" />
          <el-table-column prop="reason" label="证据说明" min-width="320" />
        </el-table>
      </el-card>
    </template>
    <template v-if="section === 'modules' && snapshot">
      <el-collapse>
        <el-collapse-item
          v-for="module in snapshot.modules"
          :key="module.key"
          :name="module.key"
        >
          <template #title
            ><b>{{ module.name }}</b
            >&nbsp;· {{ module.key }}&nbsp;·
            {{ module.version || '版本未声明' }}</template
          >
          <el-descriptions :column="2" border>
            <el-descriptions-item label="职责">{{
              module.description || '未说明'
            }}</el-descriptions-item>
            <el-descriptions-item label="源码">{{
              module.source.backend_root
            }}</el-descriptions-item>
            <el-descriptions-item label="Provider">{{
              module.provider.class || '未声明'
            }}</el-descriptions-item>
            <el-descriptions-item label="前端入口">{{
              frontendEntries(module)
            }}</el-descriptions-item>
            <el-descriptions-item label="路由 / 生成 API"
              >{{ module.routes.length }} /
              {{ module.generated_api.length }}</el-descriptions-item
            >
            <el-descriptions-item label="权限 / 公开服务"
              >{{ module.permissions.length }} /
              {{ module.public_services.length }}</el-descriptions-item
            >
            <el-descriptions-item label="迁移 / 文档"
              >{{ module.migrations.files.length }} /
              {{ module.documentation.files.length }}</el-descriptions-item
            >
            <el-descriptions-item label="打包预览"
              >{{ module.package_preview.status
              }}<template v-if="module.package_preview.file_count">
                · {{ module.package_preview.file_count }} files</template
              ></el-descriptions-item
            >
          </el-descriptions>
          <el-table :data="evidenceRows(module)" size="small">
            <el-table-column prop="label" label="阶段" width="120" />
            <el-table-column prop="status" label="状态" width="130" />
            <el-table-column prop="code" label="原因码" min-width="230" />
            <el-table-column prop="reason" label="说明" min-width="320" />
          </el-table>
          <el-tabs>
            <el-tab-pane label="依赖">
              <StructuredDetails
                :value="{
                  dependencies: module.dependencies,
                  dependants: module.dependants,
                }"
              />
            </el-tab-pane>
            <el-tab-pane label="路由与权限">
              <StructuredDetails
                :value="{
                  routes: module.generated_api.length
                    ? module.generated_api
                    : module.routes,
                  permissions: module.permissions,
                  middleware: module.middleware,
                }"
              />
            </el-tab-pane>
            <el-tab-pane label="服务与 Provider">
              <StructuredDetails
                :value="{
                  provider: module.provider,
                  public_services: module.public_services,
                }"
              />
            </el-tab-pane>
            <el-tab-pane label="生命周期">
              <StructuredDetails :value="module.lifecycle" />
            </el-tab-pane>
            <el-tab-pane label="事件任务">
              <StructuredDetails
                :value="{ events: module.events, tasks: module.tasks }"
              />
            </el-tab-pane>
            <el-tab-pane label="迁移文档">
              <StructuredDetails
                :value="{
                  migrations: module.migrations,
                  documentation: module.documentation,
                }"
              />
            </el-tab-pane>
            <el-tab-pane label="打包预览">
              <StructuredDetails :value="module.package_preview" />
            </el-tab-pane>
          </el-tabs>
        </el-collapse-item>
      </el-collapse>
    </template>
  </section>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref, watch } from 'vue';
  import { ElMessageBox } from 'element-plus';
  import { api } from '../api/platform';
  import StructuredDetails from './StructuredDetails.vue';
  import type {
    DeveloperCenterCatalogSnapshot,
    DeveloperModuleCatalog,
    RuntimeModuleStatus,
    ModuleOperationTask,
    OfficialModuleUpdate,
    OfficialModuleCatalogEntry,
    OfficialModuleSelection,
    OfficialUpdateSnapshot,
    OfficialUpdatePreview,
  } from '../api/platform';

  import { platformDeveloperOfficialUpdatesRead } from '../core/platform-shell-definitions';

  type DeveloperSection = 'modules' | 'installations' | 'official' | 'operations' | 'diagnostics';
  const props = defineProps<{
    section: DeveloperSection;
    canReadCatalog: boolean;
    canReadUpdates: boolean;
    canInstallOfficial: boolean;
    canUpdateOfficial: boolean;
    canReadRuntime: boolean;
    canInstall: boolean;
    canUpdate: boolean;
    canReadTasks: boolean;
  }>();
  const emit = defineEmits<{ 'open-operations': [] }>();
  const loading = ref(false);
  const error = ref('');
  const runtimeLoading = ref(false);
  const runtimeLoaded = ref(false);
  const runtimeError = ref('');
  const runtimeRows = ref<RuntimeModuleStatus[]>([]);
  const runtimePage = ref(1);
  const runtimeTotal = ref(0);
  const packageOperation = ref<'install' | 'update'>(
    props.canInstall ? 'install' : 'update'
  );
  const packageFile = ref<File | null>(null);
  const packageSha = ref('');
  const packageSignature = ref('');
  const packageLoading = ref(false);
  const packageError = ref('');
  const packageTask = ref<ModuleOperationTask | null>(null);
  const taskLookupKey = ref('');
  const lookedUpTask = ref<ModuleOperationTask | null>(null);
  let packageIdempotency = '';
  const releaseChannel = ref<'stable' | 'prerelease'>('stable');
  const updatesLoading = ref(false);
  const updatesError = ref('');
  const officialBusy = ref(false);
  const officialUpdates = ref<OfficialUpdateSnapshot | null>(null);
  const officialSelection = ref<OfficialModuleSelection | null>(null);
  const officialPreview = ref<OfficialUpdatePreview | null>(null);
  const officialOperation = ref<'install' | 'update'>('update');
  const canSubmitOfficial = computed(() =>
    officialOperation.value === 'install'
      ? props.canInstallOfficial
      : props.canUpdateOfficial
  );
  const catalogSearch = ref('');
  const visibleOfficialCatalog = computed(() =>
    (officialUpdates.value?.catalog || []).filter((row) =>
      moduleLabel(row.key).toLowerCase().includes(catalogSearch.value.trim().toLowerCase())
    )
  );
  let officialIdempotency = '';
  const updateLabels: Record<string, string> = {
    update_available: '有更新，需原生预检',
    current: '源码版本已最新',
    source_newer: '本地源码版本较新',
    no_published_candidate: '当前渠道无发行候选',
  };

  function resetOfficialSelection(): void {
    officialUpdates.value = null;
    officialSelection.value = null;
    officialPreview.value = null;
    officialIdempotency = '';
    updatesError.value = '';
  }

  async function checkOfficialUpdates(): Promise<void> {
    if (props.section !== 'official' || !props.canReadUpdates) return;
    resetOfficialSelection();
    updatesLoading.value = true;
    try {
      const results = await api.officialModuleUpdates(releaseChannel.value);
      if (props.section !== 'official') return;
      officialUpdates.value = results;
    } catch (cause) {
      if (props.section === 'official')
        updatesError.value = cause instanceof Error ? cause.message : '官方更新查询失败';
    } finally {
      updatesLoading.value = false;
    }
  }

  function catalogAvailability(row: OfficialModuleCatalogEntry): string {
    if (row.availability === 'withdrawn') return '此版本已撤回';
    if (row.reason === 'EDITION_UNSUPPORTED') return '不支持当前应用形态';
    return row.availability === 'available' ? '可选，需原生预检' : '当前不可用';
  }

  function catalogDependencies(row: OfficialModuleCatalogEntry): string {
    return (
      row.dependencies
        .map((item) => item.module_key + ' ' + item.version)
        .join('；') || '无'
    );
  }

  async function previewOfficial(row: OfficialModuleUpdate): Promise<void> {
    if (
      !props.canUpdateOfficial ||
      !row.available_version ||
      !row.sha256 ||
      !row.catalog_tag
    )
      return;
    await previewOfficialCandidate(
      {
        module_key: row.key,
        version: row.available_version,
        sha256: row.sha256,
        catalog_tag: row.catalog_tag,
        channel: releaseChannel.value,
      },
      'update'
    );
  }

  async function previewCatalog(
    row: OfficialModuleCatalogEntry
  ): Promise<void> {
    if (!props.canInstallOfficial || row.availability !== 'available') return;
    await previewOfficialCandidate(
      {
        module_key: row.key,
        version: row.version,
        sha256: row.sha256,
        catalog_tag: row.catalog_tag,
        channel: row.channel,
      },
      'install'
    );
  }

  async function previewOfficialCandidate(
    selection: OfficialModuleSelection,
    operation: 'install' | 'update'
  ): Promise<void> {
    if (props.section !== 'official' || officialBusy.value || updatesLoading.value) return;
    officialOperation.value = operation;
    officialBusy.value = true;
    updatesError.value = '';
    officialPreview.value = null;
    officialIdempotency = '';
    officialSelection.value = selection;
    try {
      officialPreview.value = await api.previewOfficialModule(
        selection,
        operation
      );
      if (props.section === 'official') emit('open-operations');
    } catch (cause) {
      updatesError.value =
        cause instanceof Error ? cause.message : '模块预检未通过';
    } finally {
      officialBusy.value = false;
    }
  }

  async function applyOfficial(): Promise<void> {
    if (
      props.section !== 'operations' ||
      officialBusy.value ||
      packageLoading.value ||
      !canSubmitOfficial.value ||
      !officialSelection.value ||
      !officialPreview.value
    )
      return;
    const selection = { ...officialSelection.value };
    const operation = officialOperation.value;
    const action = operation === 'install' ? '安装' : '升级';
    const digest = officialPreview.value.selection_sha256;
    officialBusy.value = true;
    try {
      const confirmed = await ElMessageBox.confirm(
        '将' +
          action +
          ' ' +
          selection.module_key +
          ' ' +
          selection.version +
          '，可能执行迁移并使相关模块短暂不可用。任务执行时会重新核对权限、依赖和迁移状态。确认提交？',
        '确认模块' + action,
        { type: 'warning' }
      ).catch(() => false);
      if (!confirmed) return;
      updatesError.value = '';
      officialIdempotency ||=
        'official-module-' +
        Array.from(crypto.getRandomValues(new Uint8Array(16)), (value) =>
          value.toString(16).padStart(2, '0')
        ).join('');
      packageTask.value = await api.applyOfficialModule(
        selection,
        digest,
        officialIdempotency,
        operation
      );
      officialPreview.value = null;
      officialSelection.value = null;
    } catch (cause) {
      updatesError.value =
        cause instanceof Error ? cause.message : '模块任务提交失败';
    } finally {
      officialBusy.value = false;
    }
  }
  const selected = ref('');
  const snapshot = ref<DeveloperCenterCatalogSnapshot | null>(null);
  const moduleChoices = ref<Array<{ key: string; name: string }>>([]);
  const localModuleNames = computed(
    () => new Map(moduleChoices.value.map(({ key, name }) => [key, name]))
  );
  function moduleLabel(key: string, name = localModuleNames.value.get(key)): string {
    return name && name !== key ? `${name}（${key}）` : key;
  }

  const labels: Record<string, string> = {
    declaration: '声明',
    discovery: '发现',
    registration: '注册',
    runtime_effective: '运行有效',
    tests: '测试',
    api_catalog: 'API 目录',
  };
  const statusRows = computed(() =>
    Object.entries(snapshot.value?.status || {}).map(([key, item]) => ({
      label: labels[key] || key,
      ...item,
    }))
  );

  async function load(): Promise<void> {
    const section = props.section;
    if (!props.canReadCatalog || (section !== 'modules' && section !== 'diagnostics'))
      return;
    loading.value = true;
    error.value = '';
    try {
      const next = await api.developerCatalog(selected.value);
      if (section !== props.section) return;
      snapshot.value = next;
      if (!selected.value)
        moduleChoices.value = next.modules.map(({ key, name }) => ({
          key,
          name,
        }));
    } catch (cause) {
      if (section === props.section)
        error.value = cause instanceof Error ? cause.message : '开发者目录加载失败';
    } finally {
      loading.value = false;
    }
  }

  async function loadRuntime(page = 1): Promise<void> {
    if (props.section !== 'installations' || !props.canReadRuntime) return;
    runtimeLoading.value = true;
    runtimeError.value = '';
    try {
      const result = await api.runtimeModules(selected.value, page);
      if (props.section !== 'installations') return;
      runtimeRows.value = result.lists;
      runtimeTotal.value = result.count;
      runtimePage.value = page;
      runtimeLoaded.value = true;
    } catch (cause) {
      if (props.section === 'installations') {
        runtimeRows.value = [];
        runtimeLoaded.value = false;
        runtimeError.value =
          cause instanceof Error ? cause.message : '安装状态读取失败';
      }
    } finally {
      runtimeLoading.value = false;
    }
  }

  function selectPackage(event: Event): void {
    packageFile.value = (event.target as HTMLInputElement).files?.[0] || null;
    packageIdempotency = '';
  }

  async function submitPackage(): Promise<void> {
    if (
      props.section !== 'operations' ||
      officialBusy.value ||
      packageLoading.value ||
      (packageOperation.value === 'install'
        ? !props.canInstall
        : !props.canUpdate)
    )
      return;
    if (!packageFile.value || !/^[a-f0-9]{64}$/.test(packageSha.value)) {
      packageError.value = '请选择 .tar 文件并填写 64 位小写 SHA-256';
      return;
    }
    const form = new FormData();
    form.append('package', packageFile.value);
    form.append('expected_sha256', packageSha.value);
    if (packageSignature.value)
      form.append('signature_key_id', packageSignature.value);
    packageIdempotency ||= `module-${Array.from(
      crypto.getRandomValues(new Uint8Array(16)),
      (byte) => byte.toString(16).padStart(2, '0')
    ).join('')}`;
    packageLoading.value = true;
    packageError.value = '';
    try {
      packageTask.value = await api.submitModulePackage(
        packageOperation.value,
        form,
        packageIdempotency
      );
    } catch (cause) {
      packageError.value =
        cause instanceof Error ? cause.message : '模块任务提交失败';
    } finally {
      packageLoading.value = false;
    }
  }

  async function lookupTask(): Promise<void> {
    if (props.section !== 'operations' || !props.canReadTasks ||
        packageLoading.value || !taskLookupKey.value.trim()) return;
    packageLoading.value = true;
    packageError.value = '';
    lookedUpTask.value = null;
    const key = taskLookupKey.value.trim();
    try {
      const task = await api.moduleOperationTask(key);
      if (props.section !== 'operations' || taskLookupKey.value.trim() !== key) return;
      if (task.task_key !== key) throw new Error('返回任务标识与查询不一致');
      lookedUpTask.value = task;
    } catch (cause) {
      if (props.section === 'operations')
        packageError.value = cause instanceof Error ? cause.message : '查询模块任务失败';
    } finally {
      packageLoading.value = false;
    }
  }

  async function refreshPackageTask(): Promise<void> {
    if (
      props.section !== 'operations' ||
      !props.canReadTasks ||
      !packageTask.value ||
      packageLoading.value ||
      officialBusy.value
    )
      return;
    const taskKey = packageTask.value.task_key;
    packageLoading.value = true;
    packageError.value = '';
    try {
      const task = await api.moduleOperationTask(taskKey);
      if (packageTask.value?.task_key !== taskKey) return;
      if (task.task_key !== taskKey)
        throw new Error('返回的任务与当前回执不一致，请重新刷新。');
      packageTask.value = task;
      // The operations page never loads the installation list implicitly.
      // Navigate to 安装与依赖 to read the updated state, if authorized.
    } catch (cause) {
      packageError.value =
        cause instanceof Error ? cause.message : '模块任务状态读取失败';
    } finally {
      packageLoading.value = false;
    }
  }

  function evidenceRows(module: DeveloperModuleCatalog) {
    return Object.entries(module.evidence).map(([key, item]) => ({
      label: labels[key] || key,
      ...item,
    }));
  }
  function frontendEntries(module: DeveloperModuleCatalog): string {
    if (module.source.frontend_clients.length === 0) return '后端模块';
    return module.source.frontend_clients
      .map(({ client_key: client, entry }) => `${client}: ${entry}`)
      .join('；');
  }
  function tagType(status: string): '' | 'success' | 'warning' | 'danger' {
    if (
      ['available', 'declared', 'discovered', 'registered', 'ready'].includes(
        status
      )
    )
      return 'success';
    if (status === 'blocked') return 'danger';
    return 'warning';
  }

  async function loadOfficialAuthorNames(): Promise<void> {
    if (!props.canReadCatalog || props.section !== 'official') return;
    try {
      const catalog = await api.developerCatalog();
      if (props.section === 'official')
        moduleChoices.value = catalog.modules.map(({ key, name }) => ({ key, name }));
    } catch {
      // The official release index remains usable without the separate
      // developer-catalog permission; remote unknown keys stay raw.
      if (props.section === 'official') moduleChoices.value = [];
    }
  }

  function activateSection(section: DeveloperSection) {
    selected.value = '';
    if (section === 'modules' || section === 'diagnostics') void load();
    else if (section === 'installations') void loadRuntime(1);
    else if (section === 'official') {
      void checkOfficialUpdates();
      void loadOfficialAuthorNames();
    }
    // Operations has no list endpoint and never fetches an unrelated page.
  }
  onMounted(() => activateSection(props.section));
  watch(() => props.section, (section) => activateSection(section));
</script>

<style scoped>
  .backup-center-card {
    margin-bottom: 16px;
  }
  .developer-toolbar .el-select {
    min-width: 320px;
  }
  @media (max-width: 760px) {
    .developer-toolbar {
      justify-content: flex-start;
      flex-wrap: wrap;
    }
    .developer-toolbar .el-select {
      width: 100%;
      min-width: 0;
    }
    .developer-toolbar .el-button {
      flex: 1 1 0;
      min-width: 0;
    }
  }
</style>
