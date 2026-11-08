# Task 作业页面复用

`peanut.task` 的定时任务页位于 `/system/crontab`，任务作业页位于
`/system/task-jobs`。前者配置已登记的命令与 cron 规则；后者读取持久任务账本，
按状态分页，取消排队中的作业或重试失败终止的作业。调度基准时间不代表命令执行完成。

## 正式入口与实际消费者

前端公开入口为模块包的 `@peanut-admin/peanut-task/optional-runtime`，源码是
[`optional-runtime/src/index.ts`](optional-runtime/src/index.ts)。它导出
`TaskJobPage`、`createTaskJobRuntime`、`createTaskJobFetchTransport`、
`taskJobRuntimeKey`、权限常量及请求/结果类型。根入口继续只负责路由贡献。
这是随应用源码装配的模块入口，不是独立发布到 npm 的组件包。

实际消费者 [`TaskJobsPage.vue`](TaskJobsPage.vue) 使用同一入口创建运行时、
注入页面并在卸载时释放请求；[`contribution.ts`](contribution.ts) 注册该页面。
宿主负责加载本模块 `views/locale` 的语言条目和 Element Plus 语言环境。

```vue
<script setup lang="ts">
import { onBeforeUnmount, provide } from 'vue';
import {
  TaskJobPage,
  createTaskJobFetchTransport,
  createTaskJobRuntime,
  taskJobRuntimeKey,
} from '@peanut-admin/peanut-task/optional-runtime';

// baseUrl、sessionFetch 和权限函数由当前宿主提供，参见 TaskJobsPage.vue。
const runtime = createTaskJobRuntime({
  transport: createTaskJobFetchTransport({ baseUrl, fetch: sessionFetch }),
  canRead,
  canManage,
});
provide(taskJobRuntimeKey, runtime);
onBeforeUnmount(runtime.dispose);
</script>

<template><TaskJobPage /></template>
```

## 输入、输出与生命周期

- `transport.list(status, page, pageSize, signal)` 返回 HTTP 状态、headers 和未知
  JSON；现有 SDK transport 调用 `/adminapi/api/v1/tasks/jobs`。
  `parseTaskList` 校验后提供脱敏 `TaskJob[]`、页码、页大小与总数；页面每页 20 条。
- `runtime.load()` 刷新当前页；`setStatus(status)` 切换状态并回到第一页；
  `setPage(page)` 加载指定的正整数页。页面提供总数和前后翻页，加载或操作期间禁用翻页，
  操作期间禁用状态切换。较早列表请求不会覆盖新的列表结果。
- `cancel(job)` / `retry(job)` 使用 `jobKey` 与 `revision` 调用服务端动作，成功后刷新。
  它们不提交任意任务、处理者、命令或私有 payload，也不自动轮询。
- `canRead` / `canManage` 是界面能力提示，分别对应
  `peanut.task.jobs.read` / `peanut.task.jobs.manage`；最终权限、当前租户范围、
  可操作状态和 revision 冲突仍由服务端检查。取消仅提供给 queued，重试仅提供给 dead。
- 本地读取权限拒绝显示 403；HTTP 401、403、503 使用现有专用状态，其他错误展示
  错误信息和可用的 request ID；网络或无效响应显示请求失败，不当作空列表。
- 每个挂载页面创建自己的运行时。卸载调用 `dispose()` 中止请求；切换账号或租户时
  宿主必须释放旧运行时并创建新实例。正式消费者使用 `tenantSessionFetch`，请求会校验
  当前会话，避免返回上一会话的数据。不要跨租户缓存或共享运行时。

后端持久任务的处理者及 checkpoint/失租合同见
[`job/README.md`](../../../../server/app/modules/peanut/task/job/README.md)。
本页面只管理脱敏作业状态，不替代所属业务模块的授权、提交与结果交付入口。

## 自定义状态视图与重试

复用上面的 runtime/provider，但自定义页面不必渲染 `TaskJobPage`；它可读取
`runtime.state.items/status/page/total/loading/mutating/error`，在挂载时调用一次
`load()`。若渲染 `TaskJobPage`，由该页面完成首次加载，父组件不重复调用。
页面没有约定公开 slots，不向它传入未声明的状态/重试插槽。

例如只观察失败终止的作业并显式重试当前选择项：

```ts
// runtime 由上面的正式工厂创建，selectedJobKey 来自自定义页面当前选择。
// 该示例替代内置页面自己的首次加载。
await runtime.setStatus('dead');
const selected = runtime.state.items.find((job) => job.jobKey === selectedJobKey);
if (selected?.status === 'dead' && runtime.canManage()) {
  await runtime.retry(selected);
}
// retry 成功后由 runtime 自己刷新，不再追加一次 load()。
// 先查看 state.error；Promise resolved 不表示作业业务已执行成功。
```

`queued` 表示待执行、`running` 表示处理中，`succeeded/dead/cancelled`
分别为成功、失败终止和取消，五种状态沿现有 `TaskStatus` 呈现。
重试提交使用原 `jobKey/revision`，不创建另一份业务作业或覆盖原尝试账本。
错误例：无 `canManage` 时 runtime 不发取消/重试请求；无读取权限时列表记录
403。服务器仍拒绝状态或 revision 不符的动作，页面保留错误/request ID，
重新读取后由用户决定，不自动重放或把 dead 显示成成功。

## 调度与 handler 的既有链

PHP 通过模块公开 `Contract\TaskScheduler` 的原生绑定调用调度，
`runDue(int $now)` 的时间为 Unix 时间戳；`start(TenantScope $scope, array $item)`
只接收可信调度 Scope 和已登记 crontab 项。两者均要求没有环境执行上下文的
原生调度入口，并沿 `TaskJobRuntime` 入账/执行；不是普通租户 HTTP 页面的
“立即执行任意命令”接口。已有上下文调用会在入队前拒绝
`TASK_EXECUTION_ROOT_REQUIRED`，不能清空别人的上下文绕过它。

处理者实现公开 `Contract\TaskHandler` 的 `key()` 和
`handle(AuthorizedOperationContext $context, JobExecution $execution)`，经既有
应用组合/注册链选定，提交者经 `TaskSubmissionProvider` 构造可信 payload。
页面只观察账本，不注册 handler、不提供类名/命令/payload，也不另建浏览器调度器。
handler 的 checkpoint、失租停止、外部幂等和恢复规则沿上面的后端 README；
外观修改不改变执行权限、审计或业务结果交付责任。
