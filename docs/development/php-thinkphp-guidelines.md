# PHP／ThinkPHP 开发规范

生效。具体开发入口见[应用约定](application-conventions.md)，实现以锁定的 ThinkPHP 版本、实际调用链和源码为准；外部教程不能替代本项目合同或验证。

## 1. 当前已确认的原则

复用锁定 PHP/ThinkPHP 正式启动、容器、路由、中间件、Validator、ORM/事务、同步事件和 CLI；不造平行框架，不机械为每类加接口/Repository/Factory。接口用于真实替换/跨模块合同，公开普通类也可。类型、异常、事务、数据访问一致；注释不执行授权，别框架/版本教程不能代替本项目源码验证。

业务服务构造注入；Controller 公共环境由基类，共享服务优先显式 xxClass/只读属性；方法注入及有实际用途的固定 Getter 仍可用。类名由受信源码固定，不按请求/任意属性/全局状态猜依赖；遵守生命周期和模块公开面。

## 2. 参考材料融合

核对 Composer lock、自动加载与当前调用链，再对照锁定版本的框架源码和正式扩展点；将结论落实到可运行样板、生成器或检查中。不复制平行规则库，也不根据其他框架或版本的教程猜测 API。

## 3. 规范覆盖

公开输入输出用明确类型/枚举/数据对象；Trait 复用小行为不藏依赖；Attribute 仅元数据，须有正式执行者。解释服务注册、请求/任务/安装生命周期，路由受众与授权，ORM 关联/Scope/事务/批量查询，幂等/异常/恢复，事件/任务/调度，配置/文件/缓存，接口注释/自动生成及静态/运行测试。每个主题给适用场景、推荐代码、反例、理由、例外、验证，不为空目录/规则数量造材料。

## 4. 版本与加载

实施核对 manifest、正式 lock、实际加载路径、测试提交及支持环境；四者不同，不以声明范围或开发链接证明正式安装。依赖精确调整，不用 alias/手改版本/永久兼容重载掩盖。历史开发路径不构成运行依赖；升级与恢复均须识别实际来源和目标。

## 5. 已确认的关键安全合同

### 身份模块调用

角色/部门/成员写入和个人账户操作使用已认证 TenantContext，不拼客户端 tenant_id/裸人 ID 当身份；仍接收整数租户的查询不机械替换。身份模块维护审计/授权修订/归属/会话，Core 提供技术协议；公开签名必须反射实际加载类。缺省 User-Agent 等可选输入保持既定安全处理，不因接口迁移改变语义。

### 平台密钥

`platform_auth.identifier_hmac_key` 去两端空白后至少32字节；缺失/空白/过短报 `PLATFORM_AUTH_CONFIGURATION_UNAVAILABLE`，不造默认密钥、不输出秘密。实际平台认证惰性装配时检查，单纯注册不强制配置；独立租户登录只依其 `tenant_auth`。合法 HMAC 输入/协议保持。

### 会员本人字段

`UserController::setInfo → UserApplicationService → MemberProfileContractService::updateSelfField` 共用 `app/common/validate/MemberProfileSelfFieldValidate`；HTTP 在头像转换前拒错误类型，公开写入口核入库不变量。不允许改账号/余额等额外字段，也不接受其他人/租户编号越权。

| 字段 | 类型/限制 | 清空 |
| --- | --- | --- |
| nickname | 字符串≤50字符，不按字节 | 空串 |
| avatar | 原值为字符串，转稳定引用后≤255字符 | 空串 |
| email | 合法邮箱字符串≤100字符 | 空串 |
| sex | 整数0/1/2或对应单字符字符串；未知/男/女 | 0，非null |
| birthday | 1000–9999年的真实YYYY-MM-DD，校闰日 | 空串/null写NULL |

非法字段/值分别 `MEMBER_PROFILE_FIELD_UNSUPPORTED`、`MEMBER_PROFILE_VALUE_INVALID`。先在租户范围确认存在再写；同值更新/重复清空成功，affectedRows=0不等于不存在。不合并员工/会员模型或批改既有资料。

### 验证码

`notification.verification.max_failed_attempts` 读 `NOTIFICATION_VERIFICATION_MAX_FAILURES`，默认每次签发5次失败。`NoticeLog.check_count` 在行锁内先查阈值再验哈希；达阈值后正确码也拒绝。仅验最新成功签发，重发归零，旧码不可回退；保留哈希/到期/租户/用途/发送预留/一次消费。

先提交失败计数再抛拒绝，不让外层事务回滚计数。发送频率限制不是验证次数限制。补充入口按租户＋用途＋手机号＋IP摘要：300秒窗口、最多10次失败，键 `NOTIFICATION_VERIFICATION_ENTRY_WINDOW_SECONDS` / `NOTIFICATION_VERIFICATION_ENTRY_MAX_FAILURES`，不存明文号/验证码。File Cache读改写非跨进程原子，硬次数和一次消费靠MySQL行锁，不永久锁账号。

匿名验证码入口由可信Host绑定产生受限TenantSystemContext，走ConsumerExecutionContext::publicTenant及原ModuleExecutionContext::system，仍检查部署/租户模块，不用匿名路径放大权限。

## 6. Controller 与应用用例的固定边界

Controller解析/校验HTTP输入并映射输出；外部回调绑定/签名/模块/业务及管理操作授权由明确用例编排。HTTP路由权限/演示环境写限制保留，非HTTP用例也授权；不能提供任意permission＋Closure的通行证。

`tenantAdminActor()` 与TenantContext的人/账号/租户一致性必须核验；不是有人员对象就可执行。上下文Getter/魔术只读及namespace规则由[Controller规范](controller-access-and-namespaces.md)唯一维护。声明式解析、类型注释、模板和运行验证保持一致；不能只因写法不同就把有用的Getter判错。

## 7. 模块命名与自动加载

业务 `PeanutAdmin\Modules\<Module>\...` 对应模块src；应用装配用app，真实Core/第三方技术前缀保留。Composer声明、HostLayout、Provider/公开导出/生成器同源；拒前缀抢占/大小写歧义。逐符号迁移连同消费者验证，不整前缀替换、不以模型换名绕私表边界。详见Controller规范§5–7。

## 8. 外部回调的上下文边界

沿用现有绑定、验签和模块机制，不因历史类型名差异另造身份域。原始正文/签名确认先于业务写，不能凭任意 `tenant_id` 获信任；保留渠道确认响应、防重复，并在 `finally` 恢复上下文。命名迁移不能改变公开安全类型或权限语义。

生成顺序：API/SDK → 模块制品摘要与 `plugins.lock` → 模板清单 → 编译验证。摘要不符时修正源并重新生成，不关闭完整性检查。

## 9. 租户设置导入导出的模块边界

ImportExport 的 `TenantSettingsConfigurationAdapter` 构造注入 Settings 公开的 `PeanutAdmin\Modules\Settings\Contract\TenantSettingsTransfer`；不直接查询、连接或修改 Settings 私表，也不取得内部 Provider/Model。Settings 在自己的 ModuleProvider 绑定实际服务，通过既有 `TenantSettingSnapshot` 返回稳定结果；普通 `TenantSettingsCommands::replace` 没有导入所需的预期修订号，不能拿它替代条件写入。

`current` 对不存在的命名空间返回 null；`snapshot` 只读取当前租户，并按命名空间排序。`apply` 的 revision=null 表示仅创建，非空 revision 必须等于规划时的现存修订号；目标消失、已被创建或修订变化均不得盲目覆盖。成功替换保留创建时间并增加修订号，JSON错误明确拒绝，不把损坏配置变成空文档。

Settings 拥有事务内的数据访问，包级应用继续拥有最外层事务和审计；后续适配器或审计失败时，前面的设置写入必须一起回滚。公开合同供受信应用服务使用，不新增HTTP入口、不自动授予动作权限；调用者仍须完成相应身份、模块和动作授权。

秘密引用的脱敏、重绑定和包格式仍由 ImportExport 现有 codec 负责。原始快照不得直接作为HTTP响应、日志或导出文件。该边界的回归入口为 `server/tests/Unit/TenantSettingsTransferBoundaryTest.php`，覆盖真实ORM读写、修订冲突、跨租户隔离、外层回滚和脱敏；进程内合成数据库测试不能替代MySQL并发锁或真实HTTP权限验收。

## 10. 渠道绑定与租户生命周期

Integration 的绑定存储构造注入 Identity 已公开的 `AdminDirectoryQuery`，通过 `tenantStatus(tenantId, forUpdate)` 获取最小生命周期状态；不连接或查询 Identity 私表。该查询只返回状态，不授予调用者权限。带锁读取参加调用者事务，先锁租户再锁渠道绑定，不通过返回 Query/Model 把锁职责转交其他模块。

回调候选保持最多两条的歧义检查，不能过滤停用租户或孤立绑定后只剩一条就接受。绑定指向不存在的租户时明确拒绝；停用租户仍保持不可用状态。渠道签名校验、受限系统身份和审计继续由原解析用例处理，不以状态为active代替验签。

ImportExport 的 `ExternalBindingConfigurationAdapter` 构造注入 Integration 的公开 `ExternalBindingTransfer`；Integration 负责自有绑定数据，Identity 负责租户状态及锁。`snapshot/current` 返回稳定数组，不带callback_key；其中原始配置仅限受信调用链，公开序列化前必须经过现有秘密引用codec。`apply` 只接收identity_hash、identity_hint、config、status，保留当前callback_key和创建时间，创建时生成新的callback_key。修订令牌仍根据完整持久状态生成，同秒内内容变化也必须冲突；null仅创建，非null必须匹配现存状态。修改、秘密重绑定、外层回滚和停用拒绝不得因移动代码而失效。

回归入口为 `IntegrationTenantBoundaryTest.php` 与 `ExternalBindingTransferBoundaryTest.php`，位于 `server/tests/Unit/`。真实数据库锁竞争、HTTP动作授权和渠道外部行为需要各自的集成验证。

## 11. 模块业务设置

业务配置由所属模块声明 backend.setting_definitions，复用 Settings 的 JSON Schema 校验、允许覆盖作用域、默认值、秘密处理和原生事务。租户覆盖使用 pa_setting_tenant_value，不将配置和许可证权益混存，也不新增逐租户模块配置表。需要整份对象约束时可声明一个 object setting，不机械拆字段丢失跨字段校验。

当前没有旧模块整份配置导入导出的生产消费者；不为退出旧开通表保留配置传输兼容层。网站/应用设置的 TenantSettingsTransferService 与定义型租户值是不同合同，不能声称前者已经覆盖通用租户设置导出。真实导出需求应在 Settings 所属边界处理，配置导入不能开通模块或授予权限。

## 12. CLI 工作器的模块接口与依赖注入

部署 CLI 消费 Ops 已登记的 `BackupTaskExecution`、`RestoreTaskExecution`、`ModuleTaskExecution` 或 `UpgradeTaskExecution`，由原 ModuleProvider 映射到既有执行器。命令构造注入所需接口及 `ExecutionContextStore/CurrentExecutionContext`，不得在执行方法中全局 `app()` 查找内部执行类；为兼容框架的可空构造参数不代表依赖可省略，执行时缺依赖必须明确失败，不能自动换另一执行来源。ThinkPHP 的列表描述器无参构造命令元数据；有必需业务依赖的命令使用原生公开静态 `__make(App $app)` 显式装配，不能依靠可空参数自动注入未绑定类型，也不能假定 Console 的 `invokeClass()` 会采用命令类的容器绑定。

接口仅表达现有固定任务动作、任务编号、修订号和必要结果材料，不接受任意 SQL、命令或部署路径。接口公开不是部署授权；原执行器仍检查任务状态、操作人员、修订 fencing、允许错误码、输入摘要和证据。CLI 的标准输入大小限制、错误脱敏与上下文恢复保持，不能为了迁移类型而放宽。完整备份、恢复、模块操作或升级仍需独立的资源与执行授权，本规范测试不运行这些操作。

模块请求 CLI 另消费 `DeploymentModuleRequests` 的 `preview/prepare`，复用组合根已有的配置与签名密钥装配，不在执行方法中重建治理服务。公开接口不含内部请求行查询或 `execute`；准备成功不等于获准安装、停用或删除。非法配置在原生依赖构建阶段失败，不回退到宽松配置。工作器贡献由已登记的 `TaskWorkerDefinitionRegistry` 在宿主技术组合根解析，重复授权键、处理者键、所有者不符和空处理者集合仍拒绝；登记和解析不执行任务，也不代替任务运行时重新授权。平台状态权限标识由 `OpsStatusService::READ_PERMISSION` 提供，权限查询接口公开不意味查询会返回允许。

`server/tests/Unit/OpsWorkerContractTest.php` 用原生 ThinkPHP Input/Output、容器与上下文，以及合成工作器验证调用合同；公开面检查不公开实际存储/执行实现。实际部署工作器与数据库并发必须单独验证，单元通过不能宣称真实备份或升级成功。
