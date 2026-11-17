# AI 接入配置

`custom.ai-access` 在租户内维护模型厂商、目录模型、服务商接入、令牌及每种能力的默认模型。模型厂商表示模型的研发方。服务商可接入不同厂商的模型，同一目录模型也可以经多个服务商调用。每个服务商的 API 前缀和地址固定，由实例维护者在 `AI_ACCESS_ALLOWED_ORIGINS` 中登记精确的 HTTPS origin。

1. 登记服务商及其令牌。令牌由系统密钥环加密。其模型范围留空时仅用于发现，可填写已登记的接入标识，或明确使用 `*` 允许该服务商下的全部接入。
2. 在模型页面登记厂商和目录模型能力。可用选定令牌读取服务商的 OpenAI 格式模型列表，再按实际能力登记模型接入。每条接入关联一个目录模型及一个服务商，记录远端标识与可调用的协议。
3. 按能力选择默认接入，或使用自动选择。在有权限的租户后台通过「调用测试」提交文字或生图请求。

消费模块声明对 `custom.ai-access` 的依赖，在已授权的租户后台业务入口使用公开的静态门面，也可通过容器注入 `Custom\Modules\AiAccess\Contract\AiClient`。

```php
use Custom\Modules\AiAccess\Facade\Ai;

$reply = Ai::generateText($prompt, $requestKey, model: 'qwen-plus', provider: 'aliyun');
$image = Ai::generateImage($prompt, $requestKey);
```

可使用目录模型、服务商和令牌的稳定标识。未指定模型时优先尝试对应能力的默认接入，再按服务商和模型优先级选择。未指定令牌时在同一服务商启用且满足范围的令牌中轮询。显式令牌若跨多个服务商同名，需要同时指定服务商。文字协议支持 OpenAI Responses、Chat Completions 和 Anthropic Messages。生图支持 OpenAI Images 的单张 PNG 响应。

调用者校验业务权限，在业务事务之外执行，提供同一次操作的稳定请求键。提交后可使用 `Ai::requestStatus($requestKey)` 查询当前成员的请求回执。回执保存实际选择的接入和令牌标识、服务商请求标识及原始用量。生成内容由调用方读取和保存。费用与计费模式留待后续实现。
