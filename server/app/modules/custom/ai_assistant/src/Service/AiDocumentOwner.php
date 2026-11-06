<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use Custom\Modules\AiAssistant\Model\GenerationTask;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerPolicy;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerState;

final readonly class AiDocumentOwner implements DocumentOwnerPolicy
{
    public function __construct(private AiAccess $access, private CurrentExecutionContext $current) {}
    public function authorizationPermissionKeys(string $action): array
    {
        if (!in_array($action, ['view','create','edit','version.create'], true)) {
            throw BusinessException::forbidden('AI_RESULT_DOCUMENT_ACTION_DENIED', '固定候选不开放覆盖、删除或发布');
        }
        return ['custom.ai-assistant.view','custom.ai-assistant.use','custom.ai-assistant.review'];
    }
    public function resolveOwner(TenantContext $c, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState
    {
        return $this->resolvePersistedOwner($c->tenantId, $owner, $forUpdate);
    }
    public function resolvePersistedOwner(int $tenantId, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState
    {
        if ($tenantId !== $this->current->tenantId() || $owner->moduleKey !== 'custom.ai-assistant' || $owner->objectType !== 'task' || !preg_match('/^[1-9][0-9]*$/D', $owner->objectId) || !preg_match('/^result_[a-f0-9]{40}$/D', $owner->slot)) {
            throw BusinessException::notFound('AI_DOCUMENT_OWNER_INVALID', '固定候选所有者无效');
        }
        $r = GenerationTask::where('tenant_id', $tenantId)->where('id', (int) $owner->objectId)->lock($forUpdate)->find() ?? throw BusinessException::notFound('AI_NOT_FOUND', '实际业务任务不存在');
        $d = AiAccess::data($r);
        if ($owner->slot !== AiCandidateGuard::slot((string) $r->job_key, $d['candidate']['sha256'] ?? '')) {
            throw BusinessException::notFound('AI_DOCUMENT_OWNER_INVALID', '固定候选所有者位置不匹配');
        }
        return new DocumentOwnerState($tenantId, $owner, false, (string) $r->version);
    }
    public function authorize(TenantContext $c, string $action, DocumentOwnerState $owner): void
    {
        $this->authorizationPermissionKeys($action);
        $r = $this->access->find($c, 'task', (int) $owner->reference->objectId, $action === 'view' ? 'view' : 'use');
        $d = AiAccess::data($r);
        AiCandidateGuard::authorizeDocument($action, $d, $c->memberId, (string) $r->job_key, $owner->reference->slot);
        app()->make(AiSources::class)->readable($c, AiAccess::decode($r->source_snapshot));
        app()->make(AiConfiguration::class)->recheck($c, AiAccess::decode($r->sealed_config));
    }
    public function stateRevision(DocumentOwnerState $owner): string
    {
        return $owner->revision;
    }
}
