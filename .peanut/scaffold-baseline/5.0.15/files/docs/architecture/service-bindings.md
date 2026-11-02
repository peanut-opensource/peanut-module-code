# 平台服务使用策略与租户配置绑定

生效。实现归属与租户数据归属是两维；运营开户时决定各服务用法，租户只有政策＋人员权限允许时可改自有配置。适用于存储/短信/邮件/Push/地图/AI/OCR 等按需能力，不代表全部已实现。

## 1. 四种策略

| 策略 | 规则/失败行为 |
| --- | --- |
| 禁用 | 明确拒绝 |
| 固定平台 | 引用指定配置，租户不能改；故障不自动换方 |
| 平台默认、允许自定义 | 授权后可启自备；启用后失败不静默回退平台 |
| 必须自备 | 缺配置/失效不可用，不借平台秘密 |

继承是绑定不是复制密钥；界面只回状态/掩码。平台控制政策，租户人员管理自有配置仍要授权。

## 2. 调用与责任

登记服务接口/字段 → 平台绑定/秘密引用 → 租户政策/自备配置 → 服务端按可信租户＋业务用途解析 → 已选适配器调用。通用模块负责政策/授权/绑定/审计，业务或渠道模块负责字段/用途/提供方/切换约束；支付商户风险不能照搬存储规则。

不自行拼密钥、不信请求任意 endpoint；校验提供方白名单、用途、字段/访问边界；秘密储存、轮换、连通测试有明确合同。配置缓存含租户/修订并失效；共用平台服务不共用租户命名空间或结果。

## 3. 配置切换不是迁移

文件保留写入时绑定/位置；改默认仅影响后续写入，旧文件走原绑定。搬存储需计划、验证及失败恢复。支付回调/退款/短信核对使用原业务绑定，不随最新配置切换；密钥轮换考虑在途操作和有效窗口。

## 4. 后台实现替换：真实权限 slot

租户服务配置不负责选择任意 PHP 类。以下实现选择来自受信应用源码和部署配置，在服务首次解析前完成；没有通用的 HTTP/UI 类名注入入口。

`server/config/peanut.php` 中已有的 `overrides` 支持 `authorization.permission.service.policy`，合同为 `app\common\contract\AdminPermissionPolicy`，版本 `2.0.0`。应用可新增自己的类，例如进一步收紧某个已登记权限：

```php
namespace app\security;

use app\common\contract\AdminPermissionPolicy;
use app\common\policy\permission\RegisteredAdminPermissionPolicy;

final class ApplicationPermissionPolicy implements AdminPermissionPolicy
{
    public function __construct(private RegisteredAdminPermissionPolicy $registered) {}

    public function canAccess(
        bool $isRoot,
        string $accessUri,
        iterable $registeredPermissions,
        iterable $grantedPermissions,
    ): bool {
        if ($accessUri === 'peanut.settings.manage') {
            return false;
        }

        return $this->registered->canAccess(
            $isRoot, $accessUri, $registeredPermissions, $grantedPermissions,
        );
    }
}
```

在原 `peanut.php` 配置项中选择该类：

```php
'overrides' => [
    'authorization.permission.service.policy' => \app\security\ApplicationPermissionPolicy::class,
],
```

真实消费链为：`peanut.overrides` → `CoreServiceOverrides::configure()/registry()` → PHP Core `ServiceOverrideRegistry::bindings()` → `AppService::registerExecutionContext()` 的原生 `bound/bind` → `AppService::registerAuthorization()` 构造 `AdminAuthorizationService` 时 `make(AdminPermissionPolicy::class)` → `AuthMiddleware::handle()` 调用 `decideApiForRequest()` → 注入策略的 `canAccess()`。菜单及权限投影也消费同一注入策略。默认实现为 `RegisteredAdminPermissionPolicy`，只有已登记权限可进入授权判断；示例仍委托它执行登记和授予判断。

应用已有的显式原生接口绑定优先于 slot 默认/配置绑定；须在 `AppService` 解析相关服务前登记。不要同时用两个入口选择不同实现。未知 slot、非数组配置、非字符串类名、类不存在或未实现合同均报错，不静默采用默认策略；Core Registry 同时校验 slot 合同与版本。依赖无法构造时由原生容器报告解析失败。可替换策略只参与权限判断，不能代替可信身份、模块权益、对象范围、租户隔离、撤权、事务或幂等合同；自定义类的安全语义由应用维护者审阅。

## 5. 设置 Provider 与定义

已有 `peanut.settings` 的 `ModuleProvider::bindings()` 是真实 Provider 示例：它将 `SettingDefinitionRegistry` 绑定到 `DeployedSettingDefinitionRegistry::build()`，将 `SettingResolver` 绑定到注入秘密保护器、revision cache、租户查询及 `peanut.settings` 配置的构造闭包。`ModuleComposition` 从已编译 `module.json` 的 `backend.provider` 加载 Provider，并将经过 `ModuleProviderBindings` 校验的绑定交给同一个 ThinkPHP 容器；缺失、重复或身份不匹配的 Provider 拒绝装配，未知类不会换成默认 Provider。

模块通过现有 `backend.setting_definitions` 声明相对资源路径。下面是应用自有模块 `example.catalog` 可声明的一条普通设置示例，并非已经安装的模块或新内置设置：

```json
[
  {
    "key": "page-size",
    "name": "Page size",
    "description": "Default catalog page size",
    "schema": {
      "$schema": "https://json-schema.org/draft/2020-12/schema",
      "type": "integer",
      "minimum": 1,
      "maximum": 100
    },
    "required": true,
    "secret": false,
    "allowed_scopes": ["deployment", "tenant"],
    "target_resource_key": null,
    "target_operation": null,
    "default": 20
  }
]
```

对应 `backend.setting_definitions` 为 `resources/setting-definitions.json`，部署配置可写 `'settings' => ['example.catalog' => ['page-size' => 30]]`。沿模块安装/目录同步入口采用定义后，`SettingsHttpApplicationService` 构造注入 Registry、Resolver、权限和幂等执行器，管理请求先验证可信租户、人员权限及模块可用性，再由 Resolver 解析。有效值顺序为 target → tenant → deployment → 应用 config → definition default；target 只用于已声明且获对象授权的定义。未知定义通过 `SettingDefinitionRegistry::require()` 拒绝，缺文件、非法字段/schema/default 由 loader 拒绝；必填值缺失或已存值损坏报错，不能改成空值或成功响应。

失败关闭的已有实例是秘密保护：Provider 在密钥缺失或配置无效时绑定 `UnavailableSecretProtector`，其 `protect()` / `reveal()` 均抛出不可用错误，不返回明文、不借其他租户密钥。普通非秘密设置仍可使用；秘密定义不允许 default，客户端只收掩码/状态。Provider 不在注册时写业务记录，配置也不跳过目录修订、租户检查、写权限、条件更新或幂等保护。

数据权限继续使用 `ModuleComposition` 汇集模块贡献的 `DataPermissionRuntimeRegistry`：资源 Provider、target resolver/catalog 和 shared-master Provider 各走已有合同。未知 provider key 按对应 Registry 拒绝，不通过增加本页的配置或权限 slot 获得跨租户范围。完整生命周期见[框架扩展与生命周期](framework-extension-and-lifecycle.md)，应用定制及升级责任见[Core 能力与实现替换](core-capabilities-and-application-overrides.md)。
