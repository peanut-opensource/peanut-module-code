<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\{AuthorizationDecision,AuthorizedOperationContext,RequestedTargetSet};
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use think\facade\Db;

/** Native initial reauthorization precedes the System root; own explicit tenant queries retain native action scope. */
final readonly class AiTaskAuthorization
{
    public function __construct(private AiAccess $access, private TenantSessionAccess $sessions, private DataPermissionEngine $engine, private ThinkPhpQueryConstraintApplier $constraints) {}
    public function authorize(TenantContext $c, int $id): AuthorizedOperationContext
    {
        $this->access->authorize($c, 'use');
        $row = $this->owned($c, 'task', $id);
        $fixed = AiAccess::decode($row['sealed_config']);
        foreach (['route','model','template','connection'] as $kind) {
            $expected = $fixed[$kind];
            $current = $this->owned($c, $kind, $expected['id']);
            if ($current['status'] !== 'enabled' || (int) $current['version'] !== $expected['version']) {
                throw BusinessException::conflict('AI_CONFIGURATION_CHANGED', '固定配置已经停用、修订或撤出当前范围');
            }
        }
        $model = $fixed['model']['details'];
        if (isset($model['valid_until']) && gmdate('Y-m-d\\TH:i:s\\Z') >= $model['valid_until']) {
            throw BusinessException::conflict('AI_MODEL_EXPIRED', '固定模型已到有效截止');
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($c, 'custom.ai-assistant.task', 'use', [new RequestedTargetSet('custom.ai-assistant.task', [(string) $id])], AiAccess::hash([$c->tenantId,$c->memberId,$c->authorizationRevision,$id])));
    }
    private function owned(TenantContext $c, string $kind, int $id): array
    {
        $q = Db::name($kind === 'task' ? 'ai_assistant_task' : 'ai_assistant_config')->where('tenant_id', $c->tenantId)->where('id', $id);
        if ($kind !== 'task') {
            $q->where('kind', $kind)->where('deleted_at', 0);
        }
        $this->constraints->apply($q, $this->engine->queryConstraint($c, 'custom.ai-assistant.' . $kind, 'use'));
        return $q->find() ?? throw BusinessException::forbidden('AI_TASK_OBJECT_REVOKED', '当前任务或配置的use范围已撤销');
    }
    public function reauthorize(VerifiedJobEnvelope $e): AuthorizedOperationContext
    {
        $t = $e->requestedTargets[0] ?? null;
        if ($e->resourceKey !== 'custom.ai-assistant.task' || $e->operation !== 'use' || count($e->requestedTargets) !== 1 || !$t instanceof RequestedTargetSet || $t->targetResourceKey !== 'custom.ai-assistant.task' || count($t->targetIds) !== 1 || !preg_match('/^[1-9][0-9]*$/D', $t->targetIds[0])) {
            throw new \DomainException('AI_TASK_ENVELOPE_INVALID');
        }
        // Only our server-held actor references are read before the real session is revalidated.
        $row = Db::name('ai_assistant_task')->where('tenant_id', $e->tenantId)->where('id', (int) $t->targetIds[0])->where('job_key', $e->operationId)->find() ?? throw new \DomainException('AI_TASK_BINDING_INVALID');
        $b = AiAccess::decode($row['actor_binding']);
        if (($b['account_id'] ?? null) !== $e->accountId || ($b['member_id'] ?? null) !== $e->memberId) {
            throw new \DomainException('AI_TASK_ACTOR_INVALID');
        }
        $c = $this->sessions->revalidateSession($e->tenantId, $e->accountId, $e->memberId, $b['session_key'], $b['client_key'], $e->traceId);
        return $this->authorize($c,(int) $row['id']);
    }
}
