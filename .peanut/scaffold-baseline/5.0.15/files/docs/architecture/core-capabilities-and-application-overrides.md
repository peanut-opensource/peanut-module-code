# Core 能力、应用复用与实现替换

Core 的价值是稳定、可复用的技术能力和可直接使用的默认实现。应用持有业务语义、数据、渠道与装配，并能通过配置或原生服务绑定改变技术实现。能力归属按真实消费者和不变量判断，不按目录大小或是否“通用业务”判断。

Provider、事件/命令、租户启停钩子、HTTP/CLI/Task 上下文及安装升级的完整时序和失败责任见 [框架扩展与生命周期](framework-extension-and-lifecycle.md)。

## 1. 成熟框架依据

本设计参考以下官方机制，复用当前锁定 ThinkPHP 的能力，不引入 Laravel/Symfony 运行依赖：

| 参考 | 可复用的提取逻辑 | Peanut 的采用方式 |
| --- | --- | --- |
| [Laravel Container](https://laravel.com/framework/docs/container)、[Providers](https://github.com/laravel/docs/blob/13.x/providers.md) | Provider 注册构造方式，消费者接收注入对象，绑定负责替换 | ThinkPHP `app/provider.php` / Service / ModuleProvider；服务不自行重建被替换的依赖 |
| [Symfony Container](https://symfony.com/doc/current/service_container.html)、[Decoration](https://symfony.com/doc/current/service_container/decoration.html) | 参数配置与服务实现是不同维度；实现可替换或装饰 | 配置传入默认服务；行为差异通过原生容器绑定应用类。仅有真实装饰需求时才增加装饰对象 |
| [Laravel Hashing](https://laravel.com/framework/docs/hashing)、[Password validation](https://laravel.com/framework/docs/11.x/validation#validating-passwords)、[Symfony PasswordHasher](https://symfony.com/doc/current/security/passwords.html) | 密码散列与新密码输入要求分别负责；既有凭据验证/重算不受注册策略误伤 | Core `PasswordHasher` 与 `PasswordPolicy` 分开，应用写用例先校验新密码再散列 |
| ThinkPHP 锁定源码 `topthink/framework 8.1.4`、`think-container 3.0.2`、`think-orm 4.0.51` | 原生配置、`bind/bound/make`、Validator、Model/Scope、事务、Cache/Log | 优先原生能力；Core 只补真实技术协议、可信上下文和安全不变量 |

这不是把三个框架的结构复制到 Peanut。对照结果用于判断职责和扩展方式；具体实现以 `server/composer.lock` 对应源码为准。

## 2. 归属与复用表

| 能力 | Core 保留/提供 | 应用层持有 | 复用与重写方式、真实入口 |
| --- | --- | --- | --- |
| 配置、容器、生命周期 | 模块 Provider 协议和绑定合同校验 | 宿主装配、模块实现及启用来源 | ThinkPHP 原生配置/容器；`app/provider.php`、`AppService`、`common/composition/ModuleComposition` |
| 密码 | `Identity/PasswordPolicy` 默认 6～1024 UTF-8 字节；`PasswordHasher` 的 Argon2id hash/verify/needsRehash | 创建/改密/重置的身份业务与事务；演示账号的固定/锁定规则 | `peanut.password` 配置新密码上下限；分别绑定策略和散列实现；安装、Identity、Member 共用注入服务 |
| 时钟、令牌 | `Auth/Clock`、UTC 默认 `SystemClock`、安全 `TokenIssuer` | 认证安全域、会话记录、撤销、TTL 业务 | 原生绑定；Tenant/Platform Auth、EffectiveAccessPreview、EntitlementQuota 消费注入对象；替换保持时间/令牌合同 |
| 身份/权限 | 可信类型、授权原语、revision cache、Scope | 账号/组织/角色/凭据/会话完整业务 | Identity ModuleProvider；可替换实现不能取消租户、权限、撤销和拒绝优先语义 |
| ORM、事务 | `TenantModel`、租户持久化/Scope 原语 | 业务 Model、表、迁移和最外层用例事务 | Think ORM 原生 Model/query/transaction；不提取逐表 CRUD Repository 到 Core |
| 输入校验 | 无第二套通用 Validator | 输入合同、可写字段、业务限制 | Think Validate；`common/validation/InputValidator` 保留字段写权限/可信上下文，不能用可配置化取消保护字段 |
| 缓存 | revision、授权命名空间/失效语义 | 业务缓存内容和有效期 | Think Cache；`RevisionPermissionCache`、`PolicyCache` 保留安全语义，普通 Cache API 不再包一层 |
| 锁、幂等 | 租户锁协议/适配、防重复原语、可信消息协议 | 动作幂等结果、业务补偿及投递流程 | `ThinkPhpTenantLockStore` / `CrontabTenantLock`；Task/Workflow 等模块组合，业务事务不迁入 Core |
| 审计与日志 | 审计写合同及通用技术支持 | 审计记录、业务事件、脱敏和执行上下文 | Think Log；Identity `AuditService`、`common/logging/OperationalLog` 各守职责 |
| 设置与秘密 | `SecretProtector`、Sodium 默认实现 | 设置目录、租户配置、密钥引用/生命周期 | Settings ModuleProvider；配置缺失仍 fail closed，不把秘密保护降为普通 config |
| 外部集成 | Webhook 目的地/秘密安全协议、OAuth transport 合同 | 租户渠道、商户/回调绑定、供应商实现与业务 | Integration/OAuth ModuleProvider；支付/回调认证保留独立安全合同 |
| 文件/媒体 | 图像变体等通用技术 contract/planner | 文件账本、引用、存储配置、授权和物理回收 | Think filesystem/供应商 SDK；File ModuleProvider；完整文件业务不搬入 Core |
| 任务/通知/工作流 | 可信执行、幂等和安全技术协议 | 表、任务状态、消息用途、投递和业务编排 | Code 官方/Peanut 模块；已有框架/队列能力优先，不造另一套通用调度框架 |
| 客户端 | Web Core 的 transport、Vue/UI/Nuxt/UniApp 环境适配 | 业务 SDK、页面、身份状态、输入提示 | Code 各端消费公共包；密码有效策略来自 HTTP，业务 API 不进入 Web Core |

没有生产实现/绑定/消费者的 Core `TenantCache` / `TenantCacheStore` 当前是未消费能力，不代表需要实现第二套缓存。已有旧业务目录的 LICENSE 不代表其中仍有业务源码；不据目录名迁移、删除或重新实现业务。

## 3. 配置与实现替换

默认安装不需要自定义类。`server/config/peanut.php` 的 `password` 默认空数组，由 Core 提供缺省值。应用加强要求可使用：

```php
'password' => ['minimum_length' => 12, 'maximum_length' => 128],
```

边界必须是整数，最小值至少 1，最大值不能小于最小值或超过散列技术输入上限 4096 字节；无效配置报错，不静默改回默认值。默认上限仍为 1024。技术输入上限用于限制散列/验证输入成本，独立于新密码策略，参考 Symfony PasswordHasher 的输入长度保护。单位是 UTF-8 字节，PHP 使用 `strlen`，客户端使用 UTF-8 编码后的字节数。中文/emoji 的字符数不能代替字节数。

配置不足时，在应用自有源码中继承 `PasswordPolicy` 或 `PasswordHasher`，并替换 `server/app/provider.php` 对应条目，或在应用 Service 的 `register()` 中执行原生绑定：

```php
$this->app->bind(\PeanutAdmin\Kernel\Identity\PasswordPolicy::class, \app\security\ApplicationPasswordPolicy::class);
$this->app->bind(\PeanutAdmin\Kernel\Identity\PasswordHasher::class, \app\security\ApplicationPasswordHasher::class);
```

自定义策略同时维护 `assertValid()`、`minimumLength()` 与 `maximumLength()` 的一致合同；自定义散列维护安全生成、既有摘要验证与 `needsRehash()` 合同。类来自受信应用源码，不能由 HTTP 参数选择。应用 Service 登记在 `app/service.php`，须在相关服务首次解析前完成；普通无参数实现直接自动注入，有构造参数才使用绑定闭包。不提供第二个 Password override 配置表。

应用服务必须接受注入的策略/散列；不自行 `new` 默认实现、不用静态工厂绕过绑定。`AppService` 不再强制覆盖密码绑定。已有版本化权限 slot 继续校验白名单/合同版本，`registry()->bindings()` 交给原生容器，应用显式原生绑定优先。该 slot 不是所有技术能力的强制扩展入口。

## 4. 完整消费与升级

新凭据写入（安装 CLI/guided、管理员/Platform 操作员/租户 owner、邀请接受、会员注册/重置/改密）执行同一个有效策略，再用注入散列服务生成摘要。登录和既有摘要重算只消费散列服务；提高最小长度不能把已有凭据变成无法验证。身份、权限、会话撤销、事务及完整性保护仍由原业务合同负责。

`GET /installapi/password-policy` 在安装前后返回当前注入策略的 `minimum_length`、`maximum_length` 和 `length_unit: utf8_bytes`，只暴露公开限制。Web/PC/UniApp 新密码表单使用有效策略；读取失败显示错误并阻止提交，没有写死的备用上下限。HTTP/SDK metadata 不再用 JSON Schema 的字符长度宣称运行时字节限制。

应用自有绑定、类及配置处于应用仓，Core 升级不直接改这些源码。若官方应用更新也触及自定义文件，沿既有基线摘要/冲突审阅流程处理，不能用升级覆盖定制。新依赖以 Composer manifest/lock 的固定源码提交为准；开发 `dev-dev#<commit>` 不是正式包发布。PHP 固定开发源码与 Web 已锁定 registry 制品可以独立组合，公开发行打包仍由 `scripts/package-release-files.py` 要求精确已发布 PHP 版本。历史发行快照与已安装实例不会因 dev 修改自动更新。

## 5. 抽取准入

新增能力先回答：原生框架是否已有；真实消费者是否跨模块且没有业务/渠道依赖；需要保留的安全/租户不变量是什么；默认实现能否直接用；配置与实现替换是否贯穿所有消费者。只有真实合同或多实现需要才新增接口，普通可替换服务可以用公开类及原生绑定。完整业务继续作为可复用 Code 模块分发，复用不要求进入 Core。

该表是职责和扩展合同。运行验证只证明实际检查过的路径；全量资格、公开发布、生产/已安装 APP 升级分别按其明确授权执行。
