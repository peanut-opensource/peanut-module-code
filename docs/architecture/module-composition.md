# 应用默认模块组合

`server/config/module_composition.json` 是宿主默认组合的唯一版本化清单。协议为 `peanut.application-module-composition.v1`，`schema_version` 为 1，`version` 标识组合契约版本，独立于模块版本。`mandatory` 是运行必需的根模块，`default` 是默认推荐的额外根模块，`optional` 是允许用户显式添加的根模块；三者必须是无重复、互不重叠的模块 key 数组，不能省略 `optional`。当前 `default`、`optional` 均明确为空；未声明的根不因出现在发行目录中而获准。依赖可以来自这些根分类以外，默认不安装 Demo 或整个目录。

MT 当前必选根来自宿主消费者：Identity 提供身份、权限和租户生命周期；File 提供公共资源和存储；Settings、ReferenceCodes 提供配置和目录同步；Integration 参与租户初始化；Ops 提供维护门禁。Task 和 ContentTools 通过模块依赖进入闭包，不在宿主再列一份。当前没有额外推荐根，因此 minimal 和 standard 的模块闭包相同；客户端交付 profile 仍由原有生成器决定。

`InitialInstallationSource::modules($serverRoot, $includeDefaults)` 按 Module manifest 递归收集闭包，然后调用现有 `PackageCheck::dependencyOrder()` 校验版本、循环及依赖顺序。生成器、首装默认/必选选择、初始 SQL 投影、无实例快照时的安装输入和默认模块 reconcile 共用此入口。缺少源码时返回 `INSTALL_MODULE_SOURCE_MISSING: <key>`，不静默退回固定名单。安装器仍以已有实例的 `official_modules` 快照维护其安装历史，后续组合变化不改写旧快照。

清单只声明产品组合，既不授予任何租户权限，也不把已停用模块重新启用。所有源码、Edition、公开导出、Composer、迁移、安装登记和数据保护继续由现有原生入口校验。宿主组合根现有工厂和安全适配仍须保留；某个符号未列入 exports 时不能通过删除消费者或把模块内部类型搬入 Core 来消除依赖。

## CLI 创建交接接口

CLI create 的 MT staged/approval 流程调用以下原生源码计划/准备合同。源码采用不等于数据库首装完成；ST 本轮暂缓。

1. CLI 从已验证的模板 inventory 中读取 `server/config/module_composition.json`；不在 CLI 保存第二份模块名单。minimal 选择 `mandatory`，standard 选择 `mandatory + default`。记录实际选择与清单摘要，模板组合版本不是模块版本 pin；客户端交付 profile 独立选择。
2. 用户选择官方发行渠道和固定索引，例如预发行 `v1.0.0-rc.1`；CLI 沿已有 `peanut.module-release-index.v1` 校验 Release、索引 digest、固定 source commit、模块版本、Edition、依赖范围、撤回状态及每个 TAR 的独立 SHA。稳定渠道缺完整闭包时明确拒绝，不能隐式改用 RC。按索引及包内同一依赖声明求完整闭包，拒绝版本冲突和循环，依赖先于消费者准备。
3. 未安装 MT 应用通过同一 `server/module` 的 `adopt --composition <request.json>` 先取得完整计划；准备时再传 `--plan-sha256 <reviewed-plan-sha>`。不能逐包试错发现依赖。单包 `adopt <tar>` 仍供明确的独立源码采用使用。桥接只加载安全环境及所需配置，建立原有 CLI 上下文，不构造 AppService、不启动业务 Module 或读取数据库；环境文件须为其他用户不可读写的普通文件，显式设 `APP_ENV=development` 与 `DEPLOYMENT_MODE=multi-tenant`。源码恢复仍使用 `adopt --recover`。
4. 整个最终闭包准备齐全后，在新应用自己的 Git 开发目录运行 `python3 scripts/project-env exec -- bash scripts/deploy-update.sh --prepare-release`。此既有入口在业务启动前加载 Edition 配置，只调用一次 `Dependencies::prepareRelease()`，MT 准备 Admin/Platform；各 Module manifest 决定依赖输入。Composer path 包、开发 metapackage、各端 workspace 与原生锁继续由现有机制处理；不能剪裁锁 JSON、伪造 content-hash 或复制其他应用 vendor。入口随后生成 API、构建客户端并编译 registry，不执行数据库安装。
5. 依赖准备、API 生成和 registry 编译依据实际最终输入执行；数据库就绪后交给现有安装器完成首次迁移、身份初始化、模块登记及目录同步。源码创建、来源采用、依赖准备、数据库安装和运行验收分别报告。

### 原生组合请求与响应

向 `php server/module` 的 stdin 写入 `{"operation":"adopt","arguments":["--composition","/absolute/request.json"]}`。请求例：

```json
{
  "schema_version": 1,
  "protocol": "peanut.module-source-composition-request.v1",
  "edition": "multi-tenant",
  "preset": "minimal",
  "optional": [],
  "composition_sha256": "<template-bound-composition-sha256>",
  "index": {
    "path": "/absolute/module-release-index.json",
    "sha256": "<independently-reviewed-index-sha256>",
    "repository": "peanut-opensource/peanut-module-code",
    "tag": "<fixed-release-tag>",
    "commit": "<fixed-40-character-source-commit>"
  },
  "archives": {"peanut.identity": "/absolute/verified-identity.tar"}
}
```

`archives` 必须提供整个最终候选闭包需要的 TAR，不只示例中的 Identity；保留较高已锁版本不需要对应较低候选 TAR。索引就是 CLI S2 已固定的 `peanut.module-release-index.v1` 原字节，不复制或重新选择 catalog。Code 核 SHA、repository/tag/commit、key/version/dependencies/editions/kernel/license/长度与包内声明一致；网络发行真实性由上游下载入口负责，本地摘要 pin 不等于公开渠道认证。

默认只读：不创建应用 `.local`、源码或锁；归档核验使用可写的进程临时目录并清理。`PackageCheck` 使用现有 Composer 范围匹配收集缺失/不兼容边及循环，`ManifestLoader` 读取实际声明，`ModuleLockResolver` 核锁与 canonical contents。完整图包含保留源码的反向依赖，并检查 Edition、Kernel、PHP/Core/扩展要求、宿主能力、命名空间、表归属、含 Host 初始源码的迁移身份和目标路径归属；Host 来源仍须满足现有 `InitialInstallationSource` 的应用源码身份合同。升级只可替换旧锁实际拥有的 scope，新增客户端 scope 已存在时拒绝覆盖。不会运行 Composer solver 或声称依赖已经安装。其他 Composer/npm/Web 依赖声明列在计划中，最终准入仍须后续原生依赖准备和编译。

响应含 `plan`、`plan_sha256`、`recovery`；`plan.protocol=peanut.module-source-composition-plan.v1`，状态为 `ready` 或 `blocked`，后者退出 1。计划列出 roots、固定 index/组合 SHA、Kernel、现锁 SHA、模块 add/upgrade/unchanged/retain/retain-higher、精确 archive/source SHA、依赖顺序、文件/锁写集、源码 before/after、迁移身份、备份及待办。所有模块均明确 `installation=uninstalled`，不查询或推断运行库的 disabled 状态；已安装/安装中标记拒绝进入此接口。

准备命令在原 source writer lock 内重新计算同一计划并比较 SHA，任一诊断或漂移都不采用源码。全部准入后，一次 `ModulePackageSourcePromoter` 事务封存全图 payload、各 TAR SHA 与最终 `modules.lock`，然后沿原 journal 前向完成；不按包分开提交。同版不同 canonical contents 或 TAR 字节拒绝，即使重新签名也不例外。兼容的较高已锁版本保留，不隐式降级；不兼容时返回联动诊断。

成功返回 `status=source-prepared`、已完成写集、原 transaction/receipt 和待完成运行步骤。失败响应给原生错误及 recovery 的 journal、transaction、已完成 scope 和 `adopt --recover`；结果未知时先对同一 handle 对账，不重放请求。源码、锁和 before-images 不等于数据库备份。整个操作不执行迁移、安装登记、租户开通或 RBAC，不宣称 Runtime 已采用。

### 宿主引用分类与源码退出条件

下表分类的是当前直接消费者，不表示这些引用已全部收敛到 Module exports，也不授权将业务搬入技术 Core。

| 分类 | 当前消费者证据 | 退出要求 |
| --- | --- | --- |
| 必需身份、租户与管理合同 | `AppService`、`InstallationExecutionHost`、`AdminAuthorizationService`、平台租户/会话/权限入口、adminapi auth/dept | 先取得 Identity；保留真实租户/RBAC合同，逐符号核 exports |
| 必需文件、配置与目录合同 | `StorageController`、`PlatformStorageController`、`ProductAssetReferenceService`、`ModuleCatalogApplier`、配置/工作台入口 | File、Settings、ReferenceCodes 从发行源采用；不能用空实现放行 |
| 必需初始化与维护合同 | `ApplicationTenantBootstrapService`、`NoticeChannelService`、`PaymentServiceFactory`、Ops 命令与维护中间件 | Integration、Ops 从发行源采用，Task/ContentTools 按依赖进入；业务授权仍由原入口承担 |
| 可选能力的条件消费者 | `ModuleComposition` 的 Document/File/Task 扩展、`ApplicationNoticeSmsSender`、`FirstRunReadinessHost`、`ModuleLifecyclePolicy`、`ModuleCheck` | 核未选择 Document/Notification/Workflow 时的条件注册及类加载；不能把可选业务变成 mandatory 来掩盖引用 |
| 宿主遗留业务消费者 | Member token/tenant resolver、Article decoration 服务、搜索/热搜/装修与通知服务 | 按真实业务归属逐项处理，保留已有合同；本批未迁移、未删源码 |

本批定向测试入口为 `PEANUT_TEST_MODULE_AUTHOR_ROOT=/absolute/path/to/peanut-module-code python3 scripts/project-env exec -- php server/tests/Productization/ModuleSourceCompositionTest.php`：官方 Module 作者源码从独立 Module 应用读取，Code 只提供 Host 测试和原生打包/组合入口，不再假定 Code 自身携带官方模块源码。测试覆盖本地 canonical TAR、无官方 Module 源码的临时宿主、AppService/业务 Module/DB 加载拒绝陷阱、全图一次 journal、重复/冲突/保留与恢复；不是公开 RC 渠道、真实 DB、登录或 HTTP 正向验收。未显式提供有效 Module 作者应用时直接拒绝，而不是让空源码导致后续打包失败。

## 模板退出与后续升级

发行 inventory 排除官方后端和各端作者源码，升级工具自身也使用投影后的应用 Composer 清单与锁，不夹带作者包。Code 的开发依赖和作者目录保留；它们不进入创建的应用。`server/resources/scaffold-application/composer.json` 与原生 Composer 生成的同目录 lock 是模板起始依赖，不包含 Module path 包或测试开发依赖。创建只生成空 `modules.lock`；完整锁和源码由 CLI 调用原生 adopt 后接收。客户端 workspace 保留通用扩展入口，API 只投影实际模块，宿主公共类型仍由现有 metadata 生成。

CLI `create --plan` 首先返回私有 staged consumer；按该消费者自己的工具登记和起始锁执行 Composer install，再使用同一 `--composition-stage` 重做 plan，审阅 SHA 后批准源码准备。CLI 不复制 vendor，不偷偷运行依赖安装。源码未准备时 HTTP 返回 503 / `INSTALL_MODULE_SOURCE_MISSING` / `uninstalled`，在业务绑定前停止；不会放行管理 API。采用后使用上面的既有准备入口，然后才可进行新库安装。真实 MT 数据库首次安装、登录、公共路由和租户隔离仍须独立验收；未执行时记 NOT_RUN，不据源码链通过宣称 S3–S5 完成或允许正式发布。

既有应用仍通过 `scripts/scaffold-upgrade` 的三方计划升级宿主；已安装且兼容的较新模块、同版摘要、应用定制、disabled 状态与恢复合同继续保留。宿主最低要求上升时列出联动模块计划，不静默降级或替换。composition 改动不授权数据库重置、现有应用写入或正式发布。
