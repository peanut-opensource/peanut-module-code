<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\contract\http\OutboundHttpTransport;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use think\facade\Db;

final readonly class AiExecution
{
    public function __construct(private AiAccess $access, private AiSources $sources, private AiConfiguration $configuration, private AiPrivateStore $private, private AiQuota $quota, private AiProtocol $protocol, private AiOutputVerifier $verifier, private OutboundHttpTransport $http, private CurrentExecutionContext $current) {}
    public function execute(AuthorizedOperationContext $context, JobExecution $execution, int $id): void
    {
        $c = $context->tenantContext;
        if ($this->current->system()->tenantId !== $c->tenantId) {
            throw new \DomainException('AI_TASK_SYSTEM_SCOPE_INVALID');
        }
        $row = $this->access->find($c, 'task', $id, 'use');
        if ($row->job_key !== $execution->jobKey) {
            throw new \DomainException('AI_TASK_BINDING_INVALID');
        }
        $this->private->exclusive($c->tenantId, $id, function () use ($context, $execution, $id): array {
            $this->executeLocked($context, $execution, $id);
            return [];
        });
    }
    private function executeLocked(AuthorizedOperationContext $context, JobExecution $execution, int $id): void
    {
        $c = $context->tenantContext;
        $system = $this->current->system();
        if ($system->tenantId !== $c->tenantId) {
            throw new \DomainException('AI_TASK_SYSTEM_SCOPE_INVALID');
        }
        $execution->checkpoint();
        $r = $this->access->find($c, 'task', $id, 'use');
        $d = AiAccess::data($r);
        if ($r->job_key !== $execution->jobKey) {
            throw new \DomainException('AI_TASK_BINDING_INVALID');
        }
        if (isset($d['fixed_result']) || ($d['collection_state'] ?? '') === 'collected') {
            throw new \DomainException('AI_CANDIDATE_IMMUTABLE');
        }
        $fixed = AiAccess::decode($r->sealed_config);
        $source = AiAccess::decode($r->source_snapshot);
        $this->configuration->recheck($c, $fixed);
        $directory = $this->private->directory($c->tenantId, $id);
        try {
            $source = $this->sources->authorizeExternalSend($execution, $d['source_authorization'] ?? null, $source, $directory . '/input-audio.bin');
        } catch (\Throwable $error) {
            // Only a persisted pre-send state can release quota. A pending/unknown provider attempt is never downgraded.
            if (in_array($d['stage'], ['prepared','blocked_source_authorization'], true) && !isset($d['attempt_started_at']) && !isset($d['provider_id'])) {
                try {
                    $this->recordSourceBlock($execution, $id, $error instanceof BusinessException ? $error->errorCode : 'AI_SOURCE_AUTHORIZATION_DENIED');
                } catch (\Throwable) {
                    // If native identity/lease also vanished, keep the original not_sent fact and reserved quota for an authorized retry/cancel.
                    // No source content or provider action is permitted by this bookkeeping path.
                }
            }
            throw $error;
        }
        if ($d['stage'] === 'candidate') {
            $meta = $d['candidate'];
            $actual = $this->verifier->verify($directory . '/candidate.bin', $meta['result_type']);
            if ($actual['sha256'] !== $meta['sha256']) {
                throw new \RuntimeException('AI_CANDIDATE_CHANGED');
            }
            $execution->assertLeaseOwned();
            return;
        }
        $providerId = $d['provider_id'] ?? null;
        $metaPath = $directory . '/response-meta.json';
        $rawPath = $directory . '/provider-response.bin';
        $receipt = null;
        if (is_file($metaPath)) {
            $candidate = AiAccess::decode(file_get_contents($metaPath));
            if (($candidate['job_key'] ?? null) === $execution->jobKey && ($candidate['request_hash'] ?? null) === $r->request_hash && is_file($rawPath) && hash_file('sha256', $rawPath) === ($candidate['response_sha256'] ?? null)) {
                $receipt = $candidate;
            }
        }
        if ($receipt === null) {
            if ($providerId === null && in_array($d['stage'], ['sending','unknown'], true)) {
                $this->update($c, $execution, $id, function ($r, array &$d) use ($c): void {
                    $this->quota->settle($c, $r, $d, 'unknown');
                    $d['stage'] = 'unknown';
                    $d['usage'] = ['state' => 'unknown','source' => 'interrupted_after_send_intent','billing_amount' => null];
                }, 'unknown');
                throw new \RuntimeException('AI_PROVIDER_OUTCOME_UNKNOWN');
            }
            // Source grant checks now participate in the native checkpoint immediately before the provider boundary.
            $key = $this->configuration->revealCredential($c, $fixed);
            $safeId = 'ai_' . $id . '_' . substr(hash('sha256', $execution->jobKey . ':' . $r->request_hash), 0, 32);
            $request = $this->protocol->request($fixed, $source, $source['instruction'], $key, $safeId, $rawPath, $directory . '/input-audio.bin', $providerId);
            unset($key);
            $execution->checkpoint();
            $this->update($c, $execution, $id, function ($r, array &$data) use ($providerId, $execution, $safeId): void {
                $data['stage'] = $providerId === null ? 'sending' : 'provider_pending';
                $data['attempt_job_key'] = $execution->jobKey;
                $data['safe_request_id'] = $safeId;
                $data['attempt_started_at'] = gmdate('c');
            }, $providerId === null ? 'sending' : 'provider_pending');
            try {
                $response = $this->http->send($request);
            } catch (\Throwable $error) {
                $execution->assertLeaseOwned();
                $this->update($c, $execution, $id, function ($r, array &$data) use ($c, $providerId): void {
                    if ($providerId === null) {
                        $this->quota->settle($c, $r, $data, 'unknown');
                        $data['stage'] = 'unknown';
                        $data['usage'] = ['state' => 'unknown','source' => 'no_confirmed_http_response','billing_amount' => null];
                    } else {
                        $data['stage'] = 'provider_pending';
                        $data['execution_block'] = 'AI_PROVIDER_POLL_UNCONFIRMED';
                    }
                }, $providerId === null ? 'unknown' : 'provider_pending');
                throw new \RuntimeException($providerId === null ? 'AI_PROVIDER_OUTCOME_UNKNOWN' : 'AI_PROVIDER_POLL_UNCONFIRMED');
            } finally {
                foreach ($request->multipart as $part) {
                    if (is_resource($part['contents'])) {
                        fclose($part['contents']);
                    }
                }if ($request->sink !== null) {
                    BoundedResponseSink::release($request->sink);
                }
            }
            if (!is_file($rawPath)) {
                throw new \RuntimeException('AI_RESPONSE_MISSING');
            }
            chmod($rawPath, 0600);
            $headers = array_intersect_key($response->headers, array_flip(['content-type','x-request-id','x-goog-request-id']));
            foreach ($headers as $name => $value) {
                $headers[$name] = substr($value, 0, 256);
            }
            $receipt = ['job_key' => $execution->jobKey,'request_hash' => $r->request_hash,'status' => $response->status,'headers' => $headers,'response_sha256' => hash_file('sha256', $rawPath)];
            $this->private->write($directory, 'response-meta.json', AiAccess::json($receipt));
        }
        $execution->checkpoint();
        try {
            $result = $this->protocol->parse($fixed['connection']['details']['protocol'], $rawPath, $receipt['headers'], $receipt['status']);
        } catch (\Throwable) {
            $this->update($c, $execution, $id, function ($r, array &$data) use ($c): void {
                $this->quota->settle($c, $r, $data, 'confirmed');
                $data['stage'] = 'response_unusable';
                $data['execution_block'] = 'AI_PROVIDER_RESPONSE_UNUSABLE';
                $data['usage'] = ['state' => 'unknown','source' => 'unusable_provider_response','billing_amount' => null];
            }, 'failed');
            throw new \RuntimeException('AI_PROVIDER_RESPONSE_UNUSABLE');
        }
        if ($result['outcome'] !== 'candidate') {
            $this->update($c, $execution, $id, function ($r, array &$data) use ($c, $result): void {
                $this->quota->settle($c, $r, $data, 'confirmed');
                $data['stage'] = $result['outcome'] === 'pending' ? 'provider_pending' : 'provider_rejected';
                $data['usage'] = $result['usage'];
                $data['provider_request_id'] = $result['provider_request_id'] ?? null;
                $data['provider_id'] = $result['provider_id'] ?? null;
                $data['provider_status'] = $result['provider_status'] ?? null;
            }, $result['outcome'] === 'pending' ? 'provider_pending' : 'failed');
            return;
        }
        if (isset($result['binary_path'])) {
            $bytes = file_get_contents($result['binary_path']);
        } else {
            $bytes = $result['bytes'] ?? $result['text'];
        }
        $this->private->write($directory, 'candidate.bin', $bytes);
        unset($bytes);
        $verified = $this->verifier->verify($directory . '/candidate.bin', $result['result_type']);
        $privateMeta = $verified + ['job_key' => $execution->jobKey,'request_hash' => $r->request_hash,'result_source' => 'provider_response','segments' => $result['segments'] ?? [],'segments_source' => $result['segments_source'] ?? 'not_applicable'];
        $execution->assertLeaseOwned();
        $this->private->write($directory, 'candidate-meta.json', AiAccess::json($privateMeta));
        $this->update($c, $execution, $id, function ($r, array &$data) use ($c, $result, $verified): void {
            $this->quota->settle($c, $r, $data, 'confirmed');
            $data['stage'] = 'candidate';
            $data['candidate'] = $verified;
            $data['usage'] = $result['usage'];
            $data['provider_request_id'] = $result['provider_request_id'] ?? null;
            $data['provider_id'] = $result['provider_id'] ?? null;
            $data['result_source'] = 'provider_response';
        }, 'awaiting_collection');
        // Native Task owns its final fence/succeeded. No File, visible text, adoption or approval occurs here.
    }

    /** Release only demonstrably unstarted work, using the real native task fence without borrowing revoked source authority. */
    private function recordSourceBlock(JobExecution $execution, int $id, string $code): void
    {
        $tasks = app()->make(\PeanutAdmin\Modules\Task\Contract\TaskSourceAuthorizations::class);
        $binding = new \PeanutAdmin\Modules\Task\Contract\SourceTaskBinding($execution->jobKey, 'custom.ai-assistant', 'custom.ai-assistant.task', 'use', 'custom.ai-assistant.generate');
        $c = $tasks->sourceActor($execution, $binding);
        Db::transaction(function () use ($tasks, $binding, $c, $execution, $id, $code): void {
            $r = $this->access->find($c, 'task', $id, 'use', true);
            $d = AiAccess::data($r);
            if ($r->job_key !== $execution->jobKey || ($execution->payload['task_id'] ?? null) !== $id
                || !in_array($d['stage'], ['prepared','blocked_source_authorization'], true)
                || isset($d['attempt_started_at']) || isset($d['provider_id'])) {
                throw new \DomainException('AI_SOURCE_BLOCK_STATE_CHANGED');
            }
            $this->quota->settle($c, $r, $d, 'not_sent');
            $d['stage'] = 'blocked_source_authorization';
            $d['execution_block'] = $code;
            $d['usage'] = ['state' => 'not_sent','source' => 'blocked_before_provider_attempt','billing_amount' => null];
            $this->access->update($r, $d, 'blocked_source_authorization');
            $this->access->event(
                $c,
                'task',
                $r,
                'execution',
                'source-block:' . hash('sha256', $execution->jobKey . ':' . $execution->attemptNumber),
                [$execution->jobKey,$code],
                ['stage' => $d['stage'],'usage' => $d['usage'],'execution_block' => $code],
            );
            $tasks->sourceActor($execution, $binding);
        });
    }

    public function verifyManual(AuthorizedOperationContext $context, JobExecution $execution, int $id): void
    {
        $c = $context->tenantContext;
        if ($this->current->system()->tenantId !== $c->tenantId) {
            throw new \DomainException('AI_TASK_SYSTEM_SCOPE_INVALID');
        }
        $row = $this->access->find($c, 'task', $id, 'use');
        if ($row->job_key !== $execution->jobKey) {
            throw new \DomainException('AI_TASK_BINDING_INVALID');
        }
        $this->private->exclusive($c->tenantId, $id, function () use ($context, $execution, $id): array {
            $this->verifyManualLocked($context, $execution, $id);
            return [];
        });
    }
    private function verifyManualLocked(AuthorizedOperationContext $context, JobExecution $execution, int $id): void
    {
        $c = $context->tenantContext;
        if ($this->current->system()->tenantId !== $c->tenantId) {
            throw new \DomainException('AI_TASK_SYSTEM_SCOPE_INVALID');
        }
        $execution->checkpoint();
        $r = $this->access->find($c, 'task', $id, 'use');
        $d = AiAccess::data($r);
        if ($r->job_key !== $execution->jobKey || !in_array($d['stage'], ['manual_candidate_prepared','candidate'], true) || !isset($d['manual_input'])) {
            throw new \DomainException('AI_MANUAL_TASK_BINDING_INVALID');
        }
        $dir = $this->private->directory($c->tenantId, $id);
        $verified = $this->verifier->verify($dir . '/candidate.bin', $d['manual_input']['result_type']);
        if ($verified['sha256'] !== $d['manual_input']['sha256']) {
            throw new \RuntimeException('AI_MANUAL_CANDIDATE_CHANGED');
        }
        $execution->assertLeaseOwned();
        if ($d['stage'] === 'candidate') {
            return;
        }
        $this->private->write($dir, 'candidate-meta.json', AiAccess::json($verified + ['job_key' => $execution->jobKey,'request_hash' => $r->request_hash,'result_source' => $d['manual_input']['source'],'provider_verified' => false]));
        $this->update($c, $execution, $id, function ($r, array &$data) use ($verified): void {
            $data['stage'] = 'candidate';
            $data['candidate'] = $verified;
            $data['manual_verification'] = ['kind' => 'local_bytes_only','provider_call' => false,'provider_verified' => false];
        }, 'awaiting_collection');
    }
    private function update($c, JobExecution $execution, int $id, callable $change, string $status): void
    {
        $execution->assertLeaseOwned();
        Db::transaction(function () use ($c, $execution, $id, $change, $status): void {
            $r = $this->access->find($c, 'task', $id, 'use', true);
            if ($r->job_key !== $execution->jobKey) {
                throw new \DomainException('AI_TASK_BINDING_INVALID');
            }$d = AiAccess::data($r);
            $change($r, $d);
            $this->access->update($r, $d, $status);
            $this->access->event($c, 'task', $r, 'execution', 'execution:' . hash('sha256', $execution->jobKey . ':' . $r->version), [$execution->jobKey,$d['stage']], ['stage' => $d['stage'],'usage' => $d['usage'],'execution_block' => $d['execution_block'] ?? null]);
            $execution->assertLeaseOwned();
        });
    }
}
