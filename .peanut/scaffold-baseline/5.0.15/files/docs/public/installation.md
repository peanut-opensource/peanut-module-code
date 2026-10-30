# 新实例安装与配置

本页区分产品开发首次安装与生产首次安装。先核产品独立版本、上游来源、Edition、固定程序来源及实际依赖锁；开发源码不等于正式发行。已有实例执行[原目录更新合同](application-release.md)，不通过首次安装覆盖数据。

## 产品源码开发的首次创建

产品源码首次创建按[产品开发流程](README.md#产品开发与定版)执行：目标源码目录和产品数据库必须是新建空对象，使用 Peanut CLI `create` 生成项目。它不安装依赖、不建库、不启动服务，也不初始化产品 Git。随后按锁准备依赖、开发环境及必要构建，启动并通过安装页面向导安装，确认访问后同步自己的产品仓库，在 `dev` 继续开发。既有产品在原目录和专库上继续，不默认重建。

生产首次安装从产品已定版 `main` 的固定提交取得程序和已受管的后台静态文件，在全新空目录、空库准备 PHP 锁定依赖和生产配置，启动后走同一安装页面向导。前端在开发／发行机器的定版环节构建并随 Git 提交；生产不需要 Node、npm、pnpm 或前端压缩包。正式准备／更新命令见[交付合同](application-release.md#1-产品定版与前端准备)。

生成 APP 的网页首次配置支持 `development` 与 `production`，使用有效 Setup Token；来源身份由 `.peanut/application-manifest.json` 与 `release-versions.json` 校验，Edition、版本及两份声明的摘要绑定到私有配置回执。Docker 中网页只提交 pending 输入，原生入口关闭流量并停止 PHP 后重新核对身份和 token，再由 owner 发布配置。非 Docker 部署由同一 PHP 用户写入和读取首配私有文件，源码目录可以由独立部署用户持有。已有配置、安装身份和部分失败继续阻止重复首次配置。带 `development-runtime.json` 的开发运行布局与内部候选仍只允许 `development`，不能用于生产。

## 1. 准备独立实例

核对产品程序身份、后端插件锁、`server/composer.lock` 和受管的 `server/public/admin`、MT `server/public/platform`。这些普通 HTML/JS/CSS 已在发版机器按锁构建，与后端属于同一个固定 Git 提交；生产只由 Nginx/public 提供。PC/H5 后置。

按该版本准备Docker Compose、Python、OpenSSL、shell和固定PHP/Nginx镜像。PHP及扩展须满足Composer锁。Compose挂载实例源码、vendor、配置与持久目录；普通更新不构建应用镜像，环境变化另行准备和验证。

server/.env存后端配置，包括DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS；ThinkPHP原生加载该文件，config中的env()优先读取环境值并使用固定默认值。原生PHP_ENV_NAME选择server/.env.<name>；其PHP_<KEY>进程变量只在文件未提供该键时生效。server/docker/.env仅管编排、端口和镜像。配置使用实际owner、普通单链接和私有权限，拒绝越界链接。初始管理员密码不写入这些环境文件，也不进Git或产品包。 `server/.env.example` 中注释的可选项展示配置代码的默认值，只有需要覆盖时才取消注释。登录锁定默认 30 分钟；异步 worker 默认每批处理 25 项。已有实例的显式配置继续生效，更新示例不会改写它们。

## 2. 数据库与资源

普通安装与运行使用 ThinkPHP 数据库连接配置。安装首配的 `app_env` 写入既有 `APP_ENV`；`database_source=bundled` 使用实例 MySQL，`existing` 直接填写主机、端口、库名、普通账号和密码。已有数据库不由安装配置器创建或修改账号权限。Compose 使用已有数据库时通过 `compose.registered-database.yaml` 只启动 PHP/Nginx；来源选择保存在私有配置回执中。

为已有数据库运行 `configure-runtime.py --database-source=existing` 后，按其返回的 `start_command` 首次启动。此时安装向导尚未写入私有配置回执，必须显式选择原生 Compose override：从产品根目录运行 `server/docker/scripts/start.sh -f compose.registered-database.yaml`。在向导中选择“已有数据库”并填写连接；配置回执发布后，后续启动自动读取该选择。首次启动不省略 `-f`，不准备或启动内置 MySQL。

已配置实例的缓存、会话和日志运行标识由 `APP_ENV` 与默认连接的类型、主机、端口、库名和表前缀稳定派生，不包含账号、密码、目录或维护资源 ID。相同数据库使用不同 hostname 配置时，缓存和会话标识仍不同；改变这些配置会使既有缓存和会话失效。MySQL 业务锁使用真实 schema 和表前缀，同库同表通过不同连接地址访问仍互斥。

DB_NAME、DB_USER 为空或 DB_PASS 未提供时，配置可以加载，但不登记数据库连接；实际使用连接时由 ThinkPHP 明确拒绝。显式空密码沿用原生连接能力，安装向导对已有数据库仍要求填写密码。首配前的文件缓存和会话标识使用应用根目录与 `APP_ENV`，防止不同未配置实例共用 cookie 名；配置齐全后切换到上述数据库配置标识。环境预检不检查数据库，后续数据库配置预检会明确报告未配置。host/port/type/charset/prefix 使用 config 中的固定默认值，容器连接须按实际服务覆盖 host。SQL 调试输出默认关闭，只有显式启用 APP_DEBUG 或原有受控采样才启用。

ThinkPHP 会优先使用 `server/runtime/config.php`，已有配置缓存可能遮盖 `.env` 或源码配置变更。真正首次配置发布时，先失效该原生配置缓存文件，再发布 `.env` 和配置回执；缓存失效失败时不先发布已配置状态。既有实例与其他运行数据不因此清理。首配不写入 PROJECT_VERSION，未设置手工覆盖时，版本继续由已采用的源码或发行身份决定。HTTP 首配仍须满足依赖、模块编译、Edition 与来源准入，配置可加载不等于整个 APP 首次安装已通过。

多租户首配只要求 `PLATFORM_HOSTS` 标识平台入口；租户站点入口在数据库中绑定。资源登记、endpoint、consumer 和租约证明属于需要它们的维护命令准入，普通运行与首次安装不强制填写维护资源身份。

操作前核实际目标、连接、权限、空库和安装身份。未知数据、已有 APP 库或 partial 库不得清空。开发源码、内部候选和正式实例身份不同，开发模式不能带入生产；具体身份适配尚未完成时停止相应安装路径，不手改清单或回执。

## 3. 安装

安装向导依次处理协议、环境检查、实例配置、数据库与权限、执行和完成登录。状态含stage/reason/remedy/writes/safe_retry。网络错误不证明没有写入；未知结果先查询，不重放安装。

访问 `/install` 或 `/admin/install` 打开同一安装向导；后台的安装跳转也进入该页面，不维护另一套安装表单。页面不依赖管理端的构建或第三方在线资源。左侧步骤展示当前进度，环境与连接检查逐项显示实际结果；原始响应收在“诊断详情”中，默认折叠。配置提交与账号安装分别显示结果，等待部署人员发布的配置不会显示为已生效；安装执行期间只显示等待状态，不推测百分比。连接中断后先读取原实例状态，仅服务器确认 `uninstalled` 和 `safe_retry=true` 才开放数据库复查，实际连接、库身份与安装状态就绪后重新开放执行。`SHOW GRANTS` 文本只提供权限诊断，无法确认角色授予等有效权限时不阻断安装；实际 SQL 权限不足由原生迁移报告，并保留失败阶段与已有数据。

Setup Token 在需要写入的步骤中显示为“安装验证密钥”，来自本实例 `PEANUT_INSTALLATION_SETUP_TOKEN`。账号密码要求读取服务器的现行策略，独立应用只填写应用管理员，多租户另填独立平台管理员；可选模块沿现有安装器目录选择，必需模块保留。表单值不写入浏览器存储；安装成功后清除密钥和密码，再显示后台登录入口。

网页向导是常规首次安装入口。已有原生控制台安装器供明确采用该合同、已准备依赖与配置的维护场景使用，不是在网页安装后再执行一次的步骤：

```sh
php server/database/install.php --preflight
PEANUT_INSTALLATION_ENV_FILE=/absolute/private/initial-admin.env php server/database/install.php
php server/database/install.php --status
```

仅preflight明确ready才执行。一次性0600文件提供ADMIN_INITIAL_EMAIL/ADMIN_INITIAL_PASSWORD；多租户还需独立PLATFORM_INITIAL_EMAIL/PLATFORM_INITIAL_PASSWORD。安装器直接将其解析为一次性身份输入，不同步到全局环境。成功核对后销毁一次性文件，不保存或输出秘密。

依赖按 `server/composer.lock` 准备。原生 prepare-vendor 在全新完整 APP 尚无 vendor 时，校验其声明／PHP归档并直接准备依赖，再完成 service discovery/vendor publish、平台检查及原生 seal；其他正式 prepared/server 输入仍按其既有边界处理。失败不启动业务；已有无效 vendor 或 installed 实例不通过首次准备覆盖，须按实际效果走停写维护。PHP owner 启动及 HTTP 安装预检不重新读取原始 Web TGZ 或 PHP ZIP，仍核版本来源和已装包与锁的一致性，不把缺少构建输入等同于已装依赖损坏。

## 4. 完成、重复安装和失败

独立应用安装完成后，在应用访问地址打开 `/admin/login`，使用安装时创建的应用管理员账号登录。多租户安装只建立平台、初始 `default` 租户和管理员身份，不自动绑定租户域名；首次进入租户后台前按以下步骤完成入口配置：

1. 在已配置的平台访问地址打开 `/platform/`，使用安装时创建的独立平台管理员账号登录。访问地址使用实际部署的协议和端口，不从当前安装页面的 Host 推导；开发时使用实际平台前端访问地址。
2. 在“租户运营 → 入口域名与客户端”选择初始 `default` 租户，填写已解析并路由到此应用的租户域名，客户端选择“管理端网页”（`admin-web`），填写变更原因并启用绑定。平台域名由 `PLATFORM_HOSTS` 限定，须与租户管理域名分开；同一个域名和客户端只能绑定一个活动租户。
3. 在绑定的租户访问地址打开 `/admin/login`，使用安装时创建的应用管理员账号登录。租户的协议和端口取其实际部署配置；平台账号与应用管理员分别登录，未绑定的域名不能用于租户登录。会员端如需使用，由平台另行登记“会员接口”（`member-api`）绑定，不能借用管理端绑定。

server/private/installation/installed.json是完成回执和防重装身份。已有 installed、executing、旧安装身份或 partial 数据须按真实状态处理。重复安装拒绝；--skip-if-installed只跳过已完成状态，不清库或重置管理员。

安装完成后安装页面应关闭。数据库为空、不可达或回执损坏不产生重装授权；不得删锁、删账本、手填成功或用旧空库备份替代当前partial保全。原执行记录中的模块选择仍有效，且原生迁移、所选表、核心身份和种子健康已完成时，状态返回 `INSTALL_RESUME_READY` 和 `safe_retry=true`。修复具体失败后，通过同一安装向导或原自动安装入口继续模块安装、租户配置和完成回执；保留原数据库和账号，不再初始化身份或执行 Host `InitialSeeder`。尚未首次激活的模块会继续其声明的幂等初始种子，已激活模块不重跑种子。无法确认这些事实时继续保留现场，不自动续作。

HTTP 安装只使用已安装的 PHP／模块依赖和已构建的客户端文件。开发／发行机器先运行 `scripts/deploy-update.sh --prepare-release`；生产从产品 Git 采用这些文件并按 PHP 锁准备依赖。缺少模块依赖或客户端入口时返回 `MODULE_RELEASE_PREPARATION_REQUIRED`，HTTP 不启动 Composer、Node 或前端构建。普通模块安装／升级仍通过 Ops 后台任务完成其原生依赖及前端采用。

HTTP worker 的执行时限须覆盖首次迁移和所选模块安装；本地开发可为该实例设置有限的 PHP `max_execution_time`（本次八模块安装使用180秒），保留默认扩展加载，不修改其他实例的全局配置。超时后先核对原请求和 worker 已终止；结果未知时先对原执行对账，不重发。再读取原安装状态，仅 `INSTALL_RESUME_READY` 且 `safe_retry=true` 时继续原模块选择，不能清库或重置身份。模板 source apply 后仍须完成[模块运行准备](module-development.md)；仅 PHP/API 输入变化时可单独刷新最终 API 目录，复用未变客户端构建。

旧安装状态不提供自动兼容、迁移或重装；需要处理旧现场时单独核定来源、数据和恢复边界。

安装状态、服务启动、管理员登录、权限和业务分别确认。静态检查不替代运行结果；已有实例的更新、备份和回退见[产品 Git 交付](application-release.md)。

结构使用官方 `migrate:run`，动态管理员和默认租户初始化后由官方 `seed:run --seed=InitialSeeder` 完成数据与权限目录。不要在网页安装成功后再执行 seed。初始迁移不支持回滚；种子发生部分失败后明确拒绝重复执行。原生版本表默认 pa_migrations；旧数据库基线和自研账本不再参与安装。

## 5. 初始数据与可选示例

创建 APP 只复制固定源码中的结构、种子和可选示例入口，不读取或导出脚手架开发数据库。两种 Edition 使用同一套真实租户身份和原生迁移目录，Standalone 不要求输入独立的平台账号；Multi-Tenant 使用独立的平台和默认租户管理员输入。

| 类别 | 来源与首次安装行为 |
| --- | --- |
| 表结构 | 所选模块自带原生迁移先建立自有表，Host `server/database/installation/20261101020000_initial_scaffold_baseline.php` 采用 `server/database/schema/baseline-schema.sql` 的宿主表。首次安装将它与所选 SQL 投影到原安装暂存目录，再交同一原生执行器；成功执行后由官方适配器记入 `pa_migrations`。 |
| 必需系统数据 | `InitialSeeder` 在真实默认租户创建后写中性租户设置；定时计划初始为空。模块安装同步自有权限、API、菜单、设置等声明目录，并执行 `backend.seeds` 的必需中性初始种子；File 自带本地存储账号、空间和路由。目录不等于租户开通或成员授权，不创建样例成员、部门、文件、订单、执行历史或外部渠道占位。 |
| 实例身份与配置 | 当次输入建立管理员、默认租户及适用平台账号，实际插入结果决定关联 ID；域名绑定来自该实例的明确输入，秘密及回调 key 当次生成。开发库中的账号、密码散列、域名、凭据、会话、安装身份和迁移记录均不复制。 |
| 可选示例数据 | 默认 `PEANUT_DEMO_MODE=disabled`，首次安装不执行示例脚本，不创建 A/B 演示租户或合成业务记录。普通工作台显示“尚未接入业务统计”，不会将固定数值或随机趋势显示为实际业务数据。 |

完整实例 SQL dump 属于独立恢复材料，可能包含身份、秘密和业务数据；它不是上述种子，不进入模板包，也不用于填充另一个新 APP。

`server/database/installation/` 仅提供首次安装输入；普通 `migrate:run` 仍扫描 `server/database/migrations/`，不会把新 initial 当作已有实例的增量升级。已有实例的迁移执行情况以原生迁移表为准。APP manifest 的 `installation_migrations` 只记录安装来源路径和摘要：有安装完成或执行状态时，源码升级保留这些文件的原字节与身份，并转为应用持有的安装来源；不会删除旧 initial、改写历史来源或补造已执行版本。尚未安装的 APP 采用目标模板的安装来源快照。

需要明确的演示实例时，复用现有 `scripts/seed-demo-data`：完成安装后，在该实例后端环境显式配置 `PEANUT_DEMO_MODE=enabled`、目标实际 `PEANUT_DEMO_TENANT_ID` 和 HTTP(S) `PEANUT_DEMO_DOCS_URL`。先预览计划，再按该命令既有资源/租约准入执行：

```sh
PHP_ENV_NAME=<明确的环境名> php scripts/seed-demo-data --plan
PHP_ENV_NAME=<明确的环境名> php scripts/seed-demo-data --apply
```

该入口创建合成文章分类、文章、会员标签和会员，只更新所选租户的官网地址。它不创建管理员或新租户；ID 从实际数据库解析，不复用开发库 ID。重复执行会更新同名示例内容，不能作为恢复或清理命令。演示模式同时启用既有演示账号策略和工作台样例展示，不能作为生产配置。MT A/B 租户专项演示入口是维护者工具，不随 APP 模板交付。

官方 Seeder 不向迁移版本表写种子成功记录；首次初始化是否完成还须核安装完成身份，不能只看基线版本已 applied。后续结构或系统数据变化通过新增原生 Migration 交付；已有实例只执行 pending 版本，不重放 `InitialSeeder`、复制开发库账本或改写已应用迁移。可选业务示例不进入版本迁移。
