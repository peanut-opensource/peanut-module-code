# OAuth 与渠道复用

`module.json` 的 `contracts.exports` 与各客户端 `contribution.ts` 是正式入口。模块启用和依赖装配由宿主负责；不要访问 OAuth 的 Model、私表或持久化实现。

## 服务入口与消费者

| 入口 | 输入和输出 | 现有消费者 |
| --- | --- | --- |
| `Contract\OAuthCommands` | `begin` 接收可信租户系统上下文、`oa` / `open_pc` 场景、站内返回路径、回调 URL、已解析渠道绑定，返回授权 URL / 有效期；`callback` / `miniProgramLogin` / `complete` 返回 `OAuthLoginResult`；`bind` 接收已认证会员上下文 | 模块 `Service\OAuthEntryService` |
| `Contract\OAuthQueries::wechatSubjectForMember` | 可信会员/租户上下文、会员 ID、终端 ID → 微信 subject；无绑定或非正 ID 返回空字符串 | Payment 的 `RechargeApplicationService`，用于渠道授权前的身份查找 |
| `Contract\OfficialAccountCallbacks` | `verify` 校验微信回调参数与已解析配置；`handlePlain` 在已确定租户系统上下文中处理明文 XML | 模块 OfficialAccountCallbackService |
| `Contract\OAuthCallbackLocator` | 从 state / ticket 摘要定位候选渠道绑定；候选不授予上下文或权限，宿主仍须验证候选并由命令原子消费 | 模块 `OAuthEntryService`；不能由请求任意指定租户 |
| `Service\OAuthBrowserCallbackService` | 场景与产品 origin → 微信回调 URL；客户端与回调 query → 产品客户端 URL | 模块 MemberOAuthController / 浏览器回调 |

通过现有容器注入合同即可复用，例如只读查询：

```php
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\OAuth\Contract\OAuthQueries;

// 由宿主注入 $oauth，并从当前可信执行上下文取得 $context。
function wechatSubject(OAuthQueries $oauth, TenantContext $context, int $memberId, int $terminal): string
{
    return $oauth->wechatSubjectForMember($context, $memberId, $terminal);
}
```

上下文不能从提交的 tenant_id 构造；查询由已建立的租户 Scope 限定。调用方仍负责本业务对象的权限，不将 subject 查询当作登录或支付授权。Provider 已绑定接口，不需要另造容器或实例缓存。授权 state 和资料补全 ticket 有效期均为 600 秒，服务按事务消费一次；不要持久缓存或跨租户复用。`OAuthLoginResult.completed=false` 表示需要走补全流程，不能提前建立登录会话；OAuthEntryService 通过 MemberAccessTokens 公开合同签发最终会话 token。

服务异常继续交给宿主错误映射，保留稳定错误码。例如 `OAUTH_SCENE_UNSUPPORTED`、`OAUTH_RETURN_PATH_INVALID`、`OAUTH_STATE_INVALID`、`OAUTH_IDENTITY_ALREADY_BOUND` 和 `OAUTH_LOGIN_BUSY`。调用外部微信、短信等动作必须具备真实配置及相应授权；缺配置不伪造成功或改用其他租户渠道。

## 客户端与管理页

现有页面的真实链路是 PC `pages/oauth/callback.vue` 调用
`callbackWechatPc(client, code, state)`：仅 `completed=true` 且有 token/member
时建立宿主会话；否则暂存补全 ticket 并进入 `/oauth/complete`。回调错误保留错误信息，
由用户返回登录重新授权，不自动重放一次性 code/state。返回地址只采用服务确认的站内路径。
UniApp 的 `pages/oauth/callback.vue` / `complete.vue` 使用本端相同职责的 API 和会话，
不是 PC 回调页的别名，不把 `/oauth/callback` 当作 UniApp 业务返回页循环进入。

绑定沿已有 `oauth-binding` ModuleSlot → `OAuthBindButton.vue` →
`bindWechatIdentity('mnp', code)`，仅微信小程序环境展示，code 来自本次 `uni.login`。
该请求使用当前已认证会员；绑定成功提示不等于另建登录身份，也不替 Payment 授权。
自定义用户中心复用此 contribution/slot，不新造全局绑定状态或复制会员档案。
实际外部回调、资料补全和绑定均须真实配置及相应授权；本指南未执行，记为 NOT_RUN。

- PC 的 contribution 注册 `oauth-login` 插槽、`OAuthLoginButton` 和 `/oauth/callback`、`/oauth/complete`。按钮复用本模块 `api/oauth.ts` 的 `beginWechatPc(client, returnPath)`；请求 client 由调用者注入，返回 `authorization_url` / `expires_in`，无需另建请求客户端。
- UniApp 的 contribution 注册 `oauth-login`、`oauth-binding` 及回调/补全页。宿主 `ModuleSlot.vue` 已消费这两个插槽；登录和绑定按钮复用模块 API。组件状态属于组件实例，不是跨用户单例。
- Web 的 contribution 注册 `/app-setting/channel`。页面复用模块 `api.ts` 的配置、菜单和回复 API，以及 FilePicker。配置 API 返回 DTO；写入函数只接收对应表单字段；失败由现有请求错误层处理。

管理页只显示有读取权限的渠道，首次打开页签时加载配置。H5 / 小程序分别维护加载、失败和重试状态；加载成功前禁止编辑和保存，保存权限单独控制。AppSecret 的 `******` 是保留现值的掩码，小程序返回的 `app_secret_configured` 仅表示是否已配置，不是密钥。

页面菜单当前以 `peanut.oauth.web-page.config` 作为入口权限，各渠道仍需其独立 config/detail/list 和 save 权限；页签可见性不替代后端鉴权。现有菜单只支持单个页面权限，因此只授予其他渠道读取权限、没有 H5 入口权限的角色仍不能进入此页面。调整这个合同需要身份/菜单提供者与消费者共同决定，本模块不扩大授权。

本模块没有真实微信配置时，只能确认本地配置和页面路径，不能声称微信登录、真实回调、菜单发布、短信补全或支付已验收。
