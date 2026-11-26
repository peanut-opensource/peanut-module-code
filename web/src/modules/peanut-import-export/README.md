# 导入导出复用入口

`@peanut-admin/peanut-import-export` 默认导出应用路由贡献；
`@peanut-admin/peanut-import-export/runtime` 导出已有的页面、运行时、传输层和类型。
实际消费者是本模块 `OperationsPage.vue`，由正式 contribution 的
`/system/import-export-operations` 路由加载。

```ts
import { onBeforeUnmount, provide } from 'vue';
import {
  createImportExportFetchTransport,
  createImportExportRuntime,
  importExportRuntimeKey,
} from '@peanut-admin/peanut-import-export/runtime';

// authenticatedFetch 和权限函数由宿主提供，沿用当前租户会话。
const runtime = createImportExportRuntime({
  transport: createImportExportFetchTransport({ baseUrl, fetch: authenticatedFetch }),
  canRead,
  canCreate,
  canCancel,
});
provide(importExportRuntimeKey, runtime);
onBeforeUnmount(runtime.dispose);
```

在该 provider 的后代渲染 `ImportExportPage`，或用 `runtime.state` 构建业务界面。
`load` / `setStatus` 读取分页列表；`submitExport(providerKey)` 和
`submitImport(providerKey, fileKey, mapping)` 提交异步 CSV 操作；`cancel(operation)`
带当前 revision 取消；`download(fileKey)` 下载结果或行错误文件。命令完成后刷新列表，
新建成功切到第一页排队状态。提交完成只代表异步任务已入队，不代表业务数据处理成功。

`mapping` 为非空的 CSV 表头 → 提供者字段名字符串字典；导入文件必须已存在且为当前
租户可读的私有 CSV 文件。导入操作不接受路径、SQL 或任意类名。`peanut.article.drafts`
在采用 Article 草稿导入合同的实例中可用。UTF-8 CSV 使用 `title,cid,body` 表头，
`cid` 是当前租户的现有栏目 ID，`body` 是可留空的纯文本。有效行写为隐藏草稿，
无效行进入现有错误报告。提交人须拥有文章新增、原文件读取和对应导入操作权限。
`app.operation-log` 仅支持操作日志导出。

权限分别为 `peanut.import-export.operations.read/create/cancel`。前端回调控制交互，
服务端仍按可信租户、人员、提供者和对象权限独立授权。运行时不跨租户共享；宿主切换
租户或销毁视图时调用 `dispose`，它中止在途读取/命令/下载并清空列表。中止 HTTP 请求
不会撤销已提交的服务端任务；需要通过正式取消入口处理。重复点击在途命令被忽略，
每次新提交使用独立幂等键；网络结果未知时先查询任务，不能据此认定没有创建任务。

错误保存在 `state.error`（HTTP status、message、requestId）；方法不以返回值表示
业务成功。401 重新登录，403 检查权限，404 检查文件/任务保留期，409 刷新任务版本，
422 检查提供者和输入，503 检查模块/提供者可用性。页面展示任务 `lastErrorCode`，
逐行失败使用错误明细下载。默认浏览器下载保存为 `<fileKey>.csv`；宿主可用
`saveDownload` 注入保存行为，用 `idempotencyKey` 注入既有请求标识生成方式。

后端正式用例为 `ImportExportCommands` / `ImportExportQueries`（见模块 `module.json`
公开声明）；输入必须是正式授权链产生的 `AuthorizedOperationContext`，不能由外部
请求构造。稳定结果是 `AsyncExportOperation`，不导出持久化 Model。现有
`OperationLogExportApplicationService` 与宿主 `OperationLogExportProvider` 是真实
导出消费者；配置迁移另用 `ConfigurationTransferCommands/Queries`，不通过 CSV
运行时混用配置包协议。


## 配置包预览与写入确认

配置迁移页面由本模块自己的 `views/index.vue` 提供，使用原生
`POST /adminapi/peanut.import-export.configuration.dry-run` 与
`POST /adminapi/peanut.import-export.configuration.apply`。HTTP 读写能力由
Identity/配置适配器的原生租户权限继续判断，不能仅靠按钮可见性通过授权。

一次可应用 dry-run 只对应当时的**完整输入**：包 JSON 文本、冲突策略、
每个占位密钥的当前绑定。用户修改任何一项即立即撤销页面保存的可应用
预览；运行中的旧 dry-run 即使最后返回成功，也不能恢复对新输入的应用
资格。确认框期间检查输入修订及服务端返回的预览摘要，防止确认时替换目标。
同一页面最多允许一次 dry-run / apply 请求，不自动重发不确定的写操作。

后端 `ConfigurationTransferApplicationService::apply` 会再次构造当前规划、
检查密钥重绑定和冲突，并在同一数据库事务内写入各适配器及审计；
前端修订锁**不取代**服务端校验。HTTP apply 已执行但响应丢失时，
不得凭网络异常推定没有生效。页面清除旧预览，提示重新导出当前配置、
复核后再决定是否提交。成功后清空密码输入；保留服务器返回的已应用回执，
且不允许再次对其执行 apply。

定向回归使用当前 Vue SFC 脚本的真实响应/修订控制，
不需要业务库、密钥或外部 HTTP；真实有权租户的干跑→变更→恢复→
事务写入/撤销验收另行按原资源登记进行。


## 异步任务操作回执与未知结果

任务页 `OperationsPage.vue` 使用原 `@peanut-admin/peanut-import-export/runtime`
及当前租户的 `tenantSessionFetch`，不直接调用数据库、队列或跨租户身份。
`submitImport`/`submitExport` 的 HTTP 201 只证明已受理异步操作，
`cancel` 的 HTTP 200 只证明相同操作 ID 已进入 `cancel_requested` 或
`cancelled` 状态；最终结果仍要重新读取原生任务列表。

受理后展示服务端返回的操作编号、Task Job 编号和追踪请求 ID；
后续任务列表 5xx/网络异常不得覆写这条真实成功回执或自动提交第二次任务。
如果提交响应丢失或格式不符，结果标记为 `unknown`，禁止带新幂等键盲目
重发；用户必须先成功读取当前租户任务列表，并显式确认已核对结果，
才能发起下一次新的操作。明确 409 拒绝保留服务端请求 ID，不得报成功。
任务列表发生 401/403 或读权限被撤销时，清空之前载入的任务元数据和回执。
其它暂时性读取失败可保留旧行，但显示错误，不能冒充当前新结果。

已受理命令后若用户在途改变状态筛选，保持用户选择而不是强制切回 queued。
宿主 Article 草稿 CSV 提交只在已确认服务端受理时清除当前选中文件，
不以随后任务列表 GET 的成功失败推定初始 POST 是否提交。
版本候选未安装前的前端定向测试不等于真实 Worker/任务终态验收。
