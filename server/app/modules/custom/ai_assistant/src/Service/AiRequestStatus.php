<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use Custom\Modules\AiAssistant\Definition\AiDefinitions as D;
use Custom\Modules\AiAssistant\Model\{Configuration,GenerationTask,Event};
use Custom\Modules\AiAssistant\Validation\AiInput as I;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** Read-only evidence for the real issuer and the original current operation scope. */
final readonly class AiRequestStatus
{
    public function __construct(private AiAccess $access, private AiSources $sources) {}
    public static function descriptor(array $v): array
    {
        I::only($v, ['endpoint','kind','id','action','request_id']);
        $endpoint = I::choice($v['endpoint'] ?? null, ['saveConfiguration','saveCredential','configurationState','createTask','manualCandidate','taskAction','collectCandidate','decide','candidateLifecycle']);
        $request = I::requestId($v['request_id'] ?? null);
        $config = in_array($endpoint, ['saveConfiguration','saveCredential','configurationState'], true);
        $kind = $config ? I::choice($v['kind'] ?? null, D::KINDS) : I::choice($v['kind'] ?? null, ['task']);
        if ($endpoint === 'saveCredential' && $kind !== 'connection') {
            I::invalid('凭据回执仅属于连接');
        }
        $id = isset($v['id']) ? I::integer($v['id']) : null;
        if ($endpoint === 'createTask' && $id !== null) {
            I::invalid('任务创建回执不能带现有对象编号');
        }
        if (isset($v['action']) && !in_array($endpoint, ['configurationState','taskAction','candidateLifecycle'], true)) {
            I::invalid('该原请求不接受动作选择');
        }
        $creation = $endpoint === 'createTask' || $endpoint === 'saveConfiguration' && $id === null;
        if (!$creation && $id === null) {
            I::invalid('原请求对象编号必填');
        }
        $operation = $config ? 'configure' : (in_array($endpoint, ['decide','candidateLifecycle'], true) ? 'review' : 'use');
        $action = match ($endpoint) {
            'saveConfiguration' => $creation ? 'create' : 'modify','saveCredential' => 'credential','configurationState' => 'state',
            'createTask' => 'enqueue','manualCandidate' => 'manualCandidate','collectCandidate' => 'collect','decide' => 'decision',
            'candidateLifecycle' => 'candidate.' . I::choice($v['action'] ?? null, ['withdraw','rework','supersede']),
            'taskAction' => I::choice($v['action'] ?? null, ['cancel','retry','observeUnknown','poll']),
        };
        if ($endpoint === 'configurationState') {
            I::choice($v['action'] ?? null, ['enable','disable']);
        }
        return ['endpoint' => $endpoint,'kind' => $kind,'id' => $id,'operation' => $operation,'event_action' => $action,'creation' => $creation,'request_id' => $request];
    }
    public function status(TenantContext $c, array $input): array
    {
        $d = self::descriptor($input);
        $this->access->authorize($c, $d['operation']);
        $row = null;
        if ($d['creation']) {
            $model = $d['kind'] === 'task' ? GenerationTask::class : Configuration::class;
            $q = $model::where('tenant_id', $c->tenantId)->where('created_by', $c->memberId)->where('request_id', $d['request_id']);
            if ($d['kind'] !== 'task') {
                $q->where('kind', $d['kind']);
            }
            $found = $q->find();
            if ($found === null) {
                $this->access->authorizeCreation($c, $d['kind']);
                return $this->result($c, $d, 'unconfirmed', null, false, 'no_visible_committed_receipt');
            }
            if ((int) $found->created_by !== $c->memberId) {
                throw BusinessException::notFound('AI_REQUEST_NOT_FOUND', '原请求不属于当前实际成员');
            }
            $row = $this->access->find($c, $d['kind'], (int) $found->id, $d['operation']);
        } else {
            $row = $this->access->find($c, $d['kind'], $d['id'], $d['operation']);
        }
        if ($d['endpoint'] === 'candidateLifecycle') {
            $this->access->find($c, 'task', (int) $row->id, 'view');
        }
        if ($d['kind'] === 'task' && $d['endpoint'] !== 'candidateLifecycle') {
            $this->sources->readable($c, AiAccess::decode($row->source_snapshot));
            foreach (['route','model','template','connection'] as $kind) {
                $fixed = AiAccess::decode($row->sealed_config)[$kind];
                $this->access->find($c, $kind, $fixed['id'], 'use');
            }
            if (in_array($d['endpoint'], ['collectCandidate','manualCandidate'], true) && !$this->access->permission($c, 'peanut.task.jobs.read')) {
                throw BusinessException::forbidden('AI_NATIVE_TASK_PERMISSION_DENIED', '恢复此操作须仍有原生任务查看权限');
            }
            if ($d['endpoint'] === 'taskAction') {
                $permission = in_array($d['event_action'], ['cancel','retry'], true) ? 'peanut.task.jobs.manage' : 'peanut.task.jobs.read';
                if (!$this->access->permission($c, $permission)) {
                    throw BusinessException::forbidden('AI_NATIVE_TASK_PERMISSION_DENIED', '恢复此操作须仍有原生任务操作权限');
                }
            }
        }
        $eventRequest = $d['creation'] ? ($d['kind'] === 'task' ? 'task-enqueue:' : 'create:') . hash('sha256', $d['request_id']) : $d['request_id'];
        $event = Event::where('tenant_id', $c->tenantId)->where('request_id', $eventRequest)->find();
        if ($event !== null) {
            $e = AiAccess::data($event);
            if ((int) $event->created_by !== $c->memberId || (int) $event->parent_id !== (int) $row->id || $event->actor_type !== 'tenant_member' || ($e['kind'] ?? null) !== $d['kind'] || $event->title !== $d['event_action']) {
                throw BusinessException::conflict('AI_REQUEST_CONFLICT', '原请求标识不属于该实际成员、对象或操作');
            }
            if ($d['endpoint'] === 'saveCredential' && !AiAccess::credentialWriteVerified($e)) {
                return $this->result($c, $d, 'unconfirmed', $e['result'] ?? null, true, 'credential_receipt_without_storage_proof');
            }
            if ($d['endpoint'] === 'candidateLifecycle') {
                if (!is_string($e['input_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $e['input_hash']) !== 1 || !is_array($e['result'] ?? null)) {
                    return $this->result($c, $d, 'unconfirmed', null, true, 'committed_receipt_missing_input_proof');
                }
                return self::candidateReceipt($c, $d['id'], $d['request_id'], $e['input_hash'], $e['result']);
            }
            return $this->result($c, $d, 'applied', $e['result'] ?? null, true, 'committed_original_receipt');
        }
        $data = AiAccess::data($row);
        if ($d['endpoint'] === 'createTask') {
            return $this->result($c, $d, 'preparing', null, true, 'persisted_task_preparation');
        }
        if ($d['endpoint'] === 'saveConfiguration' && $d['creation']) {
            return $this->result($c, $d, 'preparing', null, true, 'persisted_creation_missing_receipt');
        }
        if ($d['endpoint'] === 'collectCandidate' && ($data['collect_request_id'] ?? null) === $d['request_id']) {
            $claim = $data['collection_claim'] ?? null;
            if (!is_array($claim) || ($claim['member_id'] ?? null) !== $c->memberId || ($claim['request_id'] ?? null) !== $d['request_id']) {
                throw BusinessException::conflict('AI_COLLECTION_REQUEST_REQUIRED', '领取锚点不属于原实际成员或请求');
            }
            return $this->result($c, $d, 'preparing', null, true, 'persisted_collection_claim');
        }
        if ($d['endpoint'] === 'manualCandidate' && ($data['manual_request_id'] ?? null) === $d['request_id']) {
            if (($data['manual_input']['submitted_by'] ?? null) !== $c->memberId) {
                throw BusinessException::conflict('AI_REQUEST_CONFLICT', '人工候选锚点不属于当前实际成员');
            }
            return $this->result($c, $d, 'preparing', null, true, 'persisted_manual_candidate');
        }
        return $this->result($c, $d, 'unconfirmed', null, false, 'no_visible_committed_receipt');
    }
    /** Same committed event proof for direct success and later original-request recovery. */
    public static function candidateReceipt(TenantContext $c, int $id, string $request, string $inputHash, array $result): array
    {
        return ['request_id' => $request,'endpoint' => 'candidateLifecycle','kind' => 'task','object_id' => $id,
            'actor' => ['tenant_id' => $c->tenantId,'member_id' => $c->memberId],'state' => 'applied',
            'anchor_present' => true,'evidence' => 'committed_original_receipt','input_hash' => $inputHash,'result' => $result,
            'absence_proves_not_executed' => false,'provider_success' => false];
    }
    private function result(TenantContext $c, array $d, string $state, ?array $result, bool $anchor, string $evidence): array
    {
        // Absence is never proof of non-execution. A separate complete known rejection is required.
        return ['request_id' => $d['request_id'],'endpoint' => $d['endpoint'],'kind' => $d['kind'],'object_id' => $d['id'],'actor' => ['tenant_id' => $c->tenantId,'member_id' => $c->memberId],
            'state' => $state,'anchor_present' => $anchor,'evidence' => $evidence,'result' => $result,'absence_proves_not_executed' => false,'provider_success' => false];
    }
}
