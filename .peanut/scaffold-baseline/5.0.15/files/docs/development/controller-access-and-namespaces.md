# Controller 与命名空间约定

已生效；本文规定 Controller 接入、对象生命周期与命名边界；业务合同见[应用约定](application-conventions.md)，实现与验收以实际源码和运行结果为准。

## 1. 不变项

保留四仓、身份/Scope、模块编号、表/迁移身份、权限、HTTP地址和业务规则。真正Core/第三方类型不为好看改名；不改历史发布、许可证、生产或客户数据。

## 2. Controller 的唯一访问规则

| 对象 | 当前合法写法 |
| --- | --- |
| App/Request | BaseController接收App，子类继承 |
| CurrentExecutionContext | 当前App的get取得已登记reader，基类固定Getter；缺登记报错，不造空身份/用全局App兜底 |
| 单个动作服务 | 框架action类型注入 |
| 多动作/CrudTrait服务 | protected string $xxClass=正式类型::class，通过只读$this->xx取得；专用Getter/构造注入须有实际用途 |
| 业务服务内部 | 构造注入 |
| ORM策略 | 正式生命周期钩子＋明确Setter |

注册接口用get，明确具体类可make；不按请求或任意字符串选类，不为少参数再造接口/工厂/万能依赖对象。缺reader是装配错误，未登录是入口身份状态，二者不同；身份按本次请求/任务读取，不缓存跨请求人员快照。声明按受信源码默认值解析，运行时改属性值不能重定向依赖；构造时核声明，读取时按生命周期取得并核返回类型。

## 3. 只读属性

$this->context转发executionContext()，$this->xx读取对应xxClass；@property-read与实际类型一致。未知读取报错，isset不创建对象，赋值/unset拒绝。PHP8.3不使用8.4属性钩子；__get仅处理不可访问/未声明属性，核同名真实属性与继承冲突。框架Facade可按边界使用，身份不切另一个App/静态人员。新命名属性只按显式xxClass及既定生命周期解析。

## 4. 生命周期

路由认证→Controller构造/无参initialize→Controller中间件→action参数解析。initialize直接调用不自动注入；Callback的invokeClass不触发make的resolving后置钩子，不将其当属性注入入口。认证不得后移；业务操作不在构造/注册发生。

Model 在当前 Controller 内复用，处理新记录前调用 `resetControllerModel('xx')`；Validator/Query 每次读取取得新实例。服务从当前 App 取得，不缓存人员或租户快照。普通 PHP 直接调用 action 不自动注入；测试显式传服务或独立 App 绑定。CrudTrait 的既有 final 流程不可为写法省略校验；异常通过 `ExecutionContextStore::run` 的 `finally` 恢复。真实调用顺序以 `server/app/BaseController.php`、各应用 Controller 与正式服务绑定为准。

## 5. 命名空间的目标归属

| 代码 | 前缀 |
| --- | --- |
| 技术核心 | 真正PeanutAdmin\Kernel\...等原技术前缀 |
| 官方业务 | PeanutAdmin\Modules\<Module>\... |
| 测试模块 | 独立Fixture前缀，非生产能力 |
| 宿主装配/HTTP适配 | app\... |
| 第三方 | 自有合法厂商前缀，不强制Peanut品牌 |

模块根composer.json/module.json/database/migrations/resources/route保留，类型按PSR-4入src，不造空分层。宿主正式纳入映射，子目录有composer不等于自动加载；不每请求扫目录。完整类名唯一、Core不反依赖Code；拒保留/父子抢占前缀、大小写别名、越界符号链接，完整集合核验后再注册，失败不留半份登记。

### 物理路径与大小写

大小写不是全仓统一转换。宿主 `app\...` 对应 `server/app/` 中现有小写目录，例如 `app\common\services`；模块物理根使用 `server/app/modules/<vendor>/<snake_case>`，`src/` 下面的类型路径与该模块 Composer PSR-4 前缀精确匹配。`Controller`、`Validation`、`Service`、`Model` 等类型目录按需建立；资源目录 `database/migrations`、`resources`、`route` 保持自己的小写合同。没有消费者时不预建空类分层。

| 标识及入口 | 物理结果 |
| --- | --- |
| `dcs.product`、`--vendor=dcs` | 后端 `server/app/modules/dcs/product`；PHP `Dcs\Modules\Product\` 映射 `src/` |
| 管理端贡献 | `web/src/modules/dcs-product/contribution.ts` |
| 模块测试 | `server/tests/Modules/Dcs/Product` |

Module key 和 vendor 是小写标识，PHP 厂商/类型前缀与测试目录使用各自的精确大小写。`--vendor=Dcs` 不合法；旧 `server/app/Modules/Dcs/Product` 与 `app\Modules\Dcs\Product` 不构成当前路径的别名。现有实例升级、下游选定版本和历史证据必须分别处理，不能仅依靠本表直接搬目录。

可执行的映射来源是 Code 的 `ModuleHostLayoutFactory`、`ModulePhpNamespace`、`ModuleScaffoldGenerator` 和 Core 的 `ModuleHostLayout`；已有模块前缀以其 `composer.json` 为准。本节只解释边界，不另建与代码竞争的配置表。

## 6. 同步迁移

按逐符号映射同步namespace/use/全名/同namespace隐式引用、Composer/Provider/路由/监听/任务/公开合同、调用者/生成器/测试/构建/接口元数据/模块路径及正式摘要。旧Kernel业务和技术同前缀，禁止整前缀替换。持久化类名、序列化与任务消费者须同步核验；当前不合规公开 Model、Repository 和持久化实现直接撤回并迁移消费者，不增加兼容别名，不将私有类型改名后重新公开。迁移不重命名表/ID/权限/URL，不重跑已应用SQL。

## 7. 必要验证

真实action调度、三安全域Getter、缺绑定拒绝、跨App/请求隔离、只读/未知属性、异常恢复、CRUD/模块生成；逐类型反射/实际路径、Composer strict-psr/strict-ambiguous（根及独立包）、模块编译、真实依赖和固定源码包。未跑Linux/DB/浏览器就标未跑，静态检查不冒充运行；按Git协议交付。

## 8. 当前回归入口

`server/tests/scripts/CheckModuleNamespaceMigration.php` 读取 `server/tests/fixtures/module-namespace-migration-map.json`，用于验证迁移类型的加载位置和旧名退出；它是测试数据，不是新模块的命名来源。一次性迁移执行器已退役，不能从旧起点再次应用。
