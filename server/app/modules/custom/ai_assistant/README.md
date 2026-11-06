# AI 文本生成

模块标识为 `custom.ai-assistant`。本模块由 `peanut-module-code` 维护。消费应用从已校验的独立模块包安装或升级。

## 管理端配置

1. 在 AI 助手中新建服务商连接。选择 OpenAI 兼容 Chat Completions 或 Anthropic Messages，分别填写 HTTPS 服务地址和 API 前缀，例如 `https://provider.example` 和 `/v1`。
2. 用连接详情中的凭据操作保存密钥，再启用连接。应用使用本实例原生 Settings 密钥环加密，密钥绑定租户和连接。更改地址、前缀或协议后须重新绑定。
3. 创建并启用文本模型。填写服务商实际提供的模型标识、输入字符限制和输出 token 限制。日请求额度在连接上设置。
4. 创建并启用文本模板和文本路由，将路由绑定模型、模板与使用方模块标识。不同使用方可以选择不同路由。

## 其他模块如何调用

编辑器在当前成员确认后提交本次输入的主题、提纲和获准路由。HTTP 入口是 `POST /adminapi/custom.ai-assistant.generateText`。有写稿权限的文章模块可以在可信租户会话中调用相同的导出契约。

```php
$result = app()->make(\Custom\Modules\AiAssistant\Contract\AiTextGeneration::class)
    ->generateText([
        'route_id' => $routeId,
        'consumer_module' => 'peanut.article',
        'topic' => $topic,
        'outline' => $outline,
        'request_id' => $requestId,
        'confirm_send' => true,
    ]);
```

结果包含 `candidate_id`、`request_id` 和 `text`。使用方预览候选，让编辑明确采用，并沿本模块已有的草稿版本及保存流程处理。响应丢失时保留原请求编号；发送结果不明时，重复请求不会再次调用提供方。该交互只发送表单输入。读取已保存稿件或固定附件后后台外发仍受原授权合同约束。

开发机可执行 `php docs/development/probes/ai-text-protocols-20261106.php` 核验两种协议的消息结构与解析。用户密钥、实例连接信息与真实稿件均由采用该包的实例管理。

参考实现：[Vercel AI SDK](https://github.com/vercel/ai) 和 [LiteLLM](https://github.com/BerriAI/litellm)。
