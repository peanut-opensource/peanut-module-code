<script setup lang="ts">
  defineProps<{ value: unknown }>();

  const labels: Record<string, string> = {
    key: '标识',
    name: '名称',
    status: '状态',
    code: '原因码',
    reason: '说明',
    message: '说明',
    description: '说明',
    version: '版本',
    required: '必需能力',
    optional: '可选能力',
    dependencies: '依赖模块',
    dependants: '被依赖模块',
    module_key: '模块',
    package_key: '模块',
    constraint: '版本要求',
    installed_version: '已安装版本',
    available: '可用',
    satisfied: '已满足',
    routes: '路由',
    permissions: '权限',
    middleware: '中间件',
    method: '方法',
    path: '路径',
    operation_id: '操作标识',
    operationId: '操作标识',
    access_mode: '访问模式',
    permission: '权限',
    permission_key: '权限标识',
    permission_keys: '权限标识',
    audience: '安全域',
    risk_level: '风险等级',
    public_services: '公开服务',
    provider: '服务提供者',
    class: '类型',
    lifecycle: '生命周期',
    installed: '已安装',
    pending: '待处理',
    migrations: '数据迁移',
    documentation: '文档',
    declared_path: '声明路径',
    files: '文件',
    file_count: '文件数量',
    total_bytes: '总字节数',
    size: '字节数',
    sha256: 'SHA-256',
    inventory_sha256: '文件清单摘要',
    events: '事件',
    tasks: '任务',
    commands: '命令',
    public_contracts: '公开契约',
    task_key: '任务编号',
    current_step: '当前步骤',
    operation: '操作',
    result: '执行结果',
    recovery_pointer: '恢复记录',
    error_code: '错误码',
    source_commit: '源码提交',
    source_tree: '源码树',
    created_at: '创建时间',
    updated_at: '更新时间',
    completed_at: '完成时间',
    migration_count: '迁移数量',
    applied: '已应用',
    skipped: '已跳过',
    changed: '已变更',
    target: '目标',
    backup_reference_key: '备份编号',
    file: '文件',
    source: '来源',
    namespace: '命名空间',
    interface: '接口',
    implementation: '实现',
    tenant_enabled_count: '开通租户数',
    pending_actions: '待办',
  };
  const states: Record<string, string> = {
    active: '启用',
    inactive: '停用',
    retired: '已停用',
    ready: '就绪',
    available: '可用',
    unavailable: '不可用',
    installed: '已安装',
    not_installed: '未安装',
    missing: '缺少',
    pending: '待处理',
    blocked: '已阻断',
    queued: '排队中',
    running: '执行中',
    succeeded: '成功',
    completed: '完成',
    failed: '失败',
    disabled: '停用',
    platform: '平台',
    tenant: '租户',
    system: '系统',
    custom: '自建',
    declared: '已声明',
    discovered: '已发现',
    registered: '已登记',
  };
  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
  }
  function secretKey(key: string): boolean {
    return /password|secret|credential|(^|_)token($|_)|private_key|access_key/i.test(
      key
    );
  }
  function display(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? '是' : '否';
    if (typeof value === 'string') return states[value] || value;
    if (typeof value === 'number') return String(value);
    return '—';
  }
</script>

<template>
  <dl v-if="isRecord(value)" class="structured-facts">
    <div v-for="(item, key) in value" :key="key" class="structured-fact">
      <dt>{{ labels[key] || key.replace(/_/g, ' ') }}</dt>
      <dd>
        <span v-if="secretKey(key)">已隐藏</span>
        <StructuredDetails v-else :value="item" />
      </dd>
    </div>
    <div v-if="Object.keys(value).length === 0" class="structured-empty"
      >无记录</div
    >
  </dl>
  <ul v-else-if="Array.isArray(value) && value.length" class="structured-list">
    <li v-for="(item, index) in value" :key="index"
      ><StructuredDetails :value="item"
    /></li>
  </ul>
  <span v-else-if="Array.isArray(value)" class="structured-empty">无</span>
  <span v-else class="structured-value">{{ display(value) }}</span>
</template>

<style scoped>
  .structured-facts {
    margin: 0;
    width: 100%;
  }
  .structured-fact {
    display: grid;
    grid-template-columns: minmax(100px, 150px) minmax(0, 1fr);
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }
  dt {
    color: var(--el-text-color-secondary);
    overflow-wrap: anywhere;
  }
  dd {
    margin: 0;
    min-width: 0;
  }
  .structured-list {
    margin: 0;
    padding-left: 18px;
  }
  .structured-list li + li {
    margin-top: 8px;
  }
  .structured-value {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  .structured-empty {
    color: var(--el-text-color-secondary);
  }
  @media (max-width: 600px) {
    .structured-fact {
      grid-template-columns: minmax(0, 1fr);
      gap: 4px;
    }
  }
</style>
