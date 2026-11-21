<template>
  <section v-loading="loading">
    <el-alert
      title="模块声明、注册、安装与运行状态分别展示；安装与升级任务在后台完成后才能使用。"
      type="info"
      :closable="false"
    />
    <div class="toolbar developer-toolbar">
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
          :label="`${item.key} · ${item.name}`"
          :value="item.key"
        />
      </el-select>
      <el-button :loading="loading" @click="load">刷新目录</el-button>
      <el-button
        v-if="canReadRuntime"
        :loading="runtimeLoading"
        @click="loadRuntime(1)"
      >
        读取安装与依赖状态
      </el-button>
    </div>
    <el-alert v-if="error" :title="error" type="error" show-icon />
    <el-alert
      v-if="runtimeError"
      :title="runtimeError"
      type="error"
      show-icon
    />
    <el-card v-if="canReadUpdates" class="backup-center-card">
      <template #header>官方发行更新（开发者）</template>
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
          >检查官方更新</el-button
        >
      </div>
      <el-alert
        v-if="updatesError"
        :title="updatesError"
        type="error"
        show-icon
      />
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
        <el-table :data="officialUpdates.modules">
          <el-table-column prop="key" label="官方模块" min-width="200" />
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
          >发现新版不代表已兼容或已安装。确认前执行原生依赖、Edition、迁移与安装状态预检；版本及
          SHA-256 始终固定。</p
        >
      </template>
      <template v-if="officialPreview && officialSelection">
        <h3
          >{{ officialSelection.module_key }} →
          {{ officialSelection.version }}</h3
        >
        <p
          >发行目录：{{ officialSelection.catalog_tag }}；包 SHA-256：<code>{{
            officialSelection.sha256
          }}</code></p
        >
        <StructuredDetails :value="officialPreview.plan" />
        <el-button
          v-if="canUpdateOfficial"
          type="primary"
          :loading="officialBusy"
          @click="applyOfficial"
          >确认计划并提交升级任务</el-button
        >
      </template>
    </el-card>
    <el-card
      v-if="canInstall || canUpdate || packageTask"
      class="backup-center-card"
    >
      <template #header>安装或升级模块</template>
      <el-form label-position="top" @submit.prevent="submitPackage">
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
        <el-button :loading="packageLoading" @click="refreshPackageTask"
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
    <el-card v-if="runtimeLoaded" class="backup-center-card">
      <template #header>部署安装状态（不代表租户或成员已获授权）</template>
      <el-table :data="runtimeRows">
        <el-table-column prop="module_key" label="模块" min-width="200" />
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
    <template v-if="snapshot">
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
      <el-collapse>
        <el-collapse-item
          v-for="module in snapshot.modules"
          :key="module.key"
          :name="module.key"
        >
          <template #title
            ><b>{{ module.key }}</b
            >&nbsp;· {{ module.name }}&nbsp;·
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
  import { computed, onMounted, ref } from 'vue';
  import { ElMessageBox } from 'element-plus';
  import { api } from '../api/platform';
  import StructuredDetails from './StructuredDetails.vue';
  import type {
    DeveloperCenterCatalogSnapshot,
    DeveloperModuleCatalog,
    RuntimeModuleStatus,
    ModuleOperationTask,
    OfficialModuleUpdate,
    OfficialModuleSelection,
    OfficialUpdateSnapshot,
    OfficialUpdatePreview,
  } from '../api/platform';

  const props = defineProps<{
    canReadUpdates: boolean;
    canUpdateOfficial: boolean;
    canReadRuntime: boolean;
    canInstall: boolean;
    canUpdate: boolean;
    canReadTasks: boolean;
  }>();
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
  let packageIdempotency = '';
  const releaseChannel = ref<'stable' | 'prerelease'>('stable');
  const updatesLoading = ref(false);
  const updatesError = ref('');
  const officialBusy = ref(false);
  const officialUpdates = ref<OfficialUpdateSnapshot | null>(null);
  const officialSelection = ref<OfficialModuleSelection | null>(null);
  const officialPreview = ref<OfficialUpdatePreview | null>(null);
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
    if (!props.canReadUpdates) return;
    resetOfficialSelection();
    updatesLoading.value = true;
    try {
      officialUpdates.value = await api.officialModuleUpdates(
        releaseChannel.value
      );
    } catch (cause) {
      updatesError.value =
        cause instanceof Error ? cause.message : '官方更新查询失败';
    } finally {
      updatesLoading.value = false;
    }
  }

  async function previewOfficial(row: OfficialModuleUpdate): Promise<void> {
    if (
      !props.canUpdateOfficial ||
      !row.available_version ||
      !row.sha256 ||
      !row.catalog_tag
    )
      return;
    officialBusy.value = true;
    updatesError.value = '';
    officialPreview.value = null;
    officialIdempotency = '';
    const selection: OfficialModuleSelection = {
      module_key: row.key,
      version: row.available_version,
      sha256: row.sha256,
      catalog_tag: row.catalog_tag,
      channel: releaseChannel.value,
    };
    officialSelection.value = selection;
    try {
      officialPreview.value = await api.previewOfficialModule(selection);
    } catch (cause) {
      updatesError.value =
        cause instanceof Error ? cause.message : '模块预检未通过';
    } finally {
      officialBusy.value = false;
    }
  }

  async function applyOfficial(): Promise<void> {
    if (
      !props.canUpdateOfficial ||
      !officialSelection.value ||
      !officialPreview.value
    )
      return;
    const selection = { ...officialSelection.value };
    const digest = officialPreview.value.selection_sha256;
    try {
      await ElMessageBox.confirm(
        `将 ${selection.module_key} 升级到 ${selection.version}，可能执行迁移并使相关模块短暂不可用。确认按已预检计划提交？`,
        '确认模块升级',
        { type: 'warning' }
      );
    } catch {
      return;
    }
    officialBusy.value = true;
    updatesError.value = '';
    officialIdempotency ||= `official-module-${Array.from(
      crypto.getRandomValues(new Uint8Array(16)),
      (value) => value.toString(16).padStart(2, '0')
    ).join('')}`;
    try {
      packageTask.value = await api.applyOfficialModule(
        selection,
        digest,
        officialIdempotency
      );
      officialPreview.value = null;
    } catch (cause) {
      updatesError.value =
        cause instanceof Error ? cause.message : '模块升级任务提交失败';
    } finally {
      officialBusy.value = false;
    }
  }
  const selected = ref('');
  const snapshot = ref<DeveloperCenterCatalogSnapshot | null>(null);
  const moduleChoices = ref<Array<{ key: string; name: string }>>([]);

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
    loading.value = true;
    error.value = '';
    try {
      const next = await api.developerCatalog(selected.value);
      snapshot.value = next;
      if (!selected.value)
        moduleChoices.value = next.modules.map(({ key, name }) => ({
          key,
          name,
        }));
    } catch (cause) {
      error.value =
        cause instanceof Error ? cause.message : '开发者目录加载失败';
    } finally {
      loading.value = false;
    }
  }

  async function loadRuntime(page = 1): Promise<void> {
    if (!props.canReadRuntime) return;
    runtimeLoading.value = true;
    runtimeError.value = '';
    try {
      const result = await api.runtimeModules(selected.value, page);
      runtimeRows.value = result.lists;
      runtimeTotal.value = result.count;
      runtimePage.value = page;
      runtimeLoaded.value = true;
    } catch (cause) {
      runtimeRows.value = [];
      runtimeLoaded.value = false;
      runtimeError.value =
        cause instanceof Error ? cause.message : '安装状态读取失败';
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

  async function refreshPackageTask(): Promise<void> {
    if (!props.canReadTasks || !packageTask.value) return;
    packageLoading.value = true;
    packageError.value = '';
    try {
      packageTask.value = await api.moduleOperationTask(
        packageTask.value.task_key
      );
      if (packageTask.value.status === 'succeeded')
        await loadRuntime(runtimePage.value);
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

  onMounted(load);
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
