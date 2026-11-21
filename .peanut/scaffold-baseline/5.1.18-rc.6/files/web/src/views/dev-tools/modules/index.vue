<template>
  <div class="container">
    <Breadcrumb :items="['开发工具', '模块治理']" />

    <el-card v-if="!authenticated" class="general-card auth-card">
      <template #header>Platform 身份验证</template>
      <el-alert
        type="info"
        :closable="false"
        title="实例模块治理使用独立 Platform 会话，不复用当前租户管理员身份。"
      />
      <el-form label-position="top" @submit.prevent="login">
        <el-form-item label="Platform 邮箱">
          <el-input v-model="credentials.email" autocomplete="username" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input
            v-model="credentials.password"
            type="password"
            show-password
            autocomplete="current-password"
          />
        </el-form-item>
        <el-button type="primary" :loading="busy" @click="login">
          验证并进入
        </el-button>
      </el-form>
    </el-card>

    <el-card v-else class="general-card">
      <template #header>
        <div class="card-header">
          <span>实例模块治理</span>
          <el-space>
            <el-button :loading="busy" @click="syncAll">同步开发树</el-button>
            <el-button :disabled="busy" @click="createVisible = true">
              创建模块
            </el-button>
            <el-button
              type="primary"
              :disabled="busy"
              @click="openPackage('install')"
            >
              安装 .tar 包
            </el-button>
            <el-button :disabled="busy" @click="openPackage('update')"
              >升级 .tar 包</el-button
            >
          </el-space>
        </div>
      </template>

      <el-alert
        v-if="error"
        type="error"
        :title="error"
        show-icon
        closable
        @close="error = ''"
      />
      <el-alert
        v-if="operationLabel"
        class="operation-alert"
        type="info"
        :closable="false"
        show-icon
        :title="operationLabel"
      />
      <el-card v-if="packageTask" class="operation-alert">
        <template #header>
          <div class="card-header">
            <span>模块任务 {{ packageTask.task_key }}</span>
            <el-button :loading="busy" @click="refreshTask"
              >刷新任务状态</el-button
            >
          </div>
        </template>
        <p
          >{{ packageTask.package_key }} · {{ packageTask.status }} ·
          {{ packageTask.current_step }}</p
        >
        <p v-if="packageTask.status === 'queued'"
          >任务已排队，等待当前应用的模块任务处理。</p
        >
        <p v-if="packageTask.status === 'succeeded'"
          >依赖、模块生命周期及当前支持的客户端采用已完成。</p
        >
        <el-alert
          v-if="packageTask.last_error_code"
          type="error"
          :title="packageTask.last_error_code"
        />
        <pre v-if="packageTask.result">{{
          JSON.stringify(packageTask.result, null, 2)
        }}</pre>
        <pre v-if="packageTask.recovery_pointer">{{
          JSON.stringify(packageTask.recovery_pointer, null, 2)
        }}</pre>
      </el-card>
      <el-table :data="rows" :loading="busy" row-key="module_key" border>
        <el-table-column prop="module_key" label="Module key" min-width="210" />
        <el-table-column prop="name" label="名称" min-width="150" />
        <el-table-column label="版本 / Package" min-width="220">
          <template #default="{ row }">
            <div>
              {{ row.version }} · {{ row.status }}
              <el-tag v-if="row.lifecycle_protected" size="small" type="danger">
                实例核心模块
              </el-tag>
            </div>
            <small>{{ row.package_key }}@{{ row.package_version }}</small>
          </template>
        </el-table-column>
        <el-table-column label="依赖关系" min-width="280">
          <template #default="{ row }">
            <div class="dependency-line">
              <span class="dependency-label">依赖</span>
              <el-tag
                v-for="dependency in row.dependencies"
                :key="dependency.module_key"
                size="small"
                :type="dependency.satisfied ? 'success' : 'danger'"
              >
                {{ dependency.module_key }} {{ dependency.version }}
                {{ dependency.unavailable_reason || '已满足' }}
              </el-tag>
              <span v-if="row.dependencies.length === 0">—</span>
            </div>
            <div class="dependency-line">
              <span class="dependency-label">可选</span>
              <el-tag
                v-for="dependency in row.optional_dependencies"
                :key="dependency.module_key"
                size="small"
                :type="dependency.satisfied ? 'success' : 'info'"
              >
                {{ dependency.module_key }} {{ dependency.version }}
                {{ dependency.unavailable_reason || '可用' }}
              </el-tag>
              <span v-if="row.optional_dependencies.length === 0">—</span>
            </div>
            <div v-for="action in row.pending_actions" :key="action">
              待处理：{{ action }}
            </div>
            <div class="dependency-line">
              <span class="dependency-label">被依赖</span>
              <el-tag
                v-for="dependent in row.dependents"
                :key="dependent"
                size="small"
                type="success"
              >
                {{ dependent }}
              </el-tag>
              <span v-if="row.dependents.length === 0">—</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column
          prop="tenant_enabled_count"
          label="已开通租户"
          width="110"
        />
        <el-table-column label="操作" width="300" fixed="right">
          <template #default="{ row }">
            <el-space wrap>
              <el-button link :disabled="busy" @click="syncOne(row.module_key)"
                >同步</el-button
              >
              <el-button
                link
                :disabled="busy || row.lifecycle_protected"
                @click="disable(row)"
                >停用</el-button
              >
              <el-button
                link
                type="warning"
                :disabled="busy || row.lifecycle_protected"
                @click="uninstall(row.module_key, false)"
              >
                退役
              </el-button>
              <el-button
                link
                type="danger"
                :disabled="busy || row.lifecycle_protected"
                @click="uninstall(row.module_key, true)"
              >
                Purge
              </el-button>
            </el-space>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-model:current-page="page"
        :page-size="pageSize"
        :total="total"
        layout="total, prev, pager, next"
        class="pagination"
        @current-change="load"
      />
    </el-card>

    <el-dialog
      v-model="uploadVisible"
      :title="
        packageOperation === 'install' ? '安装自包含模块包' : '升级模块包'
      "
      width="560px"
    >
      <el-form label-position="top">
        <el-form-item label=".tar 文件">
          <input
            type="file"
            accept=".tar,application/x-tar"
            @change="selectPackage"
          />
        </el-form-item>
        <el-form-item label="期望 SHA-256">
          <el-input
            v-model="upload.sha256"
            maxlength="64"
            @input="packageIdempotency = ''"
          />
        </el-form-item>
        <el-form-item label="签名 key id（可选）">
          <el-input
            v-model="upload.signatureKeyId"
            @input="packageIdempotency = ''"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="uploadVisible = false">取消</el-button>
        <el-button type="primary" :loading="busy" @click="install"
          >校验并提交任务</el-button
        >
      </template>
    </el-dialog>

    <el-dialog v-model="createVisible" title="创建 Module 骨架" width="560px">
      <el-alert
        type="info"
        :closable="false"
        title="Web 与 php think module:create 共用同一个 ModuleScaffoldGenerator 和模板。"
      />
      <el-form label-position="top" class="create-form">
        <el-form-item label="Module key">
          <el-input
            v-model="createInput.moduleKey"
            placeholder="例如 acme.crm"
            maxlength="96"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" :loading="busy" @click="createScaffold">
          生成前后端骨架
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { onMounted, reactive, ref } from 'vue';
  import { ElMessage, ElMessageBox } from 'element-plus';
  import {
    disableModule,
    createModule,
    executeUninstall,
    hasPlatformSession,
    installPackage,
    listModules,
    moduleOperationTask,
    loginPlatform,
    moduleErrorMessage,
    previewUninstall,
    syncModules,
    type ModuleRuntimeRow,
    type ModuleOperationTask,
  } from '@/api/dev-tools/modules';

  const authenticated = ref(hasPlatformSession());
  const busy = ref(false);
  const operationLabel = ref('');
  const error = ref('');
  const rows = ref<ModuleRuntimeRow[]>([]);
  const page = ref(1);
  const pageSize = 20;
  const total = ref(0);
  const uploadVisible = ref(false);
  const packageOperation = ref<'install' | 'update'>('install');
  const packageTask = ref<ModuleOperationTask | null>(null);
  let packageIdempotency = '';
  const createVisible = ref(false);
  const credentials = reactive({ email: '', password: '' });
  const upload = reactive<{
    file: File | null;
    sha256: string;
    signatureKeyId: string;
  }>({
    file: null,
    sha256: '',
    signatureKeyId: '',
  });
  const createInput = reactive({ moduleKey: '' });

  async function perform<T>(
    operation: () => Promise<T>,
    success?: string,
    pending = '正在处理模块治理请求…'
  ) {
    busy.value = true;
    operationLabel.value = pending;
    error.value = '';
    try {
      const result = await operation();
      if (success) ElMessage.success(success);
      return result;
    } catch (cause) {
      error.value = moduleErrorMessage(cause);
      if (!hasPlatformSession()) authenticated.value = false;
      throw cause;
    } finally {
      busy.value = false;
      operationLabel.value = '';
    }
  }

  async function load(nextPage = page.value) {
    page.value = nextPage;
    try {
      const result = await perform(
        () => listModules({ page: page.value, page_size: pageSize }),
        undefined,
        '正在刷新模块、依赖与安装状态…'
      );
      if (!result) return;
      rows.value = result.lists;
      total.value = result.count;
    } catch {
      // The visible error is set by perform.
    }
  }

  async function login() {
    try {
      await perform(
        () => loginPlatform(credentials.email, credentials.password),
        undefined,
        '正在验证 Platform 身份…'
      );
      credentials.password = '';
      authenticated.value = true;
      await load(1);
    } catch {
      // The visible error is set by perform.
    }
  }

  function selectPackage(event: Event) {
    upload.file = (event.target as HTMLInputElement).files?.[0] || null;
    packageIdempotency = '';
  }

  function openPackage(operation: 'install' | 'update') {
    if (packageOperation.value !== operation) packageIdempotency = '';
    packageOperation.value = operation;
    uploadVisible.value = true;
  }

  async function refreshTask() {
    if (!packageTask.value) return;
    try {
      packageTask.value = await perform(() =>
        moduleOperationTask(packageTask.value!.task_key)
      );
      if (packageTask.value.status === 'succeeded') await load();
    } catch {
      // The visible error is set by perform.
    }
  }

  async function install() {
    if (!upload.file || !/^[a-f0-9]{64}$/.test(upload.sha256)) {
      error.value = '请选择 .tar 文件并填写 64 位小写 SHA-256';
      return;
    }
    const form = new FormData();
    form.append('package', upload.file);
    form.append('expected_sha256', upload.sha256);
    if (upload.signatureKeyId)
      form.append('signature_key_id', upload.signatureKeyId);
    try {
      packageIdempotency ||= `module-${Array.from(
        crypto.getRandomValues(new Uint8Array(16)),
        (byte) => byte.toString(16).padStart(2, '0')
      ).join('')}`;
      packageTask.value = await perform(
        () => installPackage(form, packageIdempotency, packageOperation.value),
        '模块任务已提交，请刷新任务状态查看结果',
        '正在校验模块包并提交任务…'
      );
      uploadVisible.value = false;
    } catch {
      // The visible error is set by perform.
    }
  }

  async function createScaffold() {
    const moduleKey = createInput.moduleKey.trim();
    if (!/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/.test(moduleKey)) {
      error.value = '请输入有效的命名空间化 Module key';
      return;
    }
    try {
      const result = await perform(
        () => createModule(moduleKey),
        'Module 前后端骨架已生成',
        `正在通过统一生成器创建 ${moduleKey}…`
      );
      if (!result) return;
      createVisible.value = false;
      createInput.moduleKey = '';
      ElMessage.info(
        `后端：${result.backend_path}；前端：${result.frontend_path}。编辑 module.json 后执行同步。`
      );
    } catch {
      // The visible error is set by perform.
    }
  }

  async function syncAll() {
    try {
      await perform(
        () => syncModules(),
        '模块目录同步完成',
        '正在同步全部开发模块 catalog…'
      );
      await load();
    } catch {
      // The visible error is set by perform.
    }
  }

  async function syncOne(moduleKey: string) {
    try {
      await perform(
        () => syncModules(moduleKey),
        '模块目录同步完成',
        `正在同步 ${moduleKey} catalog…`
      );
      await load();
    } catch {
      // The visible error is set by perform.
    }
  }

  async function disable(row: ModuleRuntimeRow) {
    try {
      const moduleKey = row.module_key;
      const packageModules = row.package_modules;
      const scopeNotice =
        packageModules.length > 1
          ? `${moduleKey} 属于 Bundle ${
              row.package_key
            }；停用会同时处理整个 Bundle：${packageModules.join('、')}。\n`
          : '';
      const { value } = await ElMessageBox.prompt(
        `${scopeNotice}请输入停用原因（至少 3 个字符）`,
        packageModules.length > 1 ? '停用 Bundle' : '停用模块'
      );
      await perform(
        () => disableModule(moduleKey, value),
        '模块已停用',
        `正在停用 ${moduleKey}…`
      );
      await load();
    } catch (cause) {
      if (cause instanceof Error) error.value = moduleErrorMessage(cause);
    }
  }

  async function uninstall(moduleKey: string, purge: boolean) {
    try {
      const preview = await perform(() => previewUninstall(moduleKey, purge));
      if (!preview) return;
      if (preview.blockers.length > 0) {
        error.value = preview.blockers
          .map((item) => moduleErrorMessage(new Error(item.code)))
          .join('；');
        return;
      }
      const packageKey = String(preview.confirm_plan.package_key || moduleKey);
      const affectedModules = preview.affected_modules.map(
        (module) => module.module_key
      );
      const bundleNotice =
        affectedModules.length > 1
          ? `${moduleKey} 属于 Bundle ${packageKey}，不能单独卸载；继续将处理整个 Bundle。`
          : `将处理模块包 ${packageKey}。`;
      const dataWindowNotice = purge
        ? ''
        : '\n停用后的人工数据处理窗口由你决定何时结束。请确认在途请求、任务和回调已排空、必要的数据处理已完成后继续；业务表、设置值、附件与迁移历史会保留。';
      const summary = preview.removed
        .map((entry) => `${entry.table}: ${entry.action} ${entry.count}`)
        .join('\n');
      const { value } = await ElMessageBox.prompt(
        `${bundleNotice}${dataWindowNotice}\n受影响模块（${
          affectedModules.length
        }）：${affectedModules.join('、')}\n${
          purge
            ? 'Purge 将物理删除数据与显式 RBAC 绑定。'
            : '默认退役保留数据与绑定。'
        }\n${summary}\n请输入变更原因：`,
        purge
          ? `确认 Purge ${affectedModules.length > 1 ? 'Bundle' : '模块'}`
          : `确认退役 ${affectedModules.length > 1 ? 'Bundle' : '模块'}`,
        { type: purge ? 'error' : 'warning' }
      );
      const idempotencyKey = `module-remove-${Array.from(
        crypto.getRandomValues(new Uint8Array(16)),
        (byte) => byte.toString(16).padStart(2, '0')
      ).join('')}`;
      packageTask.value = await perform(
        () => executeUninstall(moduleKey, purge, preview, value, idempotencyKey),
        '模块卸载任务已提交，请刷新任务状态查看结果',
        `正在提交 ${packageKey} 卸载任务…`
      );
      await load(1);
    } catch (cause) {
      if (cause instanceof Error) error.value = moduleErrorMessage(cause);
    }
  }

  onMounted(() => {
    if (authenticated.value) load(1);
  });
</script>

<style scoped>
  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .auth-card {
    max-width: 520px;
  }
  .auth-card .el-alert {
    margin-bottom: 20px;
  }
  .pagination {
    justify-content: flex-end;
    margin-top: 16px;
  }
  .operation-alert {
    margin: 12px 0;
  }
  .create-form {
    margin-top: 16px;
  }
  .dependency-line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    min-height: 26px;
  }
  .dependency-label {
    width: 48px;
    color: var(--el-text-color-secondary);
  }
  small {
    color: var(--el-text-color-secondary);
  }
</style>
