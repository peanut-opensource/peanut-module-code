<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Infrastructure\Persistence;

use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthAttemptRecord;
use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthCompletionRecord;
use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthIdentityRecord;
use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthPrincipalRecord;
use PeanutAdmin\Modules\OAuth\Contract\OAuthPersistence;
use PeanutAdmin\Modules\OAuth\Model\OAuthAttempt;
use PeanutAdmin\Modules\OAuth\Model\OAuthCompletionTicket;
use PeanutAdmin\Modules\OAuth\Model\OAuthIdentity;
use PeanutAdmin\Modules\OAuth\Model\OAuthPrincipal;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final class ThinkPhpOAuthPersistence implements OAuthPersistence
{
    public function createAttempt(TenantContext|TenantSystemContext $context, array $data): void
    {
        unset($data['tenant_id']);
        OAuthAttempt::create($data);
    }

    public function attemptForUpdate(TenantContext|TenantSystemContext $context, string $stateHash): ?OAuthAttemptRecord
    {
        $model = OAuthAttempt::where('state_hash', $stateHash)->lock(true)->findOrEmpty();
        return $model->isEmpty() ? null : new OAuthAttemptRecord(
            (int) $model->id,
            (string) $model->scene,
            (string) $model->return_path,
            (int) $model->expires_at,
            empty($model->used_at) ? null : (int) $model->used_at,
        );
    }

    public function markAttemptUsed(TenantContext|TenantSystemContext $context, int $id, int $usedAt): void
    {
        OAuthAttempt::where('id', $id)->update(['used_at' => $usedAt]);
    }

    public function createCompletion(TenantContext|TenantSystemContext $context, array $data): void
    {
        unset($data['tenant_id']);
        OAuthCompletionTicket::create($data);
    }

    public function completionForUpdate(TenantContext|TenantSystemContext $context, string $tokenHash): ?OAuthCompletionRecord
    {
        $model = OAuthCompletionTicket::where('token_hash', $tokenHash)->lock(true)->findOrEmpty();
        return $model->isEmpty() ? null : new OAuthCompletionRecord(
            (int) $model->id,
            (int) $model->member_id,
            (int) $model->need_profile === 1,
            (int) $model->need_mobile === 1,
            (int) $model->expires_at,
            empty($model->used_at) ? null : (int) $model->used_at,
        );
    }

    public function markCompletionUsed(TenantContext|TenantSystemContext $context, int $id, int $usedAt): void
    {
        OAuthCompletionTicket::where('id', $id)->update(['used_at' => $usedAt]);
    }

    public function identityBySubjectForUpdate(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $provider,
        string $clientKey,
        string $subject,
    ): ?OAuthIdentityRecord {
        return self::identity(OAuthIdentity::where([
            'provider' => $provider,
            'client_key' => $clientKey,
            'subject' => $subject,
        ])->lock(true)->findOrEmpty());
    }

    public function identityByMemberForUpdate(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $provider,
        string $clientKey,
        int $memberId,
    ): ?OAuthIdentityRecord {
        return self::identity(OAuthIdentity::where([
            'provider' => $provider,
            'client_key' => $clientKey,
            'member_id' => $memberId,
        ])->lock(true)->findOrEmpty());
    }

    public function principalByUnionForUpdate(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $provider,
        string $unionScope,
        string $unionId,
    ): ?OAuthPrincipalRecord {
        $model = OAuthPrincipal::where([
            'provider' => $provider,
            'union_scope' => $unionScope,
            'union_id' => $unionId,
        ])->lock(true)->findOrEmpty();
        return $model->isEmpty() ? null : new OAuthPrincipalRecord(
            (int) $model->id,
            (int) $model->member_id,
        );
    }

    public function createPrincipal(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        array $data,
    ): OAuthPrincipalRecord {
        unset($data['tenant_id']);
        $model = OAuthPrincipal::create($data);
        return new OAuthPrincipalRecord((int) $model->id, (int) $model->member_id);
    }

    public function createIdentity(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        array $data,
    ): void {
        unset($data['tenant_id']);
        OAuthIdentity::create($data);
    }

    public function updateIdentityPrincipal(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $identityId,
        int $principalId,
    ): void {
        OAuthIdentity::where('id', $identityId)->update(['principal_id' => $principalId]);
    }

    public function wechatSubjectForMember(
        AuthenticatedMemberContext|TenantContext $context,
        int $memberId,
        int $terminal,
    ): string {
        return (string) OAuthIdentity::where([
            'provider' => 'wechat',
            'member_id' => $memberId,
            'terminal' => $terminal,
        ])->value('subject');
    }

    private static function identity(OAuthIdentity $model): ?OAuthIdentityRecord
    {
        return $model->isEmpty() ? null : new OAuthIdentityRecord(
            (int) $model->id,
            (int) $model->member_id,
            $model->principal_id === null ? null : (int) $model->principal_id,
        );
    }
}
