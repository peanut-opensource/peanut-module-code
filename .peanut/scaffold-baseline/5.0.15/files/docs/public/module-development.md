# 模块开发与交付

模块是交付单元。后端实现、各客户端贡献、权限、事件、迁移和公开合同可以位于不同源码目录，但由同一个稳定模块 key 和版本汇集。模块之间只能使用公开 Query、Command、Event、Job 或 DTO 合同；不要读取别的模块私表、导入其 Model，或调用内部 Controller。

模块包至少应声明负责人、版本、依赖、兼容产品范围、迁移、权限、菜单与客户端贡献。安装前核验可信来源、许可、归档摘要及逐文件清单。当前实现允许可信外部SHA-256作为来源证明；签名是另一个可选的可信证明分支，不要求每个模块作者配置私钥。没有可信摘要或有效签名时拒绝。PHP模块在应用进程内运行，不是安全沙箱；普通租户不能上传任意PHP、Shell、类名或路径执行。

模块自己的 `composer.json` 依赖也必须与目标应用相容；宿主的 Composer 安装成功，不代表其未参与依赖解析的模块声明已经验证。源码联合开发可沿用当前开发 Core，发行构建则需在隔离副本中同步宿主和模块的实际发布依赖，再用原生包管理器验证。源码接收返回 `source_declarations`、`dependencies_installed=false` 和后续待办，只表示源码已采用。完整安装/升级共用原生依赖执行：Composer 将模块作为 path 包参与宿主求解，模块开发依赖由原生开发 metapackage 参与；Web 使用 pnpm workspace，其余端使用 npm workspace，保留各依赖类别。原生失败阻止整体可用并保留原因、退出码及恢复状态。

打包收集模块后端及已声明客户端的完整源码与资源，包括生成的 API 声明；递归前跳过 `vendor`、`node_modules`、`.cache`、`.vite`、`.nuxt`、`.output`、`.next`、`.turbo`、`.parcel-cache`、`coverage`、`tmp`、`temp` 和 `unpackage` 目录。普通 `build`、`dist` 目录可能被源码、包入口或资源引用，因此保留。作者无需为打包删除本地依赖；路径越界、符号链接、环境配置和凭据仍拒绝交付。


模块适用 Edition 声明在已有 `composer.json` 的 `extra.peanut.editions`，值为 `multi-tenant` 或 `standalone` 的非空列表，不新增另一份清单。八个默认初始模块声明支持 `multi-tenant` 与 `standalone`，两种 Edition 均使用真实租户身份和原有权限隔离；检查、采用、安装及运行按宿主 Edition 拒绝不匹配或缺声明。平台入口仍受宿主 Edition 约束，模块声明不开放平台能力。模块版本与 Composer 版本保持同一来源，模块各自独立升版。

存在 Web／Platform 贡献时，模块安装、升级、卸载及发版准备在原生依赖完成后、受影响客户端构建前，调用同一 `scripts/generate-api-contracts.php` 刷新宿主 API 类型和输出。CLI 模板 source apply 只采用源码，不代表已完成这些运行准备；更新后须让生成的 API 目录匹配应用最终的品牌化声明、锁和源码。仅 PHP/API 输入改变且客户端未变时，可在 APP 根通过原生 project-env 执行 `php scripts/generate-api-contracts.php --check --catalog-output="$PWD/server/generated/api-catalog.json"`，只刷新目录，无须重建客户端；有客户端/API 类型变化时仍按受影响客户端完成原生准备。纯后端模块没有前端消费者时不因此要求 Node。

多个已安装包需要联立更新原生依赖图时，原生 `reconcile` 调用可重复指定 `--selected-installed=<package_key>`，选择包含其必要依赖的已安装锁定图。该调用按真实 Module 节点准备一次 Composer／workspace 依赖和客户端输出，在同一依赖锁内按依赖顺序恢复选中包的 active／failed 安装状态；同批生命周期消费这次已经完成的准备结果，客户端各构建一次。

产品 Git 更新已按锁准备 PHP 依赖并采用静态文件后，使用 `reconcile --release-locked --prepared-release`：复用运行依赖与客户端入口校验，通过原生生命周期采用已有 active 包，不调用依赖解析或客户端构建。该选项仅用于 release-locked；普通开发安装／升级仍使用其原生准备流程，未准备的发行输入不能靠该选项跳过校验。

## 可用的开发与交付入口

以下命令在完整产品 APP 根执行；先用 `peanut config` 选择已有本机工具，再通过 `scripts/project-env` 运行项目原生 Composer、pnpm/npm。源码生成不执行需要运行环境的 Composer hooks。`peanut module create` 是仅生成源码的入口，不要求 `.env`、数据库或已安装实例，也不启动运行服务。它使用 ThinkPHP 原生参数解析和同一个模块生成器，默认仅生成后端骨架；有管理端贡献时显式选择 `--client=admin-web`。`peanut module create --help` 可在配置运行环境前查看参数。检查、打包、安装等其他命令仍使用应用自己的运行环境和模块信任配置。命令存在不表示模块业务、迁移或客户端已验收。

```sh
peanut module create <vendor.module> --vendor=<vendor>
peanut module check <vendor.module>
peanut module compile
peanut module pack <vendor.module> --output=<absolute-release-path>/<name>.tar
```

源码 HTTP 请求只读取已编译模块注册表，不逐请求校验插件目录、编译声明或扫描 PHP 源码边界。`peanut module compile` 在 CLI 中校验锁定声明和后端模块并写入 ThinkPHP 原生 File 缓存，不运行迁移或访问业务数据库；缓存只含模块声明，不含租户、用户或权限快照。`think run` 启动前自动生成，直接使用 `php -S` 的调用方须先执行该命令。`peanut module lock --write` 同步刷新编译结果；锁文件、依赖身份或编译配置改变后，其他采用入口须重新执行 `peanut module compile`。缺失或输入不匹配时 HTTP 返回 503，不在请求中自动重编译。编译结果位于 `runtime/module-registry`，普通业务缓存清理不删除它；清空整个 runtime 后须重新编译。正式部署继续使用既有 VerifiedServerDeployment 发布/读取流程。

Identity 升级若变更核心权限目录，升级完成后须在新的 CLI 进程执行 `peanut module sync --module=peanut.identity`，再执行 `peanut module compile`。PHP 已加载的类不能在同一进程内由新源码替换；升级进程中的目录同步可能仍读取旧权限目录。新进程同步通过原有目录生产链采用新定义；目录生成不授予成员或角色权限，已有租户权益仍由套餐/许可控制。

后端启动、安装和已锁模块治理使用同一解析器的 `forRuntime()`，仍核验锁结构、manifest 摘要与声明一致性、模块 key/路径/命名空间归属、PHP 包身份和后端模块合同；前端贡献保留声明及 npm 归属核对，不读取 web/platform 源码或 Node。完整源码的 canonical 摘要包含前端文件，只在源码创建、升级、锁生成及包采用等源码操作校验；普通构造解析器和 `forSourceUpgrade()` 保留完整文件核验，后端投影仍校验自身完整内容。不要为后端运行补挂前端目录，也不要将运行期模块编译当作前端构建或完整源码资格。

生成产品的 HTTP 版本展示只读取 development-runtime 的版本声明，不借展示入口重新校验整个源码与 vendor。该版本值不能作为安装、升级或部署准入证明；这些维护入口继续校验完整身份。配置了 `project.version` 时不再读取默认版本。

普通平台、租户 access token 认证从主库无锁读取当前会话及身份状态，不为正常读取锁住租户、账号和会话行。失效会话的撤销先在事务中加锁复核；刷新、登出、切换租户等写操作及既有事务内的认证继续使用加锁路径。租户后台 Login 与 Auth 中间件通过 ThinkPHP Request 内部存储传递一次已装载的身份，权限中间件消费后清除；身份不进入跨请求缓存。业务用例的 `decide()` 和授权操作仍重新核对当前身份，不复用这一中间件传递记录。

包作者通过可信渠道交付archive和SHA-256，不能把未知包旁自行计算的摘要当作可信来源。`peanut module install <archive> --sha256=<trusted-64-hex>`是已知Standalone / multi-tenant实例的本地开发或调试Runtime安装入口；它不创建租户、开通TenantModule或授予RBAC。确需签名时，打包同时提供`--signing-key-id`和`--signing-secret-key-file`，安装可显式要求`--signature-key-id`；既有签名不被无条件忽略。生产模块交付仍走该版本支持的受控入口，不允许普通租户自行上传和运行PHP。

租户开通使用套餐与许可证：套餐包含已安装模块及明确权限组合，租户的唯一当前许可证引用套餐并记录期限和状态。平台通过 plans 和 tenants/license 管理，逐租户模块 enable/disable 接口与命令已退出。安装包不会自动改已有套餐或授予成员权限；升级许可证只改变可用上限。成员权限沿应用的角色、成员管理入口办理，并核对实际权限键及租户 Scope；平台 operators/roles/replace 是平台操作员角色入口，不等同租户成员授权。

以下动作彼此独立：

1. 发现源码不代表已经安装。
2. 安装模块包不代表给所有租户开通。
3. 给租户开通不代表给所有人员授权。
4. 人员授权不能绕过模块状态、Tenant Scope 或对象状态。
5. 停用默认保留数据，并分别处理新入口、在途任务和客户端贡献。
6. 升级不得覆盖实例秘密、自有模块或应用自定义区。

对外模块包应带版本化说明，至少覆盖输入输出、权限、正常与失败例子、迁移、重试、停用和升级边界。API metadata 与实现同批刷新，并运行 `./scripts/check-openapi`，确保删除接口时不残留旧 SDK 或文档条目。

PC 的 `pc/modules/<slug>/contribution.ts` 使用 Nuxt 原生页面、组件和 layout 注册；UniApp 的 `uniapp/src/modules/<slug>/contribution.ts` 由 Vite 构建入口生成正式 pages.json 与必要页面入口，业务源码只保留在模块中。Web 沿用既有 contribution 入口。各端依赖写在模块自己的 package.json，由原生 workspace 求解；宿主只保留自身依赖。默认生产锁不自动加入开发用 fixture。

## 普通管理动作的写法

简单列表、详情和增删改可参考 `ArticleCateController`：声明 `$crudClass` 指向当前 App 已绑定的业务用例，`CRUD_VALIDATE` 指向校验器，再按动作声明 `CRUD_INPUT_FIELDS`。新增、修改和状态变更还必须分别声明 `CRUD_WRITABLE_FIELDS`；`id` 等定位字段是控制参数，不应作为可持久化字段。已接入字段声明的 Controller 漏写场景或可写声明会报配置错误，额外请求字段会被拒绝；未接入声明的旧 Controller 仍按受控迁移保留旧路径，不能把本约束视作全站写入保护。服务仍负责 Tenant Scope、对象归属、业务不变量和事务，不能只靠 Controller 字段投影保护内部调用。

分类模块的最小声明形态如下；实际字段、校验规则和授权由各模块决定：

```php
protected string $crudClass = ArticleCategoryAdministration::class;
protected const CRUD_VALIDATE = ArticleCateValidate::class;
protected const CRUD_INPUT_FIELDS = [
    'add' => ['name', 'is_show', 'sort'],
    'edit' => ['id', 'name', 'is_show', 'sort'],
];
protected const CRUD_WRITABLE_FIELDS = [
    'add' => ['name', 'is_show', 'sort'],
    'edit' => ['name', 'is_show', 'sort'],
];
```

需要软删除时，只有模块明确启用并登记回收、恢复、永久删除路由和权限后才开放相应动作；恢复要先检查冲突及关联，普通查询和导出仍保持租户过滤。订单、账本、支付等具有独立状态和补偿规则的操作应写明确业务用例，不套普通删除。

## 数据库结构交付

删除自有表时，从 database.owned_tables 移出并声明 database.retired_tables，保留其原生历史迁移和删除迁移的归属。退役声明只允许所属模块的正式原生迁移文件引用，不要求该表存在，也不给业务代码或其他模块访问权；不能与当前表或其他模块的声明重复。

平台 HTTP 卸载沿已有 Ops 队列交给 CLI，提交后按任务状态确认结果。正常退役先将目标后端和客户端源码移入现有 quarantine，再撤销目标自己引入的 Composer require/path repository、开发 metapackage 及精确 workspace 路径；宿主依赖、其他模块节点和共享 workspace glob 保留，目标源码已不再被 glob 匹配。原生 Composer、pnpm/npm 更新锁和安装结果，重建受影响客户端并刷新编译登记后才完成退役。失败保留原确认计划和 quarantine 声明，沿同一入口继续，不重建业务数据。锁外但已有安装记录的旧模块，可从原 canonical 插件与模块声明停用、退役；不为清理而加入当前运行锁或激活其旧依赖。

模块在 module.json 的 `database.owned_tables` 声明自有表，以 `backend.migrations: "database/migrations"` 声明自己的 ThinkPHP 原生迁移。安装和升级按目标模块及其强依赖收集迁移，沿用原生数字顺序、执行器和单一账本；宿主尚未应用的迁移先由宿主原生入口处理。首次安装使用宿主初始结构和实际所选模块迁移；默认脚手架保留 identity、file、settings、reference-codes、task、ops、content-tools、integration 八模块，其他模块通过独立包加入。已有表由其初始迁移核对并保留数据，不通过补写账本伪造执行。停用、卸载保留表、设置值、附件及迁移历史；清除业务数据需要单独明确授权。模块声明目录与必需静态默认由所属模块安装处理，租户级必需数据由真实租户初始化处理；应用组合初始化基础套餐与当前许可证，不以包安装成功代替租户权益或成员角色授权。
