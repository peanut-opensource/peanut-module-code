<template>
  <section v-loading="loading">
    <el-alert
      title="模块声明、注册、安装与运行状态分别展示；安装与升级任务在后台完成后才能使用。"
      type="info"
      :closable="false"
    />
    <div class="toolbar">
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
      <el-button :loading="runtimeLoading" @click="loadRuntime(1)">
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
    <el-card class="backup-center-card">
      <template #header>安装或升级模块包</template>
      <el-form label-position="top" @submit.prevent="submitPackage">
        <el-form-item label="操作">
          <el-select
            v-model="packageOperation"
            @change="packageIdempotency = ''"
          >
            <el-option label="安装" value="install" />
            <el-option label="升级" value="update" />
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
        <pre v-if="packageTask.result">{{ pretty(packageTask.result) }}</pre>
        <pre v-if="packageTask.recovery_pointer">{{
          pretty(packageTask.recovery_pointer)
        }}</pre>
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
            <pre>{{
              pretty({
                required: row.dependencies,
                optional: row.optional_dependencies,
              })
            }}</pre>
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
              <pre>{{
                pretty({
                  dependencies: module.dependencies,
                  dependants: module.dependants,
                })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="路由与权限">
              <pre>{{
                pretty({
                  routes: module.generated_api.length
                    ? module.generated_api
                    : module.routes,
                  permissions: module.permissions,
                  middleware: module.middleware,
                })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="服务与 Provider">
              <pre>{{
                pretty({
                  provider: module.provider,
                  public_services: module.public_services,
                })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="生命周期">
              <pre>{{ pretty(module.lifecycle) }}</pre>
            </el-tab-pane>
            <el-tab-pane label="事件任务">
              <pre>{{
                pretty({ events: module.events, tasks: module.tasks })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="迁移文档">
              <pre>{{
                pretty({
                  migrations: module.migrations,
                  documentation: module.documentation,
                })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="打包预览">
              <pre>{{ pretty(module.package_preview) }}</pre>
            </el-tab-pane>
          </el-tabs>
        </el-collapse-item>
      </el-collapse>
    </template>
  </section>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue';
  import { api } from '../api/platform';
  import type {
    DeveloperCenterCatalogSnapshot,
    DeveloperModuleCatalog,
    RuntimeModuleStatus,
    ModuleOperationTask,
  } from '../api/platform';

  const loading = ref(false);
  const error = ref('');
  const runtimeLoading = ref(false);
  const runtimeLoaded = ref(false);
  const runtimeError = ref('');
  const runtimeRows = ref<RuntimeModuleStatus[]>([]);
  const runtimePage = ref(1);
  const runtimeTotal = ref(0);
  const packageOperation = ref<'install' | 'update'>('install');
  const packageFile = ref<File | null>(null);
  const packageSha = ref('');
  const packageSignature = ref('');
  const packageLoading = ref(false);
  const packageError = ref('');
  const packageTask = ref<ModuleOperationTask | null>(null);
  let packageIdempotency = '';
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
    if (!packageTask.value) return;
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
  function pretty(value: unknown): string {
    return JSON.stringify(value, null, 2);
  }

  onMounted(load);
</script>

<style scoped>
  .backup-center-card {
    margin-bottom: 16px;
  }
  .el-select {
    min-width: 320px;
  }
  pre {
    margin: 0;
    max-height: 420px;
    overflow: auto;
    padding: 14px;
    color: #d9e2f2;
    background: #13213a;
    border-radius: 6px;
    font-size: 12px;
  }
</style>
