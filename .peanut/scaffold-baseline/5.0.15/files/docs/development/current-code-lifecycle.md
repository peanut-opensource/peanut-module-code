# 当前代码导读

路径相对本应用源码根；本文解释实际接线，不是第二套规范或完整产品验收。先读[开发规范](standard.md)和[应用约定](application-conventions.md)。调用顺序以本次原生锁及实际加载源码为准；执行者分别记录源码、依赖、命令与验证范围，不能从本文推断某项运行验证已通过。

## 1. 产品与代码

这是模块化管理应用基础，不是预先完成的全部行业系统。官方模块及workflow、artifact-revision、entitlement-quota等可选模块以实际module.json和插件锁为准；fixture.delivery-record是测试模块，不算产品。已有身份组织、会话、审计、设置字典文件、文章分类、富文本、会员/OAuth、任务通知CSV、机器/Webhook、充值回调退款及运营诊断等源码；存在源码不代表外部渠道和业务已经全部验收。

PHP技术Core和Web公共包不含完整业务。模块类型使用各模块composer.json声明的 `PeanutAdmin\Modules\...` 前缀及src目录，Core技术前缀保留。platform是运营端、web是租户后台、pc是网站、uniapp是移动客户端；职责与默认组合见[默认交付](../architecture/default-delivery-and-ownership.md)。H5、微信构建和终端实际运行需要分别验证。

## 2. 当前Controller

[BaseController](../../server/app/BaseController.php)接收App，从该App取得Request，构造时检查xxClass声明；`$this->xx`按受信源码声明解析。接口必须有正式绑定，返回类型、继承、保留名、真实属性冲突和循环均检查。`$this->context`从当前App读取已登记reader；未知属性、赋值和unset拒绝，不造默认身份。

```php
// ArticleController 的接入片段，省略 use、校验及其他声明。
/** @property-read ArticleAdministration $crud */
protected string $crudClass = ArticleAdministration::class;
// CrudTrait 通过 $this->crud 执行明确的管理合同。
```

Model在一次Controller操作内复用，新记录显式resetControllerModel；Validator和Query每次取得干净对象。业务服务仍构造注入；直接PHP调用不自动注入，测试显式传服务或建立独立App绑定。CrudTrait统一允许输入、可写字段、分页结果与显式软删动作，不能开放未授权通用写入。详见[Controller规范](controller-access-and-namespaces.md)。

## 3. HTTP实际顺序

[HTTP入口](../../server/public/index.php)加载Composer后执行Http::run、Response::send和Http::end；[CLI入口](../../server/think)进入原生console。两者均由 ThinkPHP `App::initialize()` 原生加载环境和配置。当前框架的服务初始化应区分应用service.php和框架初始化器：

```text
server/public/index.php
→ server/vendor/autoload.php
→ new App / Http::run
→ App::initialize → loadEnv → load配置、公共文件、事件和app/service.php
→ app/service.php登记AppService → AppService::register及模块绑定
→ ModuleExtensionService::register → ThinkPHP登记模块原生Service
→ AppInit事件
→ 框架RegisterService初始化器 → BootService → AppService::boot → 模块Service::boot
→ HttpRun、全局中间件及MultiApp → 应用中间件（安装、维护限制）
→ 显式路由 → 路由中间件（身份、模块、权限）
→ Controller构造/initialize → Controller中间件 → action参数解析及业务
→ 中间件逆序返回、日志及finally恢复 → Response::send → Http::end
```

ThinkPHP 8.1.4 的 `Env` 读取 `server/.env`；原生 `PHP_ENV_NAME=<name>` 选择 `server/.env.<name>`。`config/*.php` 使用 `env()` 和固定默认值，业务代码读取配置或 `env()`；文件不再镜像到 `getenv()`、`$_ENV` 或 `$_SERVER`。原生 `Env` 支持 INI section，并在已加载值不存在时读取 `PHP_<KEY>` 进程变量；文件值覆盖同名进程输入，具体转换以锁定框架实现为准。独立数据库维护命令先调用原生 `App::loadEnv()`，不提前初始化应用服务。

这里的 `AppService::register` 发生在加载本应用service.php时，早于AppInit；不能因为框架的RegisterService初始化器在AppInit之后，就把应用服务注册也写成之后。[AppService](../../server/app/AppService.php)登记现有身份reader、基础设施和模块接口；boot通过Model::maker为相应模型接入Scope策略。

### Owner 限定的 HTTP SQL 计时

默认关闭。部署 owner 可在已获准的传统 FPM pool 本地进程环境中配置 `env[PEANUT_HTTP_SQL_TIMING_REQUEST_ID]` 为一个符合 RequestTrace 格式的精确请求 ID；保留 `clear_env`，由 pool 显式传递这一个变量。只有真正的 `fpm-fcgi` 请求且 `X-Request-Id` 与该值匹配才会选择采样。选择器只读 `getenv(..., true)` 的 OS/putenv 本地值，不接受普通 SAPI/FastCGI 参数或仅靠 HTTP header 开启；此变量不从 `server/.env` 读取，不将请求值复制进本地环境。CLI、长驻 worker 和其他请求不安装新 listener、不输出采样日志。启用仍须运行资源 owner、FPM 配置和变更准入；源码具备该开关不表示保留的 APP 可立即启用，本次不修改或重载任何运行配置、环境、镜像或 deployment binding。不得改 APP_DEBUG 来取得样本。

选择请求在 AppService 注册最开始确认没有既有 SQL listener 或已创建的连接后，先登记原生 Db listener，再仅为当前默认连接启用 trigger_sql。冲突时放弃采样，不清空 listener、不改变原配置；未选中时原 APP_DEBUG 的 SQL 日志行为不变。选中请求的 listener 替代原生缺省 raw SQL 日志 fallback，只累计非 CONNECT 的成功原生回调数与秒数，不保留、哈希或输出 SQL、绑定参数、DSN、身份或凭据。最终 Request 在 Http::run 初始化之后才创建，所以计数暂存为本次 FPM 请求的标量；HttpEnd 核验最终 header、owner 本地值和现有 RequestTrace ID 后，仅输出 `http_sql_timing` 的 request_id、count、sql_seconds、coverage_start、coverage_elapsed_seconds。记录发生在原生日志 save 之前，无响应字段或新指标 header。

覆盖从 `AppService.register.before_composition` 到 HttpEnd；HttpEnd 后的 middleware end SQL、此前的初始化查询、直接 PDO 或没有成功原生计时回调的语句、其他未启用 trigger_sql 的连接不在此计数保证内。原生秒数从 PDO prepare 前到 bind/execute 后，不包括连接建立、result fetch/hydration；CONNECT 被排除。SQL 字符串的原生构造和回调、汇总本身仍有成本，样本是 instrumented 观测，不是零开销基线，不宣称已测响应耗时或节省比例。同一计时行用 hrtime 记录覆盖区间总秒数 coverage_elapsed_seconds，以比较 SQL 回调合计与其余覆盖区间成本；它不是端到端 HTTP 耗时，差值不能直接归到某个函数。使用普通 FPM 的请求内生命周期，不据此宣称常驻/协程采样安全。

模块可以在同一个 Provider 上继承原生 `think\Service`，通过原生 register/boot 接入事件、路由及命令；普通绑定型 Provider 无需空生命周期方法。详细扩展时点、租户启停钩子和失败责任见[框架扩展与生命周期](../architecture/framework-extension-and-lifecycle.md)。

Controller的initialize由构造函数直接无参调用，不自动注入；Service::boot由框架服务启动机制调用，两者不同。路由认证早于Controller构造，不能移到initialize之后的Controller中间件。Callback与Container::make的构造/后置回调也不是同一入口；扩展前应核实际invokeClass、make和resolving调用路径。HttpEnd不代替可靠任务。

[ExecutionContextStore](../../server/app/common/execution/ExecutionContextStore.php)是进程内栈；run在finally恢复并检查上下文一致性。reader可在装配阶段建立，具体身份在请求或任务边界内进入。普通FPM和串行任务的恢复检查不能扩大为Swoole协程或全部常驻并发安全。

## 4. 入口中间件

| 入口 | 链路及用途 |
| --- | --- |
| 租户后台 | LoginMiddleware → OfficialModuleMiddleware（模块路由）→ AuthMiddleware → OperationLogMiddleware；会话/Host、安装开通、动作权限与留痕 |
| 会员 | CheckTokenMiddleware及模块检查；核JWT、服务端会话、会员和租户，建立ConsumerExecutionContext |
| 匿名网站 | PublicTenantModuleMiddleware；可信Host或已登记默认租户及公开动作，匿名不取消隔离 |
| 平台 | 平台Host、登录与权限；不冒充租户员工 |
| 外部回调 | 原始HTTP → 回调用例 → 绑定及验签 → 模块 → 业务；第三方tenant_id不是信任来源 |

后台位置为 `server/app/adminapi/http/middleware/`，会员为 `server/app/api/middleware/`，公共模块边界为 `server/app/common/infrastructure/module/`。应用middleware.php处理安装和维护，路由按需要认证；根app/middleware.php为空不表示没有MultiApp。安装、开通、动作和数据范围不能合并成is_admin；HTTP保护入口，服务仍须保护CLI和worker调用。

## 5. 文章编辑例子

`POST /adminapi/peanut.article.edit` 登记在[文章模块路由](../../server/app/modules/peanut/article/route/app.php)。该路由实际依次登记Login、OfficialModule、Auth和OperationLog中间件。

员工会话、Host和状态检查 → 模块检查 → 文章编辑权限 → ArticleController/CrudTrait::edit → ArticleValidate及允许/可写字段 → ArticleAdministration合同 → 服务核上下文和动作权限 → 事务内锁定分类、文章并核文件引用 → tenantOwnership Scope及模型写入检查 → 保存和响应 → 留痕及上下文恢复。

合同由模块Provider::bindings接入。文章和分类复用标准CRUD签名；软删除、回收查看、恢复与永久清理分别授权，文件引用不随软删物理清理。非HTTP调用同样必须建立合法执行范围，不能把任意CLI调用视为已授权。普通保存保持同步，不自动通知全部监听者。

## 6. 钩子与特性

| 机制 | 实际作用及边界 |
| --- | --- |
| AppService register/boot | 登记依赖；Model::maker给对应模型接策略，不是万能属性注入 |
| BaseController initialize | 对象构造期间的无参初始化，不是构造前或自动注入点 |
| handle(Request,Closure $next) | 前置检查、后置响应及finally状态恢复 |
| TenantOwnedModel::scopeTenantOwnership | 经该模型查询增加范围，裸Db::table不会自动继承 |
| onBeforeInsert/onBeforeUpdate | 模型写归属检查，不能假定原始批量SQL触发它们 |
| CrudTrait beforeAdd/Edit/Delete | 同步模板钩子，默认空，不是异步事件 |
| ModuleProvider::bindings | 模块接口进入同一容器，不自动授予业务权限 |
| readonly/属性提升 | 固定属性及减少样板，不是深拷贝或授权 |
| SensitiveParameter | 减少堆栈泄露，不是存储加密或所有日志脱敏 |
| finally、事务及行锁 | 恢复本地状态或保证覆盖的数据库一致性，不撤销外部付款或保证恰好一次 |

app/event.php保留AppInit、HttpRun、HttpEnd、LogLevel和LogWrite项，但默认listener内容、bind及subscribe为空。专用通知、工作流和任务记录不等于一个统一Outbox总线。可靠协作遵守[事件任务专题](../architecture/events-and-tasks.md)。

## 7. Worker实际执行

模块Provider登记TaskWorkerContributor → 注册表组合通知、导出等处理者 → 领取任务与租约 → 验证可信信封和授权 → 进入任务及业务模块范围 → 各checkpoint核租约、权限和模块 → 最终fence → 保存结果并finally清理。

任务不携带长期Token或上一次执行身份。撤权与失租分别处理，不无限重试无权任务。具体handler必须在正确批次及外部动作前调用checkpoint，框架不能截获任意代码的全部副作用。事务和租约不能代替外部接口的幂等及结果核对。

## 8. 读代码的方法

先选择一个业务动作：入口 → AppService → 模块路由 → 中间件 → Controller/CrudTrait → 应用用例 → 公开合同和所属模块数据访问 → 测试。构造和方法注入、员工和会员安全域、模块和人员授权、manifest和原生lock各有职责，不按重复词删除机制。

跨模块配置转移可从ImportExport的三个适配器追到Settings或Integration公开合同，以及Identity已公开的模块配置服务；详细的租户状态、条件写入、秘密引用和审计边界见[PHP规范](php-thinkphp-guidelines.md)。不要绕过这些边界直接访问别人的私表。

需要核验框架时，查看实际已安装依赖中的App::initialize/load/register/bootService、Http::run/end、Route dispatch、Callback exec、Dispatch中间件管线及Container的invokeClass/invokeReflectMethod/bindParams/make/invokeAfter。仅阅读源码不等于已经执行相关场景。

## 9. 应用生成、安装与升级

本节解释已有源码能力。APP在自己的Git开发、定版，生产采用固定main提交；[产品Git交付](../public/application-release.md)说明目标流程、保护与尚未接通的部分。安装、迁移及源码三方升级各用正式入口；维护资料位于源码仓 docs/maintenance，历史回执按原版本解释。

开发联调明确选择本地 PHP/Web Core：PHP 使用真实 Composer path link，普通 PHP 源码修改直接由开发消费者读取，不以逐次提交、固定包、发布或重新封装 development-runtime 为前提；依赖声明、路径或配置合同变化才刷新相应原生依赖/服务登记。Web Core 保留 dist 导出，维护者在所选 Core 工作区单独运行原生 `pnpm run watch`，只启动一个编译写者；客户端启动不再托管 Core 编译器。开发链接与候选、正式 APP 的固定依赖分别使用，不把维护者目录或活链接放进交付 APP。

维护者首次在无消费者、所选 Core 无编译写者的窗口执行 `scripts/core-dev select --php-core-dir /absolute/php-core --web-core-dir /absolute/web-core --backend-env /absolute/server/.env`，再执行 `scripts/core-dev install --client web,platform`。`--client` 接受逗号分隔的 `web`、`platform`、`pc`、`uniapp`，省略时为 `all`；`install/link/status/link-status` 只处理所选 Web 客户端，PHP 仍使用所选本地 Core。多个客户端共用一次 Web Core 依赖安装和构建，客户端自身依赖须事先按各端原生锁准备。选择保存在当前工程忽略的 `.local/core-dev.json`，不含凭据；显式源码路径优先，其次复用已保存的源码选择。首次 `select` 缺少源码路径时，可复用 `peanut config` 已明确保存在 `.local/toolchain/host.json` 的 `core.php/core.web` 绝对路径，不推断相邻仓或私有 Project。host 的 Core 路径服务工具配置和本地发行输入，`core-dev.json` 服务源码联调；二者不互相回写，后续 config 不会改变已有源码选择。`select` 只保存选择，`install` 才通过原生 Composer path 和现有 Web 链接入口激活依赖，初次生成 Web dist；两步均不连接或迁移数据库。`scripts/core-dev status --client web,platform` 核实际链接。切换 Core 或刷新依赖前重新核运行消费者和 Core 写者，不能替换在途候选现场。

Core 安装和构建复用当前 Code 的 `project-env` 独立 PHP/Web scope，工具来自 `peanut config` 已准备的 `.local/toolchain/cores/php|web/host.json`。PHP 入口的环境文件检查、manifest 解析、Composer、服务登记与登记验证统一使用该 PHP scope，避免前后阶段使用不同解释器。入口不再以 Corepack 选择或下载 pnpm，也不固定调用应用的 Composer wrapper；缺少 scope、工具或 prepared 状态时明确失败，先显式配置准备。所选 Core manifest 的要求仍由 `project-env` 核对。源码选择可独立于发行 Core 路径，但更换来源或工具要求后需重新核对对应 scope 的配置与准备状态。

从 Code 根使用已准备的 Web Core 工具启动现有 watch（只启动一个）：

```sh
code_root="$PWD"
web_core="$(scripts/core-dev web-path)"
cd "$web_core"
python3 "$code_root/scripts/project-env" --root "$web_core" --state-root "$code_root" \
  --host "$code_root/.local/toolchain/cores/web/host.json" exec -- pnpm run watch
```

选择生效后，PHP 普通源码无需重新安装。`local-core-composer` 和 Core 选择配置共用本工程 `.local/core-links/php` 临界区，依赖/配置写入在持有记录时核原生 PID 与 canonical router 进程，运行中明确拒绝切换。OPcache、常驻 worker 或已加载类仍须由其 owner 按原运行合同刷新/重启，脚本不替别人重启服务。在已选 Web Core 根单独运行 `pnpm run watch`；它先构建，再持续编译各包，通过 Core 已有的 `scripts/local-core-writer.mjs` 保护输出写集。多客户端共享这一个编译写者，各自用原生开发入口读取已链接的 dist，不为每个客户端再获取同一 Core writer。Core 根与包级原生 build/watch 共用原记录，嵌套命令只复用活祖先进程凭据。安装、切换链接或刷新依赖前，先由实际 owner 关闭相关客户端和 Core watch；现有安装/选择入口仍保护 `.local/core-links/<client>/owner.json` 与 Core writer，不能在原生客户端运行期间手工换链接。这些入口保护正常工具调用，不承诺拦截任意手工链接写入、裸包管理器操作或绕入口启动的进程。异常强杀留下记录时先核 PID、实际进程和 Core 路径，由原 owner 确认空闲后清理该记录，不把别人的编译器停掉。

各客户端配置独立：复制各自 `.env.example` 为本目录 `.env`，保留其中实际有用的字段。Web 和 Platform 各用根 `vite.config.ts`；`npm run dev` 直接启动 Vite，Web/Platform/UniApp 配置用原生 `loadEnv` 加载当前 mode，Nuxt 使用自己的 dotenv（默认 `.env`，可通过原生 `--dotenv` 显式选择）。环境变量遵循框架优先级，不再读取共享 `.local/host-client.env`、额外 selector 或拒绝普通父环境。UniApp 的 H5、小程序、custom、SSR 等 `dev:*` 直接运行原生 `uni`，保留原参数。启动不会安装依赖、改锁、构建或启动 Core watch。

| 客户端 | 原生开发入口 | 本目录配置 |
| --- | --- | --- |
| Web | `npm run dev` | `VITE_HOST`、`VITE_PORT`、`VITE_ALLOWED_HOSTS`、`VITE_OPEN_BROWSER`、`VITE_API_PROXY_TARGET`、`VITE_API_BASE_URL`、`VITE_DEPLOYMENT_MODE`、`REPORT` |
| Platform | `npm run dev` | `VITE_HOST`、`VITE_PORT`、`VITE_ALLOWED_HOSTS`、`VITE_OPEN_BROWSER`、`VITE_API_PROXY_TARGET`、`VITE_API_BASE_URL` |
| PC | `npm run dev` | `NUXT_HOST`、`NUXT_PORT`、`NUXT_DEV_PROXY_ORIGIN`、`NUXT_DEV_PROXY_TARGET`、`NUXT_PC_RENDER_MODE` 及 SSR/公开 runtime 配置，详见本目录示例 |
| UniApp | `npm run dev:h5` 或所需 `dev:*` | `VITE_HOST`、`VITE_PORT`、`VITE_API_PROXY_TARGET`、`VITE_APP_BASE_URL` |

端口由各客户端配置；维护者填写自己登记的实际端口。Docker 对外端口仍由 Docker 编排配置管理，不替换宿主客户端配置。文档站使用自己的原生 VitePress 命令及启动参数；`DOCS_PORT` 是编排监听选项。`PHP_PORT`、网关/HTTP/Redis 端口属于其服务，不进入浏览器配置。开发 API 通过同源路径代理到明确上游，代理保留原始 Host；不设置固定租户 Host，不在浏览器 JavaScript 中设置 Host。生产也使用同域名下的 API/资源路径，浏览器 API 前缀留空；UniApp 小程序/App 的绝对后端地址按实际终端另行配置。`VITE_` 和公开 runtime 字段会交付客户端，不能存放秘密。

`.local` 按真实消费者保留：`toolchain/` 是本机工具绑定、缓存与 shim，`core-dev.json` 是已选择的 Core 源码，`core-links/` 是正在使用的链接写保护，均不属于普通清理目标。开发配置使用 `server/.env` 和各端自己的 `.env`；旧 `local-stack`、`local-php-runtime`、`local-multi-tenant-demo` 与其环境探针已退出开发入口，不再生成共享 `stack.env`、同步数据库配置、隐式安装依赖或代启全部服务。可选 Docker 开发编排与网关文件保留，由使用者明确选择 Compose 文件和私有 `--env-file`；`deploy/local-stack.env.example` 只提供开发编排覆盖值，`CHOKIDAR_USEPOLLING` 默认启用，生产 HTTP 端口在 `server/docker/.env` 中配置；生产编排按 `server/docker/README.md`。

清理本地残留时逐对象核 owner、活跃进程、直接引用和恢复用途。已终态且能重建的临时输出或字节相同的重复副本可删除；有效配置先保全并核完整摘要。安装身份、凭据、数据库/上传备份、FAIL/UNKNOWN 现场、冻结候选、未知 owner 和活跃 PID/shim 保留，不清空整个 `.local`，不把旧证据批量改名为永久备份。旧自动化对已退役入口的引用按当前开发期测试政策保留，另行授权测试时再按最终设计处理。

日常可变源码联调不构成候选资格：阶段候选通过冻结合同生成 PHP ZIP、Web 六个 TGZ，并由同格式模板 TGZ 创建 APP，按候选原生 Composer/npm 锁安装到 APP 自身 `vendor`/`node_modules`，必须拒绝维护者源码链接；正式交付再核适用公开包的最终内容、来源及原生正式锁。

首次安装继续使用网页安装向导，控制台入口是 `php server/database/install.php`。首次空库检查、动态管理员/租户、模块选择、租户模块开通、健康检查和完成锁均由安装业务负责。结构通过官方 `migrate:run` 执行唯一 `server/database/migrations` 目录；动态身份创建后，官方 `seed:run --seed=InitialSeeder` 写默认数据并同步权限目录。种子从实际唯一默认租户读取身份，不依赖另一个 PDO 的会话变量；重复或部分初始化拒绝重放。安装完成身份位于 `server/private/installation/installed.json`，包含原生迁移版本与当前源码摘要，不能据此声称官方账本提供历史 checksum 验证。

既有 `server/database/seed-multi-tenant-demo.php` 仍保留专项演示数据能力；它与首次安装、脚手架开发库初始化是不同操作。需要该能力时，先按其既有 target/resource 准入及恢复合同核对明确目标，在对应后端环境配置 `PEANUT_DEMO_MODE`、演示租户邮件/密码/域名等所需值，再显式选择 `PHP_ENV_NAME` 并执行 `php server/database/seed-multi-tenant-demo.php --apply --default-host=<明确的default租户域名>`。它不负责选择工具、安装依赖、生成秘密或启动服务；不得向共享开发库重放旧演示配方，也不能把退出的聚合脚本当作当前初始化入口。

数据库只采用 ThinkPHP 原生迁移和 `pa_migrations`（可由 database.migration_table 配置）。本次最终结构为唯一 `20261101000000_initial_baseline.php`，后续用 `php server/think migrate:create <Name>` 增加标准 PHP 迁移；初始基线明确不支持回滚。旧 baseline 覆盖、自研版本账本、schema:application 及历史 partial continuation 已退役。不存在自动清库、重建身份或旧数据库兼容路径。详见[原生迁移合同](../maintenance/migration-protocols.md)。

模块安装/升级仍负责信任、依赖、锁、模块状态、目录和租户业务，结构变更由应用主目录原生迁移统一交付；不再读取模块私有 SQL 目录或 backend.migrations。安装执行原生 pending 迁移并核声明的自有表，缺失结构明确拒绝，不能把模块目录提升视为数据库交付。模块 schema 的新增/删除应在应用版本中审阅并交付，卸载保留原生迁移历史。

开发 APP 吸收脚手架源码仍使用 CLI `peanut upgrade` 或 `scripts/scaffold-upgrade` 的固定模板、完整 inventory、来源摘要、三方比较、归属与显式冲突决策。源码 apply 不安装依赖、不构建客户端、不迁移数据库、不部署。`.peanut/scaffold-baseline` 是有效源码比较元数据，与已删除的数据库基线目录无关；APP 自有业务、秘密、配置与持久文件不进入自动覆盖。

产品在自己的 Git dev 开发，经审核定版 main/tag。已有产品用 `scripts/deploy-update.sh [--target=<full-commit>]` 在原 main 目录 ff-only 更新 origin/main，按变化准备锁定依赖和前端构建，再执行原生迁移与清缓存。运行前准备真实恢复材料和所需停写，运行后按环境刷新进程并确认访问和本次业务；脚本失败保留现场，不自动 reset、回灌旧数据库或重装。该入口的源码实现、必要静态检查与真实生产验收分别记录。

## 10. SSR与两种形态

SSR常驻Node为多个请求生成HTML，全局可变Token、租户、store或私有HTML缓存可能串身份，这是风险模型而非已证实事故。包含网站客户端的应用中，`pc/composables/useRequest.ts`在请求作用域建立client；服务端只读取Host并通过Nuxt适配器核可信Host，显式不转发个人Cookie，访问令牌也只在浏览器取得。上游地址和协议来自部署配置，不采用任意请求输入。Pinia和个人状态必须保持当前Nuxt实例及浏览器边界，公开HTML不能混入个人数据。

`pc/utils/rendering-policy.ts` 的 `pcPublicSsrPages` 是唯一公开 SSR 登记：首页、资讯列表、分类、详情和关于我们。Nuxt 的 `pages:resolved` 从真实文件路由生成规则，并同步已初始化的 Nitro 配置，动态参数只覆盖自身路由段；每个未登记页面显式 CSR，`/**` 也默认 CSR，所以新增资讯子页面不会继承整个目录的 SSR。政策页面目前属于公开 CSR；登录、OAuth、个人资料、收藏、账户安全、充值属于私有 CSR。没有新页面登记时不要扩大 SSR 通配符。

公共 Layout 只消费既有网站公开配置；头像、昵称、登录和充值导航在 `MemberNavigation.client.vue` 中读取。新闻详情用请求级 `useAsyncData` 保存固定公开字段，匿名请求不携个人凭据，响应额外字段不进入新闻 payload。`ArticleFavorite.client.vue` 在浏览器消费既有鉴权详情接口中的收藏布尔值，收藏写入沿原权限接口；失败或失效个人会话保留可匿名阅读的正文。关于我们复用网站标题、logo、简介、slogan 和 copyright，不新增配置表。

PC URL 为 `/`，物理产物仍放 `server/public/pc`；Admin、Platform、Mobile 和 PHP API 保留独立 Nginx location。在 `pc/.env`（或原生 `--dotenv` 选择的文件）/命令环境中设置 `NUXT_PC_RENDER_MODE=hybrid|spa`：Hybrid 执行 `npm run build` 并运行 `.output/server/index.mjs`；SPA 必须另用包含 `NUXT_PC_RENDER_MODE=spa` 的配置执行 `npm run generate`，只部署该次生成的完整 `.output/public`，无需 PC Node SSR 服务。当前部署选定 SPA，只消费同次 APP 发行生成的完整浏览器产物；SSR 常驻服务及 Hybrid 运行合同本批后置，不能将 Hybrid 的 public 目录当成完整 SPA。

hybrid公开首屏、浏览器个人态、代理、缓存、退出及完整SPA模式需要真实HTTP/浏览器验证。类型检查、源码构建或模拟上游不能证明生产PHP、数据库、CDN及实际终端正确。发布前另核各依赖支持范围，不把开发标识当发布版本。

standalone与multi-tenant共用业务模块、tenant_id结构及MultiTenantDataScopePolicy。前者固定真实默认租户并隐藏SaaS运营及租户切换，不限制员工数量；后者启用运营平台、租户开通与Host管理。形态投影和运行配置须匹配；改一个环境值不会迁移数据或合并多个租户。
