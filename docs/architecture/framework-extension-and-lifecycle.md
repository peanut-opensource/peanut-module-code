# 框架能力、模块扩展与生命周期

Peanut 使用 ThinkPHP 原生机制承载配置、容器和进程运行，Core 保留可信上下文、安全不变量及模块技术合同，应用模块负责完整业务。本文逐项说明成熟框架中适用的机制及 Peanut 的责任；完整归属表与配置/实现替换示例见 [Core 能力与应用覆盖](core-capabilities-and-application-overrides.md)。

## 1. 参考机制与采用边界

| 机制 | 成熟框架依据 | Peanut 采用与责任 |
| --- | --- | --- |
| 配置与依赖注入 | [Laravel Container](https://laravel.com/docs/13.x/container)、[Symfony Container](https://symfony.com/doc/current/service_container.html) | ThinkPHP config、bind、instance、make；应用参数配置和实现绑定分别处理，消费者注入实际依赖；不新增容器 |
| Provider 注册与启动 | [Laravel Providers](https://laravel.com/docs/13.x/providers) | 模块绑定完成后登记原生 Think Service；register 声明扩展，boot 消费已登记服务；应用先于模块 boot |
| 装饰与覆盖 | [Symfony Decoration](https://symfony.com/doc/current/service_container/decoration.html) | 应用显式绑定可替换技术默认实现；真实装饰需求使用注入被装饰服务的应用类；模块绑定冲突仍拒绝，不静默抢占 Host |
| 同步事件、订阅与监听 | [Laravel Events](https://laravel.com/docs/13.x/events)、[Symfony EventDispatcher](https://symfony.com/doc/current/components/event_dispatcher.html) | Think Event listen、subscribe、trigger；公开事件类型、payload 和版本由所属业务模块维护；框架进程事件与业务事件分开 |
| HTTP 与中间件 | [Laravel Request Lifecycle](https://laravel.com/docs/13.x/lifecycle) | Think HTTP/路由/多应用；应用负责安装/维护/Host/身份/模块/权限边界及先后次序；不把 HttpEnd 当可靠投递 |
| CLI、命令与调度 | [Laravel Console](https://laravel.com/docs/13.x/artisan) | Think Command 和 Service commands；ContextualCommand 包裹可信上下文；任务/调度业务留 Code |
| Worker、重试与消息 | [Symfony Messenger](https://symfony.com/doc/current/components/messenger.html) | 已有 Task worker、租约、重试和 dead 状态；每次任务建立上下文并校验授权，不为相似概念引入新消息运行时 |
| 异常与响应 | ThinkPHP 锁定 Exception/Handle | 应用异常响应负责领域错误、HTTP 状态及脱敏；异常处理不能吞掉事务或钩子失败 |
| 校验与密码 | [Laravel Validation](https://laravel.com/docs/13.x/validation)、[Symfony PasswordHasher](https://symfony.com/doc/current/security/passwords.html) | Think Validate；业务输入合同留模块；Core 新密码策略与密码散列分离；客户端使用有效策略 |
| ORM、Model 事件与 Scope | Think ORM 锁定 Model/query/event/Scope | 业务 Model/关联/字段归模块；Core 租户和 Scope 原语保留；AppService 的 Model maker 安装数据范围策略，不复制 ORM |
| 事务与并发 | [Laravel Database Transactions](https://laravel.com/docs/13.x/database#database-transactions) | Think Db transaction；最外层业务用例拥有事务、锁和幂等结果；重试必须考虑实际 DDL 与外部副作用，不自动重放任意业务 |
| 缓存与失效 | Think Cache 原生驱动 | 普通缓存直用框架；授权 revision、命名空间、撤销语义归现有安全合同；不再建通用 Cache façade |
| 日志、审计与追踪 | Think Log 原生通道 | 应用业务审计与技术日志分开；请求/任务可信上下文与秘密脱敏保留，框架日志不替代审计台账 |
| 文件与第三方渠道 | Think filesystem、供应商 SDK | 文件账本/引用/权限/回收归 Code；Core 仅技术 planner 与必要协议；应用配置选择可信供应商实现 |
| 模块声明与制品 | Provider/Bundle 的显式装配思想 | canonical manifest、plugins.lock、内容摘要、前端/worker/迁移 contribution 一起登记；不采用未经审阅的扫描自动发现 |
| 租户权益 | 应用业务用例与原生事务 | 套餐/许可证定义可用上限，ModuleGuard 消费资格投影；不自动授予成员权限或触发定时补偿 |
| 安装、迁移与升级 | 成熟迁移框架的台账思想 | 应用现有锁、checksum、applying/applied/failed 及恢复计划；Core 提供技术协议，不搬运完整实例升级业务 |
| 客户端插件与作用域 | Vue/Nuxt/UniApp 官方扩展机制 | Web Core 负责 transport、UI 和环境适配，Code 持有业务 SDK/状态/页面；监听与资源清理跟随实际组件/请求/任务边界 |

客户端具体参考 [Vue Plugins](https://vuejs.org/guide/reusability/plugins.html)、[effectScope/onScopeDispose](https://vuejs.org/api/reactivity-advanced.html)、[SSR 请求状态隔离](https://vuejs.org/guide/scaling-up/ssr.html#cross-request-state-pollution)、[Nuxt Plugins](https://nuxt.com/docs/guide/directory-structure/plugins)、[UniApp 页面生命周期](https://uniapp.dcloud.net.cn/tutorial/page.html)及 [request/abort](https://uniapp.dcloud.net.cn/api/request/request.html)。现有 Vue async action/list 已有 generation 与 scope dispose；ClientRequest 的可选 signal 贯通 Nuxt fetch 和 UniApp RequestTask.abort，取消按 AbortError 返回并清理监听。PC composable 的 cancel、scope dispose 取消其自有在途请求；不会全局中止其他页面/任务的请求。

Vue tenant disposer 的模块级 Map 当前真实调用位于浏览器 Web；当前 PC/SSR 无该调用，不能据此承诺其可用于 SSR 请求状态。SSR 的 Client/runtime 仍按请求或 app 所有权创建，可信 Host/cookie 转发合同保留，不为复用放开跨请求共享身份。

### 1.1 客户端请求入口与注入

各端保留自己的协议、身份和错误处理入口。模块生成的 `createModuleClient(options?: ClientOptions)` 由 `scripts/generate-api-contracts.php` 生成，接收 openapi-fetch 的 `baseUrl`、`fetch` 等原生选项；不修改生成文件来接入宿主身份。Web 租户模块可按现有 Settings 宿主的方式显式选择：

```ts
import { createModuleClient } from '@/modules/peanut-settings/generated/openapi';
import { tenantSessionFetch } from '@/api/tenant-session-fetch';

const api = createModuleClient({
  baseUrl: new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString(),
  fetch: tenantSessionFetch,
});
```

这个示例用于浏览器租户管理端。`tenantSessionFetch` 透传 `Request.signal`，按会话 generation/token 拒绝迟到响应，仅对 `/adminapi/api/v1/` 的 HTTP 401、业务码 40100 尝试刷新并重派发一次；无法克隆的请求体只发送一次。普通 fetch 不会自动获得这些行为。需要替换后端或采集请求信息时，由宿主通过上述选项注入，保留取消、错误和身份语义，不再安装一套全局缓存或拦截器。

| 客户端 | 正式请求入口 | 身份、错误与取消责任 |
| --- | --- | --- |
| Web | [共享 Axios 拦截器](../../web/src/api/interceptor.ts)、[租户 fetch](../../web/src/api/tenant-session-fetch.ts) | Axios 和显式注入的 fetch 分别处理原有响应协议；两者保留会话快照与既有 401 刷新重派发；调用方传入 signal 才能取消其网络请求 |
| Platform | [platform API client](../../platform/src/api/platform.ts) | 独立 Axios 实例、平台会话 generation/token、刷新去重和单次重派发；平台身份不使用租户 fetch |
| PC | [useRequest](../../pc/composables/useRequest.ts) | 每次 composable 创建 Client 与 Nuxt transport；SSR 使用可信 Host 转发，当前不转发 cookie；浏览器读取自身用户态；`cancel()` 和 scope dispose 仅中止该实例自有请求 |
| UniApp | [request/http](../../uniapp/src/utils/request.ts) | 使用 UniApp transport，适配器返回原生 `uni.request` 的 RequestTask，公开 http 方法返回 Promise；可选 signal 触发 abort，结束后移除 abort 监听；用户 store 的 generation/token 约束过期会话清理 |

File 的 [visibilityRequest](../../web/src/modules/peanut-file/api.ts) 继续调用共享 `axios.request`，只通过 `transformResponse` 保留最终 4xx 拒绝体中的 `error_code`。HTTP 401 不记录为最终可见性拒绝，仍交给共享刷新与重派发；网络错误和取消原样传播。自定义文件呈现不需要另建 Axios 实例来绕过这个合同。

### 1.2 开始、完成、错误、取消与退出

请求状态由拥有该操作的组件或 runtime 管理，transport 不创建跨页面的 loading/error 单例。操作开始时设置 loading 并清除本次错误，成功时只在 generation/revision 仍有效时更新数据，失败时保留实际错误，finally 结束本次 loading 并释放监听。取消不得写成成功或空数据；主动 abort 按对应 transport 的取消错误处理，迟到结果仍须受当前会话与操作版本约束。

- Web [useRequest](../../web/src/hooks/request.ts) 已有 loading/response/error/reload/cancel 与 scope dispose；它的 `cancel()` 通过 revision 丢弃迟到结果并结束 loading，参数 `api: () => Promise` 不接收 signal，因此不代表中止网络。需要网络取消的调用方拥有 AbortController 并传给实际 API。
- Web 组件/runtime 在卸载时调用自身 `dispose()`、停止订阅并清除自身状态。租户资源可用 `registerTenantDisposer(key, cleanup)` 登记清理；组件退出还须调用返回的 unregister，避免保留失去所有者的监听。`disposeTenantState()` 执行已登记回调并传播失败，不会替所有模块自动发现请求。登录、退出和切换租户的宿主已调用它，并用会话 generation 阻止旧响应回填；模块仍负责自己的请求、监听和临时数据。
- PC `useRequest` 将外部 signal 连接到每次请求的 controller，在 finally 中移除连接并从 pending 集合删除。`cancel()` 可由组件主动调用；Vue scope dispose 自动调用它。`clearSession()` 清除用户 cookie 状态，不是所有 composable 的全局取消器；SSR 状态不能注册到浏览器的全局 tenant disposer。
- UniApp 页面按原生 `onLoad/onUnload` 或其 Vue scope 创建和释放自有请求、订阅及状态。需要网络取消时，把页面所有的 controller.signal 传给 `http.get/post`，在退出时 abort；只递增 revision 的页面仅丢弃迟到结果。`logout/clearIfToken` 清除用户态，不能代替页面释放。`uploadImage` 是独立的 `uni.uploadFile` 入口，当前不暴露 signal，不把普通请求的取消合同延伸到上传。

跨端 contribution 只负责声明并装配当前端能力，不共享页面实例或请求状态。PC 的 [Nuxt 配置](../../pc/nuxt.config.ts) 通过 `moduleFrontendEntries(..., 'pc-web', ...)` 装配模块，UniApp 的 [页面生成器](../../uniapp/scripts/module-pages.mjs) 使用 `uniapp` contribution 生成页面/slot 与 `virtual:peanut-module-contributions`；二者共用的 [入口解析器](../../scripts/module-frontend-entries.mjs) 在开发时读取模块声明，发行构建时读取固定 `plugins.lock`。新增贡献沿各自生成链接入，不能复制 Web 页面、tenant disposer 或桌面 transport 来替代原生生命周期。

ThinkPHP 实际 API 以 `server/composer.lock` 锁定的 framework 8.1.4、container 3.0.2、ORM 4.0.51 为准。上述 Laravel/Symfony 资料用于职责和行为比较，不是 Peanut 的依赖，也不证明 Peanut 已完成对应运行验证。

## 2. 模块启动与原生扩展

模块现有 `backend.provider` 必须实现 Core `ModuleProvider`，声明 moduleKey 和 bindings。需要启动、路由、事件或命令扩展的模块，可以让同一个 Provider 继承 `think\Service`：

```php
final class ModuleProvider extends \think\Service
    implements \PeanutAdmin\Kernel\Module\ModuleProvider
{
    public function moduleKey(): string { return 'example.orders'; }
    public function bindings(): array { return [OrderQueries::class => OrderQueryService::class]; }

    public function register(): void
    {
        // 声明事件、路由或命令；不在这里解析其他模块尚未登记的服务。
        $this->commands([ReconcileOrdersCommand::class]);
    }

    public function boot(OrderQueries $queries): void
    {
        // 所有绑定可解析；这里只安装进程扩展，不创建业务租户上下文。
        $this->app->event->listen(OrderChanged::class, OrderChangedListener::class);
    }
}
```

实际启动顺序：

1. ThinkPHP 载入 Host provider/config 与原生服务。
2. 源码开发形态由 AppService 根据固定 lock 编译模块，校验 Provider 身份、绑定/alias 冲突和 Task contribution；Server 普通 HTTP 恢复维护生命周期已经验证、编译的声明。所有模块绑定进入同一个容器，同一 Provider 对象保留在该容器。
3. 位于 AppService 之后的 ModuleExtensionService 将原生模块 Service 交给 ThinkPHP register；普通绑定型 Provider 不被要求增加空 register/boot。
4. ThinkPHP boot 原生服务，AppService 先安装 Model 数据范围策略，再运行模块 Service boot；业务对象仍须通过可信执行边界使用。

不强制重注册、不直接手工调用模块 boot、不再增加第二生命周期管理器。原生 register/boot 异常阻止启动，不能吞异常后继续提供缺少扩展的服务。开发期插件制品修复命令有既有受控装配跳过路径，不因此执行尚未重新封存的 Provider。

模块的业务事件处理器、命令与任务仍须按所属模块公开面、运行许可、可信身份和 Scope 执行；原生 Event 不是授权边界。同步监听器可能中断当前操作；可靠通知、外部调用或异步业务必须由现有持久任务/状态台账负责，不能在 boot 或 HttpEnd 即席发送并假定可恢复。

## 3. 套餐与许可证的业务边界

套餐定义共享的模块与权限组合清单，每个租户的当前许可证引用一个套餐并声明生效时间、到期时间和状态。模块安装、租户权益与成员角色授权分别判断；开通或升级许可证不授予成员角色，套餐到期也不删除已有角色关系。

租户业务权益由 Identity 提供，Core 的 ModuleRuntimeRepository / ModuleGuard 继续消费当前资格投影。没有逐模块启停钩子或另一套开通 Manager；业务设置使用所属模块的 setting_definitions 和 Settings 现有校验、作用域与事务能力。到期在实际授权时判断，不依赖定时回调改变开关；租户任务仍使用现有执行边界，已提交的撤权事实继续传播。

## 4. 请求、命令、任务与包生命周期

| 边界 | 已有负责人 | 失败和清理责任 |
| --- | --- | --- |
| HTTP 身份/租户 | 各 audience 登录/PublicTenantModule middleware、ModuleExecutionBoundary | ExecutionContextStore.run 在 finally 恢复栈；受保护路径校验模块与权限，不由全局事件隐式建立身份 |
| CLI | ContextualCommand / ModuleContextualCommand | 顶层 Instance 上下文包裹 handle；嵌套调用保留既有合法上下文，不增加全局 reset 破坏父层 |
| 每个 Task job | ModuleAwareTaskHandler、ThinkPhpTaskJobRuntime、LocalWorker | 每次 job 授权、执行、上下文 finally 与已有租约/重试/dead 状态；队列驱动异常不等于业务成功 |
| Package 安装/升级 | PluginLifecycleService | 锁与固定身份 → installing/upgrading → migration → catalog → active；异常标 failed 并传播，已提交 DDL 的恢复依台账，不承诺整体自动回滚 |
| Package 卸载 | PluginLifecycleService / PluginRuntimeGovernanceService | 检查受保护模块、依赖、租户使用和引用；计划确认及既有 purge/隔离合同保持 |
| 实例升级 | 应用独立 plan/backup/apply/migration/health/verify 入口 | 来源、保留与恢复材料固定；激活前后恢复边界保持，不用模块 boot/hook 替代升级引擎 |

当前上下文栈证明的是现有串行/FPM 和显式嵌套调用合同；未验证的协程/并行 worker 不能沿用进程单例隔离结论。新增并发运行方式须先明确 task-local 生命周期与真实验证，不把全局可变对象共享给多个租户。

Server 发行的完整文件、Plugin canonical内容和模块声明校验由部署 owner 在原生 `initialize-traffic`、更新及恢复后的闭流阶段负责，完成之后才能授予流量。`VerifiedServerDeployment` 是该生命周期的薄 Host 适配器：使用 ThinkPHP 原生 File driver 保存纯 JSON 声明，以不可变内容键写入、同步文件并原子发布指针。程序及依赖必须对 Web 用户不可写；索引目录由部署 owner 控制，普通应用缓存不承担来源信任。

声明绑定发行身份、投影锁、Composer依赖、模块配置和安装/部署状态，模块根使用Server相对路径。普通HTTP通过 `app/provider.php` 读取受保护结果、核对绑定并恢复模块autoload，再交给原生容器和Service注册；不执行完整文件树扫描或模块编译。索引缺失、损坏、绑定变化或仍在更新时拒绝，不由Web进程懒惰重建。任何程序、模块或发行变更必须先闭流、停止旧FPM，再完整校验、重建声明并重启授流；失败撤销流量与运行证明。

原生 Docker 维护进程在 chroot 外使用同一程序目录的可写视图，FPM 在 `/server` 使用只读视图；仅已有缓存、日志、上传及安装数据目录挂载可写。隔离容器的 owner 使用 `SYS_PTRACE` 读取非 dumpable worker 的真实 `/proc` root、cwd 和描述符，不启用 privileged 或 host PID namespace。FPM 保持 `process.dumpable=no`；授流前验证 worker 的 UID/GID、effective/permitted/inheritable/ambient capability 全零、`NoNewPrivs=1`、chroot 与无维护视图描述符，再根据真实 mount 及目录保护发布原生 Linux 文件系统上的运行证明，绑定当前声明。FPM 不能访问维护视图、Docker 配置凭据与恢复密钥。非 Docker 入口使用真实请求 UID/GID/组、不可提权能力和部署 owner 的文件权限，不根据 `www-data` 账户名推断安全性；无法证明具体权限或能力时拒绝。外部 Host 未执行运行资格不能记为通过。

网页安装配置写入 `private/resources/pending` 的纯数据。安装预检只检查实际可写子目录，与只读 HTTP 挂载的 11 个 mutable 路径一致；`runtime`、`private/resources` 父目录保持只读，应用创建器和 CLI 准备入口创建相同的子目录。现有 owner 监控入口闭流并停止 bootstrap FPM，验证配置与实际资源后原子发布唯一 `server/.env`，完整校验、编译并进入 installing；安装完成再停止旧 FPM，重建绑定后进入 installed。更新/恢复同样复用原生维护入口。恢复密钥唯一位置为 owner 控制的 `private/maintenance`；发现旧位置的密钥时明确要求原 owner 先闭合原固定工具事务，不自动迁移或提供双源读取。

源码开发形态不冒充不可变发行，继续按实际源码装配。CLI、其他目标目录以及显式安装落盘、升级和模块治理的 `load()` 保留独立完整校验。安装宿主直接注入当前App已有的 `CompiledModuleRegistry`；安装完成锁、迁移状态、数据库健康及失败阻断检查保持执行。常驻HTTP运行方式仍须明确每次运行的App及执行上下文生命周期，不能把声明索引当跨请求业务状态容器。

部署模块资格枚举通过 Identity 模块公开查询批量读取当前安装身份，保留锁定读及状态、版本、schema、摘要校验；插件归属同样批量投影。单模块操作仍立即读取当前身份，授权与租户启停边界独立执行，不以跨请求业务缓存代替校验。Web 新登录先清理旧用户、菜单与租户资源，提交新身份时再清理登录期间可能回填的旧状态；generation/token 检查继续阻止迟到响应覆盖当前会话。

## 5. 扩展准入与验证

新增扩展先判断是否已有框架入口、真实消费者及明确 owner，再选择原生机制、薄 Host 适配或模块业务合同。事件 schema、Job payload、幂等键、失败责任及版本演进属于所属模块公开合同；没有具体消费者时，不新增空事件格式注册表、万能 HookRegistry 或通用 Scheduler。

职责整理不等于全量运行验证。发布必须另外固定源码、公共 Core 版本、原生锁、canonical 制品与应用包，并通过对应发布 Gate；开发提交、文档和类型检查不能替代实际安装、升级、恢复或生产资格。
