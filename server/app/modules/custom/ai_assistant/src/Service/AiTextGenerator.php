<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\contract\http\OutboundHttpTransport;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use Custom\Modules\AiAssistant\Model\TextGeneration;
use Custom\Modules\AiAssistant\Validation\AiInput as I;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;

final readonly class AiTextGenerator
{
    public function __construct(
        private CurrentExecutionContext $execution,
        private ModuleExecutionBoundary $modules,
        private AiAccess $access,
        private AiConfiguration $configuration,
        private AiQuota $quota,
        private AiProtocol $protocol,
        private OutboundHttpTransport $http,
    ) {}

    public function generate(array $input): array
    {
        $c = $this->execution->tenantAdmin();
        $this->modules->assertHttp('custom.ai-assistant', 'http.admin');
        $this->access->authorize($c, 'use');
        if (Db::connect()->getPdo()?->inTransaction()) {
            throw BusinessException::conflict('AI_OUTSIDE_TRANSACTION_REQUIRED', '生成文本须在业务写入事务之外办理');
        }
        I::only($input, ['route_id', 'consumer_module', 'topic', 'outline', 'request_id', 'confirm_send']);
        if (($input['confirm_send'] ?? null) !== true) {
            throw BusinessException::invalid('AI_CONFIRM_SEND_REQUIRED', '须确认将本次输入发送给所选服务');
        }
        $route = I::integer($input['route_id'] ?? null);
        $consumer = I::consumerModule($input['consumer_module'] ?? null);
        $topic = I::text($input['topic'] ?? null, 2000);
        $outline = I::text($input['outline'] ?? '', 4000, false);
        $requestId = I::requestId($input['request_id'] ?? null);
        $hash = AiAccess::hash([$route, $consumer, $topic, $outline]);
        $fixed = $this->configuration->snapshot($c, $route);
        if ($fixed['route']['details']['capability'] !== 'text' || $fixed['route']['details']['consumer_module'] !== $consumer) {
            throw BusinessException::invalid('AI_ROUTE_SCOPE_DENIED', '须选择与使用模块匹配的文本能力路由');
        }
        $binding = [];
        foreach (['connection','model','route','template'] as $kind) {
            $binding[$kind] = ['id' => $fixed[$kind]['id'], 'version' => $fixed[$kind]['version']];
        }
        $existing = $this->find($c, $requestId);
        if ($existing !== null) {
            return $this->receipt($existing, $hash, $binding);
        }

        $prompt = "主题：\n" . $topic . ($outline === '' ? '' : "\n\n提纲：\n" . $outline);
        $path = tempnam(sys_get_temp_dir(), 'ai-text-');
        if ($path === false) {
            throw new \RuntimeException('AI_RESPONSE_STORAGE_UNAVAILABLE');
        }
        try {
            try {
                $key = $this->configuration->revealCredential($c, $fixed);
            } catch (\PeanutAdmin\Settings\Secret\SecretProtectionException) {
                throw BusinessException::conflict('AI_SECRET_PROTECTOR_UNAVAILABLE', '连接凭据暂不可用，请联系管理员');
            }
            $safeId = 'ai_' . substr(AiAccess::hash([$c->tenantId, $c->memberId, $requestId]), 0, 40);
            $request = $this->protocol->request($fixed, ['input_kind' => 'member_text', 'text' => $prompt], '', $key, $safeId, $path);
            // Commit the unique send intent and its quota before making the non-retryable POST.
            try {
                $claimed = Db::transaction(function () use ($c, $fixed, $binding, $consumer, $requestId, $hash) {
                    $locks = $binding;
                    uasort($locks, static fn($a, $b) => $a['id'] <=> $b['id']);
                    foreach ($locks as $kind => $ref) {
                        $row = $this->access->find($c, $kind, $ref['id'], 'use', true);
                        if ($row->status !== 'enabled' || (int) $row->version !== $ref['version']) {
                            throw BusinessException::conflict('AI_CONFIGURATION_CHANGED', '配置已修订，请刷新后重新选择');
                        }
                    }
                    $this->configuration->recheck($c, $fixed);
                    if ($this->find($c, $requestId) !== null) {
                        return false;
                    }
                    $record = new TextGeneration();
                    $details = ['configuration' => $binding, 'consumer_module' => $consumer];
                    $record->save(['created_by' => $c->memberId, 'request_id' => $requestId, 'request_hash' => $hash,
                        'status' => 'sending', 'details' => AiAccess::json($details), 'created_at' => time(), 'updated_at' => time()]);
                    $details['quota'] = $this->quota->reserve($c, $fixed['connection'], $record);
                    $record->save(['details' => AiAccess::json($details)]);
                    return true;
                });
            } catch (BusinessException $error) {
                throw $error;
            } catch (\Throwable $error) {
                // Two different route locks may race on the same member request. The database unique key decides.
                if (($existing = $this->find($c, $requestId)) !== null) {
                    return $this->receipt($existing, $hash, $binding);
                }
                throw $error;
            }
            if (!$claimed) {
                return $this->receipt($this->find($c, $requestId), $hash, $binding);
            }

            try {
                $response = $this->http->send($request);
            } catch (\Throwable) {
                $this->finish($c, $requestId, 'unknown', 'unknown', []);
                throw BusinessException::conflict('AI_UNKNOWN_NO_RESEND', '服务未返回可确认的结果，原请求将保留且不会重发');
            }
            try {
                $result = $this->protocol->parse($fixed['connection']['details']['protocol'], $path, $response->headers, $response->status);
                if (isset($result['text']) && str_contains($result['text'], $key)) {
                    throw new \UnexpectedValueException('AI_RESPONSE_UNSAFE');
                }
            } catch (\Throwable) {
                $this->finish($c, $requestId, 'unusable', 'confirmed', ['http_status' => $response->status]);
                throw BusinessException::conflict('AI_PROVIDER_RESPONSE_UNUSABLE', '提供方响应不含可用的文本候选');
            }
            $result['http_status'] = $response->status;
            if (isset($result['provider_request_id']) && str_contains($result['provider_request_id'], $key)) {
                unset($result['provider_request_id']);
            }
            $status = $result['outcome'] === 'candidate' ? 'candidate' : 'rejected';
            $record = $this->finish($c, $requestId, $status, 'confirmed', $result);
            $this->modules->assertHttp('custom.ai-assistant', 'http.admin');
            $this->configuration->recheck($c, $fixed);
            return $this->receipt($record, $hash, $binding);
        } finally {
            if (isset($request) && $request->sink !== null) {
                BoundedResponseSink::release($request->sink);
            }
            unlink($path);
        }
    }

    private function find(TenantContext $c, string $request, bool $lock = false): ?TextGeneration
    {
        return TextGeneration::where('tenant_id', $c->tenantId)->where('created_by', $c->memberId)
            ->where('request_id', $request)->lock($lock)->find();
    }

    private function finish(TenantContext $c, string $request, string $status, string $outcome, array $result): TextGeneration
    {
        return Db::transaction(function () use ($c, $request, $status, $outcome, $result) {
            $row = $this->find($c, $request, true);
            if ($row === null || $row->status !== 'sending') {
                throw new \RuntimeException('AI_TEXT_INTENT_MISSING');
            }
            $d = AiAccess::data($row);
            $this->quota->settle($c, $row, $d, $outcome);
            $d['usage'] = $result['usage'] ?? ['state' => 'unknown'];
            $d['http_status'] = $result['http_status'] ?? null;
            $d['provider_request_id'] = $result['provider_request_id'] ?? null;
            $row->save(['candidate' => $result['text'] ?? null, 'details' => AiAccess::json($d),
                'status' => $status, 'updated_at' => time()]);
            return $row;
        });
    }

    private function receipt(TextGeneration $record, string $hash, array $binding): array
    {
        if (!hash_equals($record->request_hash, $hash)) {
            throw BusinessException::conflict('AI_REQUEST_CONFLICT', '同一请求标识对应不同的输入或路由');
        }
        if (AiAccess::hash(AiAccess::data($record)['configuration']) !== AiAccess::hash($binding)) {
            throw BusinessException::conflict('AI_CONFIGURATION_CHANGED', '原请求绑定的配置已修订');
        }
        return match ($record->status) {
            'candidate' => ['candidate_id' => (int) $record->id, 'request_id' => $record->request_id, 'text' => $record->candidate],
            'rejected' => throw BusinessException::conflict('AI_PROVIDER_REJECTED', '提供方拒绝了本次请求，请检查模型和连接配置'),
            'unusable' => throw BusinessException::conflict('AI_PROVIDER_RESPONSE_UNUSABLE', '提供方响应不含可用的文本候选'),
            default => throw BusinessException::conflict('AI_UNKNOWN_NO_RESEND', '发送已登记，请沿原请求核对结果'),
        };
    }
}
