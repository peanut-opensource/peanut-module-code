<?php

declare(strict_types=1);

use Custom\Modules\AiAssistant\Service\AiProtocol;

$root = dirname(__DIR__, 3);
require $root . '/server/vendor/autoload.php';
$protocol = new AiProtocol();
$tmp = tempnam(sys_get_temp_dir(), 'media-ai-protocol-');
$fixed = ['connection' => ['details' => ['protocol' => 'openai_text', 'origin' => 'https://text.example.invalid', 'api_prefix' => '/v1']],
    'model' => ['details' => ['provider_model_id' => 'dev-model-a', 'max_output_tokens' => 350, 'input_limit_chars' => 1000]],
    'template' => ['details' => ['instructions' => '列出可核对的事实。']]];
$source = ['text' => 'DEV：图书馆举办阅读活动。'];
function verify(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
try {
    $request = $protocol->request($fixed, $source, '写成简讯', 'DEV-compat-key', 'dev-request-1', $tmp);
    $body = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
    verify($request->url === 'https://text.example.invalid/v1/chat/completions' && $request->headers['Authorization'] === 'Bearer DEV-compat-key' && $body['model'] === 'dev-model-a', 'compatible provider uses configured origin, model and credential');
    $fixed['connection']['details'] = ['protocol' => 'anthropic_messages', 'origin' => 'https://messages.example.invalid', 'api_prefix' => '/v1'];
    $fixed['model']['details']['provider_model_id'] = 'dev-model-b';
    $request = $protocol->request($fixed, $source, '写成简讯', 'DEV-messages-key', 'dev-request-2', $tmp);
    $body = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
    verify($request->url === 'https://messages.example.invalid/v1/messages' && $request->headers['x-api-key'] === 'DEV-messages-key' && !isset($request->headers['Authorization']) && $request->headers['anthropic-version'] === '2023-06-01', 'Messages uses its own endpoint and header');
    verify($body['model'] === 'dev-model-b' && $body['system'] === $fixed['template']['details']['instructions'] && $body['messages'][0]['role'] === 'user' && $body['max_tokens'] === 350, 'Messages body follows selected model and template');
    $reply = ['id' => 'msg_dev','type' => 'message','role' => 'assistant','stop_reason' => 'end_turn','content' => [['type' => 'text','text' => '第一段。'],['type' => 'text','text' => '第二段。']], 'usage' => ['input_tokens' => 11,'output_tokens' => 9]];
    file_put_contents($tmp, json_encode($reply, JSON_THROW_ON_ERROR));
    $output = $protocol->parse('anthropic_messages', $tmp, ['content-type' => 'application/json','request-id' => 'dev_trace'], 200);
    verify($output['text'] === "第一段。\n第二段。" && $output['provider_request_id'] === 'dev_trace' && $output['usage']['values']['output_tokens']['value'] === 9, 'provider text blocks, request identity and usage read back');
    foreach ([['stop_reason' => 'refusal'],['stop_reason' => 'max_tokens'],['content' => [['type' => 'tool_use']]]] as $change) {
        file_put_contents($tmp, json_encode(array_replace($reply, $change), JSON_THROW_ON_ERROR));
        try { $protocol->parse('anthropic_messages', $tmp, ['content-type' => 'application/json'], 200); }
        catch (RuntimeException $e) {
            if ($e->getMessage() === 'AI_TEXT_RESPONSE_NOT_CANDIDATE') continue;
            throw $e;
        }
        throw new RuntimeException('Accepted incomplete, refused or tool output');
    }
    echo "PASS refused, incomplete and tool results cannot become article text\n";
} finally {
    unlink($tmp);
}
