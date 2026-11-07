<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use Custom\Modules\AiAssistant\Model\GenerationTask;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\{AuthorizationDecision,AuthorizedOperationContext};
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskJobService;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use think\facade\Db;

final readonly class AiTasks
{
    public function __construct(private AiAccess $access, private AiConfiguration $configuration, private AiSources $sources, private AiQuota $quota, private AiPrivateStore $private, private TaskJobRuntime $native, private AiTaskAuthorization $authorization) {}
    public function create(TenantContext $c, string $title, array $input, string $request): array
    {
        $hash = AiAccess::hash([$c->tenantId,$c->memberId,$title,$input]);
        $old = $this->existing($c, $request, $hash);
        if ($old !== null && $old->status !== 'preparing') {
            return AiAccess::present($old);
        }
        if ($old === null) {
            $fixed = $this->configuration->snapshot($c, $input['route_id']);
            $capability = $fixed['route']['details']['capability'];
            if ($fixed['route']['details']['consumer_module'] !== $input['consumer_module'] && $fixed['route']['details']['consumer_module'] !== 'general') {
                throw BusinessException::invalid('AI_ROUTE_SCOPE_DENIED', '路由不用于本次制作模块');
            }
            $this->reworkSource($c, $input, $fixed, false);
            $prepared = $this->sources->prepare($c, $input['document_id'], $input['revision_id'], $capability);
            $prepared['private']['instruction'] = $input['instruction'];
            if (mb_strlen($prepared['private']['text']) > $fixed['model']['details']['input_limit_chars']) {
                throw BusinessException::invalid('AI_INPUT_LIMIT', '固定源文字超出所选模型的机构限额');
            }
            if (isset($input['repeat_of_task_id'])) {
                $previous = $this->access->find($c, 'task', $input['repeat_of_task_id'], 'use');
                $pd = AiAccess::data($previous);
                if (!in_array($pd['stage'] ?? '', ['sending','unknown'], true) || !$input['confirm_possible_duplicate_cost']) {
                    throw BusinessException::invalid('AI_DUPLICATE_RISK_CONFIRMATION_REQUIRED', '未知任务另发必须明确确认可能重复消耗');
                }
            } elseif ($input['confirm_possible_duplicate_cost']) {
                throw BusinessException::invalid('AI_REPEAT_REFERENCE_REQUIRED', '重复消耗确认须引用原未知任务');
            }
            $old = Db::transaction(function () use ($c, $title, $input, $request, $hash, $fixed, $prepared) {
                if ($existing = $this->existing($c, $request, $hash)) {
                    return $existing;
                }
                $rework = $this->reworkSource($c, $input, $fixed, true);
                $ids = [];
                foreach (['connection','model','route','template'] as $kind) {
                    $ids[$fixed[$kind]['id']] = $kind;
                }ksort($ids);
                foreach ($ids as $id => $kind) {
                    $r = $this->access->find($c, $kind, (int) $id, 'use', true);
                    if ((int) $r->version !== $fixed[$kind]['version'] || $r->status !== 'enabled') {
                        throw BusinessException::conflict('AI_CONFIGURATION_CHANGED', '配置在提交时已改变');
                    }
                }
                $this->sources->verify($c, $prepared);
                $d = ['source' => $prepared['summary'],'capability' => $fixed['route']['details']['capability'],'configuration' => array_diff_key($fixed, ['credential' => true]),'consumer_module' => $input['consumer_module'],'repeat_of_task_id' => $input['repeat_of_task_id'] ?? null,'duplicate_cost_confirmed' => $input['confirm_possible_duplicate_cost'],'rework_of' => $rework,'stage' => 'preparing','result_source' => 'not_generated','usage' => ['state' => 'not_sent','billing_amount' => null],'execution_block' => null];
                $row = $this->access->create($c, 'task', $title, $d, $request, ['source_snapshot' => AiAccess::json($prepared['private']),'sealed_config' => AiAccess::json($fixed),'actor_binding' => AiAccess::json(self::actor($c)),'request_hash' => $hash,'job_key' => null]);
                $d['quota'] = $this->quota->reserve($c, $fixed['connection'], $row);
                $this->access->update($row, $d);
                $this->access->event($c, 'task', $row, 'create', 'task-create:' . hash('sha256', $request), [$hash], AiAccess::present($row));
                return $row;
            });
        }
        $id = (int) $old->id;
        $source = AiAccess::decode($old->source_snapshot);
        $fixed = AiAccess::decode($old->sealed_config);
        $this->sources->readable($c, $source);
        $this->configuration->recheck($c, $fixed);
        $prepared = $this->sources->prepareAuthorization($c, $source);
        return $this->private->exclusive($c->tenantId, $id, function () use ($c, $id, $source, $fixed, $request, $prepared): array {
            $current = $this->access->find($c, 'task', $id, 'use');
            if ($current->job_key) {
                return AiAccess::present($current);
            }
            $directory = $this->private->directory($c->tenantId, $id);
            $manifest = null;
            if ($fixed['route']['details']['capability'] === 'transcription') {
                $manifest = $this->sources->copyAudio($c, $source, $directory . '/input-audio.bin');
            }
            return Db::transaction(function () use ($c, $id, $manifest, $request, $prepared): array {
                $r = $this->access->find($c, 'task', $id, 'use', true);
                if ($r->job_key) {
                    return AiAccess::present($r);
                }
                if ($r->status !== 'preparing') {
                    throw BusinessException::conflict('AI_STATE_INVALID', '任务已离开输入准备阶段');
                }
                $this->configuration->recheck($c, AiAccess::decode($r->sealed_config));
                $this->sources->readable($c, AiAccess::decode($r->source_snapshot));
                $d = AiAccess::data($r);
                $d['input_manifest'] = $manifest;
                $d['stage'] = 'prepared';
                $d['private_expires_at'] = time() + 86400;
                $definition = app()->make(AiNativeTask::class);
                $job = $this->native->publisher($definition)->publish($this->authorization->authorize($c, $id), $definition->taskType(), ['task_id' => $id], 'ai-generate:' . $c->tenantId . ':' . $id . ':' . $request);
                $r->job_key = $job->jobKey;
                $r->save(); // Native Task reauthorization reads this server-owned binding before grant issuance.
                $d['source_authorization'] = $this->sources->issueAuthorization($c, $prepared, $job->jobKey, $d['private_expires_at'], 'ai-source:' . $job->jobKey);
                $d['native_job_key'] = $job->jobKey;
                $this->access->update($r, $d, 'queued');
                $result = AiAccess::present($r);
                $this->access->event($c, 'task', $r, 'enqueue', 'task-enqueue:' . hash('sha256', $request), [$id,$manifest], $result);
                return $result;
            });
        });
    }
    /** This is a new task linked to a withdrawn acceptance, never a resend of an unknown provider request. */
    private function reworkSource(TenantContext $c, array $input, array $fixed, bool $lock): ?array
    {
        if (!isset($input['rework_of_task_id']) && !isset($input['rework_of_task_version'])) {
            return null;
        }
        if (isset($input['repeat_of_task_id']) || $input['confirm_possible_duplicate_cost']) {
            throw BusinessException::invalid('AI_INPUT_INVALID', '固定候选重做与未知请求另发须分别办理');
        }
        $id = \Custom\Modules\AiAssistant\Validation\AiInput::integer($input['rework_of_task_id'] ?? null);
        $version = \Custom\Modules\AiAssistant\Validation\AiInput::integer($input['rework_of_task_version'] ?? null);
        $previous = $this->access->find($c, 'task', $id, 'use', $lock);
        $this->access->find($c, 'task', $id, 'view');
        $this->access->cas($previous, $version);
        $d = AiAccess::data($previous);
        if ($previous->status !== 'rework_required' || ($d['acceptance_lifecycle']['action'] ?? '') !== 'rework'
            || (int) ($d['source']['document_id'] ?? 0) !== $input['document_id']
            || ($d['capability'] ?? '') !== $fixed['route']['details']['capability']
            || ($d['consumer_module'] ?? '') !== $input['consumer_module']) {
            throw BusinessException::conflict('AI_REWORK_SOURCE_CHANGED', '须先登记撤回重做，并选同一来源文档、制作能力与使用模块的新任务；原修订不能静默改套');
        }
        return ['task_id' => $id,'task_version' => $version,'source' => $d['source'],'fixed_result' => $d['fixed_result'],
            'requested_by' => $d['acceptance_lifecycle']['member_id'],'requested_at' => $d['acceptance_lifecycle']['at']];
    }
    private function existing(TenantContext $c, string $request, string $hash): ?GenerationTask
    {
        $this->access->authorize($c, 'use');
        $row = GenerationTask::where('tenant_id', $c->tenantId)->where('request_id', $request)->find();
        if ($row !== null) {
            $this->access->find($c, 'task', (int) $row->id, 'use');
            if ((int) $row->created_by !== $c->memberId || $row->request_hash !== $hash) {
                throw BusinessException::conflict('AI_REQUEST_CONFLICT', '同请求的来源、配置选择、身份或制作要求不同');
            }
        }
        return $row;
    }
    public function status(TenantContext $c, int $id): array
    {
        $r = $this->access->find($c, 'task', $id);
        $out = AiAccess::present($r);
        if ($r->job_key) {
            $out['native_task'] = $this->native->jobs()->detail($this->nativeContext($c, 'read'), $r->job_key)->toPublicArray();
        }return $out;
    }
    public function command(TenantContext $c, int $id, string $action, ?int $jobRevision, string $request, ?int $taskVersion = null): array
    {
        $row = $this->access->find($c, 'task', $id, 'use');
        $prepared = in_array($action, ['retry', 'poll'], true) && !isset(AiAccess::data($row)['manual_input'])
            ? $this->sources->prepareAuthorization($c, AiAccess::decode($row->source_snapshot)) : null;
        return $this->private->exclusive($c->tenantId, $id, fn(): array => $this->commandLocked($c, $id, $action, $jobRevision, $request, $prepared, $taskVersion));
    }
    private function commandLocked(TenantContext $c, int $id, string $action, ?int $jobRevision, string $request, ?array $prepared, ?int $taskVersion): array
    {
        return Db::transaction(function () use ($c, $id, $action, $jobRevision, $request, $prepared, $taskVersion): array {
            $commandInput = $taskVersion === null ? [$jobRevision] : [$jobRevision,$taskVersion];
            if ($saved = $this->access->replay($c, 'task', $id, $action, $request, $commandInput, 'use')) {
                return $saved;
            }
            $r = $this->access->find($c, 'task', $id, 'use', true);
            $d = AiAccess::data($r);
            if (isset($d['collection_claim']) || isset($d['collect_request_id']) || isset($d['fixed_result'])) {
                throw BusinessException::conflict('AI_COLLECTION_IN_PROGRESS', '候选已开始领取；沿原领取请求恢复，不能改写其任务');
            }
            if (!$r->job_key) {
                $this->nativeContext($c, 'manage'); // Preparation cancellation retains the existing native management permission.
                if ($action !== 'cancel' || $jobRevision !== null || $taskVersion === null || $r->status !== 'preparing'
                    || !in_array($d['stage'], ['preparing','prepared'], true) || isset($d['attempt_started_at']) || isset($d['provider_id'])) {
                    throw BusinessException::conflict('AI_TASK_NOT_QUEUED', '未排队任务须按当前业务版本取消准备');
                }
                $this->access->cas($r, $taskVersion);
                $this->quota->settle($c, $r, $d, 'not_sent');
                $d['local_cancelled'] = true;
                $d['remote_cancellation'] = 'not_required';
                $d['stage'] = 'not_sent';
                $d['usage'] = ['state' => 'not_sent','source' => 'cancelled_before_enqueue','billing_amount' => null];
                $d['private_expires_at'] = time();
                $this->access->update($r, $d, 'cancelled');
                $result = AiAccess::present($r);
                $this->access->event($c, 'task', $r, $action, $request, $commandInput, $result);
                return $result;
            }
            if ($jobRevision === null || $taskVersion !== null) {
                throw BusinessException::conflict('AI_TASK_ALREADY_QUEUED', '任务已排队，请刷新后使用真实原生任务修订');
            }
            if ($action === 'cancel') {
                $manage = $this->nativeContext($c, 'manage');
                try {
                    $job = $this->native->jobs()->cancel($manage, $r->job_key, $jobRevision);
                } catch (TaskJobException $error) {
                    if ($error->problemCode !== 'TASK_STATE_CONFLICT'
                        || !in_array($d['stage'], ['prepared','blocked_source_authorization'], true)
                        || isset($d['attempt_started_at']) || isset($d['provider_id'])) {
                        throw $error;
                    }
                    $job = $this->native->jobs()->detailForRecovery($manage, $r->job_key, $jobRevision);
                    if ($job->status !== 'dead') {
                        throw $error;
                    }
                    // Recovery holds the native terminal row lock in this transaction and rejects any live lease/attempt.
                }
                $actor = AiAccess::decode($r->actor_binding);
                if (isset($d['source_authorization']) && ($actor['account_id'] ?? null) === $c->accountId && ($actor['member_id'] ?? null) === $c->memberId) {
                    $this->sources->revokeAuthorization($c, $d['source_authorization']);
                }
                $state = in_array($d['stage'], ['preparing','prepared','blocked_source_authorization'], true) ? 'not_sent' : 'unknown';
                if (in_array($d['quota']['state'], ['reserved','unknown'], true)) {
                    $this->quota->settle($c, $r, $d, $state);
                }
                $d['local_cancelled'] = true;
                $d['remote_cancellation'] = 'not_confirmed';
                $d['stage'] = $state === 'not_sent' ? 'not_sent' : 'unknown';
                $this->access->update($r, $d, 'cancelled');
            } elseif ($action === 'retry') {
                $b = AiAccess::decode($r->actor_binding);
                if (($b['member_id'] ?? null) !== $c->memberId || ($b['account_id'] ?? null) !== $c->accountId) {
                    throw BusinessException::forbidden('AI_ORIGINAL_ACTOR_REQUIRED', '重试必须由实际原提交成员重新授权');
                }
                if (in_array($d['stage'], ['sending','unknown'], true) && !$this->private->acknowledged($c->tenantId, $id, $r->job_key, $r->request_hash)) {
                    throw BusinessException::conflict('AI_UNKNOWN_NO_RESEND', '原POST结果未知，不能通过重试自动再发；可核对真实提供方ID或另建明确确认风险的新请求');
                }
                if (!in_array($d['stage'], ['prepared','blocked_source_authorization','provider_pending','candidate','sending','unknown'], true)) {
                    throw BusinessException::conflict('AI_RETRY_NOT_ALLOWED', '该业务阶段不允许重试');
                }
                $this->sources->readable($c, AiAccess::decode($r->source_snapshot));
                $fixed = AiAccess::decode($r->sealed_config);
                $this->configuration->recheck($c, $fixed);
                if ($d['quota']['state'] === 'not_sent') {
                    $this->access->find($c, 'connection', $fixed['connection']['id'], 'use', true);
                    $d['quota'] = $this->quota->reserve($c, $fixed['connection'], $r);
                }
                $r->actor_binding = AiAccess::json(self::actor($c));
                $r->save();
                $job = $this->native->jobs()->retry($this->nativeContext($c, 'manage'), $r->job_key, $jobRevision);
                if ($prepared !== null) {
                    if (isset($d['source_authorization'])) {
                        $this->sources->revokeAuthorization($c, $d['source_authorization']);
                    }
                    $d['private_expires_at'] = time() + 86400;
                    $d['source_authorization'] = $this->sources->issueAuthorization($c, $prepared, $r->job_key, $d['private_expires_at'], 'ai-retry-source:' . hash('sha256', $request));
                    if ($d['stage'] === 'blocked_source_authorization') {
                        $d['stage'] = 'prepared';
                        $d['execution_block'] = null;
                    }
                }
                $this->access->update($r, $d, 'queued');
            } elseif ($action === 'poll') {
                $job = $this->native->jobs()->detail($this->nativeContext($c, 'read'), $r->job_key);
                if ($job->status !== 'succeeded' || $job->revision !== $jobRevision || $d['stage'] !== 'provider_pending' || !is_string($d['provider_id'] ?? null)) {
                    throw BusinessException::conflict('AI_PROVIDER_POLL_NOT_READY', '仅已确认提供方ID的异步任务可发起安全GET核对');
                }
                $this->sources->readable($c, AiAccess::decode($r->source_snapshot));
                $this->configuration->recheck($c, AiAccess::decode($r->sealed_config));
                $definition = app()->make(AiNativeTask::class);
                $next = $this->native->publisher($definition)->publish($this->authorization->authorize($c, $id), $definition->taskType(), ['task_id' => $id], 'ai-poll:' . $c->tenantId . ':' . $id . ':' . $request);
                $previousActor = AiAccess::decode($r->actor_binding);
                if (isset($d['source_authorization']) && ($previousActor['account_id'] ?? null) === $c->accountId && ($previousActor['member_id'] ?? null) === $c->memberId) {
                    $this->sources->revokeAuthorization($c, $d['source_authorization']);
                }
                $r->job_key = $next->jobKey;
                $r->actor_binding = AiAccess::json(self::actor($c));
                $r->save();
                $d['private_expires_at'] = time() + 86400;
                $d['source_authorization'] = $this->sources->issueAuthorization($c, $prepared, $next->jobKey, $d['private_expires_at'], 'ai-poll-source:' . hash('sha256', $request));
                $d['native_job_key'] = $next->jobKey;
                $this->access->update($r, $d, 'queued');
            } elseif ($action === 'observeUnknown') {
                $job = $this->native->jobs()->detail($this->nativeContext($c, 'read'), $r->job_key);
                if (in_array($job->status, ['queued','running'], true) || $d['stage'] !== 'sending') {
                    throw BusinessException::conflict('AI_NOT_UNCERTAIN', '只有终止任务的在途发送能登记本地未知事实');
                }
                $this->quota->settle($c, $r, $d, 'unknown');
                $d['stage'] = 'unknown';
                $d['usage'] = ['state' => 'unknown','source' => 'interrupted_after_send_intent','billing_amount' => null];
                $this->access->update($r, $d, 'unknown');
            } else {
                throw BusinessException::invalid('AI_TASK_ACTION_INVALID', '任务动作无效');
            }
            $result = AiAccess::present($r);
            $this->access->event($c, 'task', $r, $action, $request, $commandInput, $result);
            return $result;
        });
    }
    public function nativeContext(TenantContext $c, string $operation): AuthorizedOperationContext
    {
        $key = $operation === 'read' ? 'peanut.task.jobs.read' : 'peanut.task.jobs.manage';
        if (!$this->access->permission($c, $key)) {
            throw BusinessException::forbidden('AI_NATIVE_TASK_PERMISSION_DENIED', '需要原生任务查看或管理权限');
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($c, TaskJobService::RESOURCE_KEY, $operation, [], AiAccess::hash([$c->tenantId,$c->memberId,$c->authorizationRevision,$operation])));
    }
    public static function actor(TenantContext $c): array
    {
        return ['account_id' => $c->accountId,'member_id' => $c->memberId,'session_key' => $c->sessionKey,'client_key' => $c->clientKey];
    }
}
