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
租户可用的私有 CSV 文件。页面不上传文件，也不接受路径、SQL 或任意类名。当前默认
注册的 `app.operation-log` 是只导出的真实消费者，不支持导入；接入导入需要实际业务
提供者及其正式注册，不能把操作日志导出当作导入示例。

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
