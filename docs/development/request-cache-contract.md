# 请求缓存与失效合同

本页描述当前应用采用的请求链；修改这里列出的写入口时，同时检查相应失效责任。缓存使用 ThinkPHP `Cache` 与当前配置的原生存储，业务查询使用 ThinkORM Model/Query/paginate；不新增缓存框架或通用 SQL 执行器。

## 1. 请求顺序

可信 Host/客户端缓存识别 → 实时验证 Token、Session、账户/成员/租户状态及安全版本 → 核对域名租户与凭证租户一致 → 复用身份资料 → 检查模块部署、租户许可与接口权限 → 计算业务数据范围 → 原生分页与摘要关联 → 复用当前页负责人名称 → 返回。

登录先验证账户；有多租户时按既有 challenge/select 合同进入指定租户。业务请求使用已选择租户的会话。接口权限、商业许可和记录可见范围是三项不同判断，不能互相替代。

客户端由 Web Core `@peanut-admin/client` 的 `createSessionRecovery` 统一协调会话版本、并发刷新和失效清理；宿主 `web/src/api/tenant-session-refresh.ts` 只登记真实刷新协议、失效判定、本地业务清理和登录导航。Axios 适配和 `createSessionFetch` 共用同一实例。模块 fetch 发送前校验 API origin 与租户路由，只有控制器前拒绝的认证失败允许自动重试一次。刷新凭据被明确拒绝，或重试后仍为凭据失效时，清理对应会话并立即跳登录页，保留返回路径，不等待确认弹窗或再次刷新页面，也不额外请求远程退出。网络故障、取消、403及旧会话晚到响应不触发当前会话退出。Token/Cookie 的业务发行、撤销及租户安全域仍归 Identity 和宿主，不整体搬到技术 Core。

客户端使用公共会话机制时，必须在自身 manifest 和原生锁中直接声明 `@peanut-admin/client`，并由当前应用的 Axios 与模块 Fetch 共用同一宿主恢复实例；不得依赖其他包的传递依赖或保留私有的并发刷新、重试和失效默认实现。开发期本地 Core 源码链接、固定依赖安装、静态构建产物和实际 HTTP 运行加载是四种不同的采用状态，分别核对，不能用源码或类型检查代替运行时采用。业务登录地址、跨标签 Cookie 锁、撤销写入、菜单清理及返回地址仍由宿主和 Identity 按当前产品合同负责。

运营平台的 `platform/src/api/platform.ts` 通过已有 `@peanut-admin/vue` 的 `createMemoryRefreshCoordinator` 合并同一平台会话代际与失败 Token 的在途刷新；平台的登录/退出、Cookie 顺序、token 存储、业务错误 envelope、页面状态清理继续由宿主负责。不新增第二套刷新 Map 或改变客户端 Token 协议；同标签请求合并不等于跨标签会话安全已完成验收。

## 2. 哪些事实实时读取

- 域名入口按 `(规范化 host, client_key)` 查询。`tenant_id` 是输出，不是域名查询的输入。`TenantEntryBindingResolver` 在同一原生 Request 中复用结果；成功映射跨请求缓存300秒。未知、停用及非法 Host 沿入口策略拒绝，不缓存失败，不把默认租户当错误域名的兜底。事务内直接读库。
- `ThinkPhpTenantAuthRepository::sessionByTokenHash` 用不可逆 Token 摘要查会话，通过一条关联查询取得 ID、当前状态、安全版本和授权版本。ID 来自服务端可信记录，客户端提交的 tenant/member/account ID 不替代鉴权。
- Token 为不透明随机凭证；本轮没有改 JWT，也没有把“解析出 ID”当成通过认证。未来若选择自包含凭证，必须验证签名、受众、有效期，并保持服务端撤销语义。
- 客户记录的租户归属、负责人/部门/有效共享及业务搜索仍在最终 SQL 中执行；不能缓存一个客户 ID 集合后跳过当前业务范围。

## 3. 缓存表

所有下列键均使用配置中的应用、数据库和环境 namespace。派生数据的 TTL 为 300 秒；Host 入口的当前 revision 键无 TTL，绑定/租户状态写入成功后旋转。修订号控制更新可见性，派生数据的 TTL 用于旧数据回收。

| 内容/Owner | 键（省略 namespace） | 读取/重建入口 | 失效依据 |
| --- | --- | --- | --- |
| Host/客户端到租户映射/Identity | `identity:tenant-entry:v2:entryRevision:hash(host,client_key)` | `ThinkPhpTenantEntryBindingLookup::binding`；miss 核对绑定和租户状态 | 绑定启停及租户状态事务成功后旋转原生缓存 revision；旧请求回填的旧 revision 不再被新请求选择 |
| 业务接口当前成员资料/宿主+Identity | `identity:admin-principal:v1:T:M:MA:TA:TR:PR` | `AdminAuthorizationService::principalForRequest`；miss 读取成员、账号与角色，不计算可切换租户数量 | 成员/角色授权版本、租户版本、账号/成员资料时间戳摘要 |
| 成员有效权限/Identity | `identity:permission-snapshot:v1:T:M:MA:TA:licenseState` | `CoreTenantModuleAdminBridge::permissionInputs`；miss 通过 `TenantAuthorizationQuery::permissionSnapshot` 计算完整有效集合 | 成员/租户授权版本；许可证状态随当前时间计算 |
| 已安装模块字典/Identity | `identity:installed-modules:v1:T:TA` | `TenantModuleStateQueries::installationRecord`；miss 一次读取完整安装字典；按 module_key 取值 | 目录及安装生命周期在同事务递增所有租户授权版本 |
| 租户公共资料/Identity | `identity:tenant-profile:v1:T:TR` | `AdminDirectoryQuery::tenantProfile`；id/code/name/display_name/locale/timezone | `PlatformTenantAdminService::updateTenant/transitionTenant` 递增租户 revision |
| 当前页负责人名称/Identity | `identity:member-names:v1:T:TA:hash(sortedIDs)` | `AdminDirectoryQuery::memberDisplayNames`；miss 按租户批量读取唯一成员ID，最多500个一批 | 成员写入口和自助改名在事务内递增租户授权版本 |
| 已有许可证及套餐模块/Identity | `identity:license:v1:T:TA`、`identity:plan-module:v1:…` | `TenantEntitlementService` | 套餐/许可写入口推进 tenant authorization_revision；许可时间窗每次重新判断 |

T=tenantId，M=memberId，MA=member authorization_revision，TA=tenant authorization_revision，TR=tenant revision，PR=account/member updated_at 摘要。模块字典内容是系统级数据，目前复用已有租户授权版本失效，因此不同租户可能保存相同字典；没有另建全局版本表。

除鉴权前的 Host 映射以外，以上请求资料缓存仅接受 `TenantAuthService::authenticatedRead` 给出的当前 HTTP GET、同一个已验证 context、无数据库事务的请求。CLI、写请求、锁定读取、事务内读取沿实时路径。请求结束后原生 Request 中的身份和结果不进入跨请求静态对象。Host 映射只确定入口，不授予会话或记录访问权。

身份资料的 `updated_at` 是毫秒时间戳，不是严格单调资料版本；权限及停用不依赖它。若后续要求同毫秒连续资料更新也必须立即显影，应复用正式资料 revision 或按实际资料值生成摘要，不能据 TTL 宣称绝对即时更新。

## 4. 写入口必须承担的责任

| 变更 | 正式 Owner/入口 | 必须同步更新的事实 |
| --- | --- | --- |
| 退出、全部退出、刷新、刷新重放 | `TenantAuthService` / `ThinkPhpTenantAuthRepository` | 撤销对应 Session/Token 或旧 access token；保留审计记录，不要求物理删行 |
| 密码、账户停用 | Identity 账户/认证服务 | account security_revision；密码变更撤销相关会话 |
| 成员停用、退出、角色/部门调整 | Identity 成员管理服务 | 成员状态、安全版本/授权版本及所属租户授权版本，按现有操作合同执行 |
| 角色权限、角色状态、数据策略 | Identity 角色与数据权限服务 | 所属租户 authorization_revision；数据策略仍由现有数据权限能力负责 |
| 套餐、许可、权限/API 目录、安装生命周期 | 套餐/许可服务、`CatalogLifecycleService`、`ModuleAuthorizationCatalogSynchronizer` | 受影响租户授权版本；全局目录和安装变更递增所有租户授权版本 |
| 租户名称、语言、时区、状态 | `PlatformTenantAdminService` | tenant revision；停用另递增 security_revision，旧会话被实时入口拒绝 |
| 域名启用/停用/更换绑定、租户状态 | `TenantEntryBindingAdminService`、`PlatformTenantAdminService::transitionTenant` | 事务成功后旋转 Host 缓存 revision；不能只清键后允许在途请求回填旧映射 |
| 自助修改成员显示名 | `AccountSelfService::updateProfile` | 显示名发生变化时，在原事务内递增租户 authorization_revision，名称缓存不依赖 TTL 或提交后清键 |

版本更新与业务写入必须同事务提交。旧权限缓存即使尚未物理清理，也不会被下一次通过实时鉴权的请求选中。禁止让业务模块直接改身份/权限表却不调用 Owner 的版本更新；禁止把 TTL 当撤权机制。新增写入口须对照本表检查其影响，不能只更新读取代码。

开发目录同步通过既有 `peanut module compile` / `peanut module sync --module=<key>` 入口完成。sync 在事务内先核对编译清单所属包与已安装活动包的 source、version、artifact、Composer/npm/frontend 身份和模块归属，再同步目录及同版本安装摘要；目录和安装摘要变化均推进租户授权版本。它不安装、升版、重新启用或替换包，包身份不同必须走原生生命周期入口。省略 module 时保持原有全量目录语义。

## 5. 业务列表的查询规则

- 复用 TP Model/Query、Paginator 和 `PageResult::fromPaginator`。需要精确总数时通常执行 count 和列表两条 SQL；不需要总数的入口采用已有简单分页合同。昂贵展示投影在稳定排序和 LIMIT 确定本页后执行，外层恢复同一排序，不重复 OFFSET；普通列表使用原生 paginate，不为所有列表另造分页引擎。
- Count 与列表使用相同业务条件。展示用关联不必加入 count；只有关联字段参与过滤/分组且改变统计集合时，才进入统计语义。无需开发通用自动 JOIN 推断器。
- 一对一展示字段使用带租户条件的 LEFT JOIN；一对多角色/别名先按对象聚合，再并入本页查询，禁止直接展开导致行数相乘、分页错乱或逐行 N+1。
- 负责人名称复用 Identity 公开目录能力，按本页唯一 ID 批量获取和缓存，不逐行加载完整身份，也不默认缓存整租户人员名册。展示字段以真实调用方为准，经营角色、别名等业务属性与用户权限角色分开。
- `AdminDirectoryQuery::memberSummaries` 为明确成员集合提供最小名称、部门和有效状态；只承担展示，不授予操作权限。本人/部门选项及停用历史人员使用 `memberOptionsPage`：调用方先确定获准部门和历史ID，目录在数据库中完成有效性、名称筛选与原生分页，不读取角色/岗位。成员有历史记录不意味着仍有效但已调出部门的人可以重新进入获准范围。
- 列表、看板、日程、事件历史和选项接口都遵守同一批量读取规则：分页回调只转换已取得的数据，不在回调中再逐条读取身份、关联记录或相同权限。先取得本次权限集合与业务范围，再批量读取当前页所需字段。选人接口使用最小成员资料，并在可见范围、部门、状态和历史口径过滤之后统计和分页，不能加载全员角色/岗位再逐人重新鉴权。
- 导入预览按实际来源复合键批量查状态，显示时复用一次来源授权策略；保留事务锁、版本比较与同批撤回/限制优先规则。批量化不绕过正式写入用例，也不把可展示的摘要当作写权限。
- 低频写入、高频展示的最近事件/下一待办等摘要，由所属业务模块按租户、对象和业务定义的成员维度维护。所有会影响摘要的创建、更新、删除、转交和关联变化在原业务事务内更新；历史回填使用原生 Migration。列表关联摘要，不重复扫描全量明细。无实际热点或无直接消费者时不提前增加冗余表。
- 个人摘要和公共摘要的口径由业务明确，查询参数只选择获准口径。两者先核记录范围；公共摘要不授予事件全文、关联对象或个人待办列表的访问权。
- 平台租户的公共时区复用 `AdminDirectoryQuery::tenantProfile`；客户所在地时区、具体日程时区由业务各自维护，不能混为一个配置。

## 6. 验证与职责

源码断言、合成数据缓存测试、真实 HTTP、数据库执行计划和完整发布资格分别记录。比较性能必须保存相同入口、分页/过滤条件、身份、返回集合、缓存状态、SQL 数量/耗时及端到端耗时。SQL 少不等于所有请求链问题已经解决。

现有 CRUD Trait 位于宿主 `server/app/common/traits/CrudTrait.php`；分页、Model/Query 和缓存原语分别来自 TP。PHP Core 提供身份上下文、Host/租户入口合同和数据权限等共享能力。业务投影归所属模块，公共身份缓存归 Identity/宿主，不为了类名或少一条 SQL 将业务逻辑搬进 Core，也不复制 Core。

耗时按 HTTP 总时间、启动与准入、身份/权限、数据库连接、SQL 往返/执行、投影/序列化分开记录。ORM SQL 计时未必包含建连。OPcache 状态从真实 HTTP worker 获取，CLI 开关不能代替；热缓存与冷启动分报，保留相同输入和返回集合。部署身份继续校验完整字节；已有 OpenSSL 扩展可计算相同 SHA-256 摘要，不以 mtime、跳过校验或关闭权限换取性能。持久连接须核对当前事务和会话锁的收尾语义，不作为默认性能修复。
