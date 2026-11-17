<?php

declare(strict_types=1);

namespace Custom\Modules\AiAccess\Service;

use app\common\contract\http\OutboundHttpTransport;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\common\value\http\OutboundHttpRequest;
use Custom\Modules\AiAccess\Contract\AiClient;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\BaseQuery;
use think\db\exception\DuplicateException;
use think\facade\Db;

final readonly class ProviderClient implements AiClient
{
    private const RESPONSE_LIMIT = 33554432;

    public function __construct(
        private Catalog $catalog,
        private CurrentExecutionContext $execution,
        private ModuleExecutionBoundary $modules,
        private OutboundHttpTransport $http,
    ) {}

    public function generateText(string $prompt, string $requestKey, ?string $model = null, ?string $provider = null, ?string $credential = null): array
    {
        return $this->generate('text', $prompt, $requestKey, $model, $provider, $credential);
    }

    public function generateImage(string $prompt, string $requestKey, ?string $model = null, ?string $provider = null, ?string $credential = null): array
    {
        return $this->generate('image', $prompt, $requestKey, $model, $provider, $credential);
    }

    public function requestStatus(string $requestKey): array
    {
        $tenant = $this->tenant();
        $this->requests($tenant, $requestKey)->where('status', 'sending')
            ->whereRaw('created_at < CURRENT_TIMESTAMP - INTERVAL 150 SECOND')
            ->update(['status' => 'unknown']);
        $row = $this->requests($tenant, $requestKey)->find();
        if ($row === null) {
            throw BusinessException::notFound('AI_ACCESS_REQUEST_NOT_FOUND', '未找到该请求');
        }
        return $this->receipt($row);
    }

    private function generate(string $capability, string $prompt, string $requestKey, ?string $model, ?string $provider, ?string $credential): array
    {
        $tenant = $this->tenant();
        $length = mb_strlen($prompt);
        if (trim($prompt) === '' || $length > 200000 || !mb_check_encoding($prompt, 'UTF-8')) {
            throw BusinessException::invalid('AI_ACCESS_INPUT_INVALID', '请输入有效的生成内容');
        }
        foreach ([$model, $provider, $credential] as $key) {
            if ($key !== null) {
                $this->catalog->identifier($key);
            }
        }
        if (Db::connect()->getPdo()?->inTransaction()) {
            throw BusinessException::conflict('AI_ACCESS_TRANSACTION_ACTIVE', 'AI 外发须在业务事务之外执行');
        }
        $request = $this->requests($tenant, $requestKey);
        if ($request->find() !== null) {
            throw BusinessException::conflict('AI_ACCESS_REQUEST_EXISTS', '该请求已提交，请查询原请求状态');
        }
        $sink = tempnam(sys_get_temp_dir(), 'peanut-ai-');
        if ($sink === false) {
            throw new \RuntimeException('AI_ACCESS_RESPONSE_FILE_UNAVAILABLE');
        }

        $sinkUri = null;
        try {
            $sinkUri = ResponseSink::uri($sink);
            [$outbound, $receipt] = Db::transaction(function () use ($tenant, $requestKey, $capability, $length, $prompt, $model, $provider, $credential, $sinkUri): array {
                [$endpoint, $selectedModel, $token] = $this->select($tenant, $capability, $length, $model, $provider, $credential);
                $outbound = $this->outbound($capability, $prompt, $requestKey, $endpoint, $selectedModel, $this->catalog->reveal($tenant, $token), $sinkUri);
                $receipt = [
                    'request_key' => $requestKey, 'provider_key' => $endpoint['entry_key'],
                    'model_key' => $selectedModel['entry_key'], 'credential_key' => $token['entry_key'],
                    'capability' => $capability, 'status' => 'sending', 'provider_request_id' => null, 'usage_json' => null,
                ];
                try {
                    Db::name('ai_access_request')->insert($receipt + [
                        'tenant_id' => $tenant->tenantId, 'member_id' => $tenant->memberId,
                    ]);
                } catch (DuplicateException) {
                    throw BusinessException::conflict('AI_ACCESS_REQUEST_EXISTS', '该请求已提交，请查询原请求状态');
                }
                if ($credential === null) {
                    $order = $this->catalog->query($tenant, 'credential')
                        ->where('provider', $endpoint['entry_key'])->max('selection_order');
                    $this->catalog->query($tenant, 'credential')->where('id', $token['id'])
                        ->update(['selection_order' => (int) $order + 1]);
                }
                return [$outbound, $receipt];
            });

            try {
                $response = $this->http->send($outbound);
                $remoteRequest = $response->headers['x-request-id'] ?? $response->headers['request-id'] ?? '';
                $receipt['provider_request_id'] = preg_match('/^[A-Za-z0-9_:.\/-]{1,180}$/D', $remoteRequest) ? $remoteRequest : null;
                if ($response->status < 200 || $response->status >= 300) {
                    $receipt['status'] = $response->status >= 500 || $response->status === 408 ? 'unknown' : 'rejected';
                    throw BusinessException::conflict('AI_ACCESS_PROVIDER_REJECTED', '服务商未返回成功结果，请查询原请求');
                }
                $size = filesize($sink);
                if ($size === false || $size < 2 || $size > self::RESPONSE_LIMIT) {
                    throw new \UnexpectedValueException('AI_ACCESS_RESULT_SIZE_INVALID');
                }
                $payload = json_decode(file_get_contents($sink), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new \UnexpectedValueException('AI_ACCESS_RESULT_INVALID');
                }
                $usage = array_filter(array_intersect_key(
                    is_array($payload['usage'] ?? null) ? $payload['usage'] : [],
                    array_flip(['prompt_tokens', 'completion_tokens', 'input_tokens', 'output_tokens', 'total_tokens']),
                ), static fn (mixed $value): bool => is_int($value) && $value >= 0);
                $receipt['usage_json'] = $usage === [] ? null : json_encode($usage, JSON_THROW_ON_ERROR);
                $result = $this->result($capability, $payload);
                $receipt['status'] = 'succeeded';
            } catch (BusinessException $exception) {
                throw $exception;
            } catch (\Throwable) {
                throw BusinessException::conflict('AI_ACCESS_RESULT_UNKNOWN', '调用结果未确认，请查询原请求状态');
            } finally {
                $this->requests($tenant, $requestKey)->update([
                    'status' => $receipt['status'] === 'sending' ? 'unknown' : $receipt['status'],
                    'provider_request_id' => $receipt['provider_request_id'], 'usage_json' => $receipt['usage_json'],
                ]);
            }

            return $result + ['request' => $this->receipt($receipt)];
        } finally {
            if ($sinkUri !== null) {
                ResponseSink::release($sinkUri);
            }
            unlink($sink);
        }
    }

    private function select(TenantContext $tenant, string $capability, int $length, ?string $model, ?string $provider, ?string $credential): array
    {
        $providers = $this->catalog->query($tenant, 'provider')->where('enabled', 1);
        if ($provider !== null) {
            $providers->where('entry_key', $provider);
        }
        if ($credential !== null && $provider === null) {
            $owners = $this->catalog->query($tenant, 'credential')->where('entry_key', $credential)->where('enabled', 1)
                ->limit(2)->column('provider');
            if (count($owners) > 1) {
                throw BusinessException::invalid('AI_ACCESS_CREDENTIAL_AMBIGUOUS', '多个服务商有此令牌标识，请同时指定服务商');
            }
            $providers->where('entry_key', $owners[0] ?? '');
        }

        foreach ($providers->order('priority', 'desc')->order('entry_key')->select() as $candidate) {
            $key = $candidate['entry_key'];
            // Configure and choose credentials under the same provider lock.
            $endpoint = $this->catalog->query($tenant, 'provider')->where('entry_key', $key)
                ->where('enabled', 1)->lock(true)->find();
            if ($endpoint === null || !$this->catalog->isApprovedOrigin($this->catalog->details($endpoint)['origin'])) {
                continue;
            }
            $models = $this->catalog->query($tenant, 'model')->where('provider', $key)->where('enabled', 1);
            if ($model !== null) {
                $models->where('entry_key', $model);
            }
            $tokens = $this->catalog->query($tenant, 'credential')->where('provider', $key)->where('enabled', 1)
                ->whereNotNull('secret_payload');
            if ($credential !== null) {
                $tokens->where('entry_key', $credential);
            }
            $available = $tokens->order('selection_order')->order('entry_key')->lock(true)->select();
            foreach ($models->order('priority', 'desc')->order('entry_key')->lock(true)->select() as $choice) {
                $details = $this->catalog->details($choice);
                if (!isset($details['protocols'][$capability]) || $details['input_limit_chars'] < $length) {
                    continue;
                }
                foreach ($available as $token) {
                    $scope = $this->catalog->details($token)['models'];
                    if ($scope === ['*'] || in_array($choice['entry_key'], $scope, true)) {
                        return [$endpoint, $choice, $token];
                    }
                }
            }
        }

        throw BusinessException::conflict('AI_ACCESS_TARGET_UNAVAILABLE', '没有符合条件的服务商、模型及可用令牌');
    }

    private function outbound(string $capability, string $prompt, string $requestKey, array $endpoint, array $model, string $secret, string $sink): OutboundHttpRequest
    {
        $details = $this->catalog->details($model);
        $address = $this->catalog->details($endpoint);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Client-Request-Id' => $requestKey];
        $body = ['model' => $details['remote_model']];
        $protocol = $details['protocols'][$capability];
        if ($protocol === 'anthropic_messages') {
            $headers += ['x-api-key' => $secret, 'anthropic-version' => '2023-06-01'];
            $path = '/messages';
            $body += ['messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => $details['max_output_tokens']];
        } else {
            $headers['Authorization'] = 'Bearer ' . $secret;
            if ($capability === 'image') {
                $path = '/images/generations';
                $body += ['prompt' => $prompt, 'n' => 1, 'size' => '1024x1024', 'output_format' => 'png'];
            } else {
                $path = '/chat/completions';
                $body += ['messages' => [['role' => 'user', 'content' => $prompt]], 'max_completion_tokens' => $details['max_output_tokens']];
            }
        }

        return new OutboundHttpRequest('POST', $address['origin'] . $address['api_prefix'] . $path, $headers,
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            connectTimeoutSeconds: 5, timeoutSeconds: 120, sink: $sink);
    }

    private function result(string $capability, array $payload): array
    {
        if ($capability === 'image') {
            $encoded = $payload['data'][0]['b64_json'] ?? null;
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($bytes === false || (@getimagesizefromstring($bytes)['mime'] ?? null) !== 'image/png') {
                throw new \UnexpectedValueException('AI_ACCESS_IMAGE_INVALID');
            }
            return ['image' => $bytes, 'mime_type' => 'image/png'];
        }

        $text = $payload['choices'][0]['message']['content'] ?? null;
        if ($text === null && ($payload['type'] ?? null) === 'message' && is_array($payload['content'] ?? null)) {
            $parts = [];
            foreach ($payload['content'] as $block) {
                if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $parts[] = $block['text'];
                }
            }
            $text = implode("\n", $parts);
        }
        if (!is_string($text) || trim($text) === '') {
            throw new \UnexpectedValueException('AI_ACCESS_TEXT_INVALID');
        }
        return ['text' => $text];
    }

    private function tenant(): TenantContext
    {
        $tenant = $this->execution->tenantAdmin();
        $this->modules->assertHttp('custom.ai-access', 'service.generate');
        if (!$this->catalog->allowed($tenant, 'use')) {
            throw BusinessException::forbidden('AI_ACCESS_PERMISSION_DENIED', '当前成员无权调用 AI 服务');
        }
        return $tenant;
    }

    private function requests(TenantContext $tenant, string $key): BaseQuery
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $key)) {
            throw BusinessException::invalid('AI_ACCESS_REQUEST_KEY_INVALID', '请求标识无效');
        }
        return Db::name('ai_access_request')->where('tenant_id', $tenant->tenantId)
            ->where('member_id', $tenant->memberId)->where('request_key', $key);
    }

    private function receipt(array $row): array
    {
        $value = array_intersect_key($row, array_flip([
            'request_key', 'provider_key', 'model_key', 'credential_key', 'capability', 'status', 'provider_request_id',
        ]));
        $value['usage'] = $row['usage_json'] === null ? null : json_decode($row['usage_json'], true, 16, JSON_THROW_ON_ERROR);
        return $value;
    }
}
