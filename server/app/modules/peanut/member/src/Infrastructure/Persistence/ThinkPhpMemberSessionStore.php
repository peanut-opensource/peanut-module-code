<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Infrastructure\Persistence;

use app\common\tenancy\PlatformTenantDataGateway;
use PeanutAdmin\Modules\Member\Contract\MemberSessionStore;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionRecord;
use PeanutAdmin\Modules\Member\Model\MemberSession;

/** 在认证上下文建立前，通过唯一受控跨租户网关核验会员会话。 */
final readonly class ThinkPhpMemberSessionStore implements MemberSessionStore
{
    private const ACTOR = 'api.member-session';

    public function __construct(private PlatformTenantDataGateway $tenantData) {}

    public function create(MemberSessionRecord $session): void
    {
        $written = $this->query('issue')->insert([
            'session_hash' => $session->sessionHash,
            'tenant_id' => $session->tenantId,
            'member_id' => $session->memberId,
            'session_revision' => $session->sessionRevision,
            'issued_at' => $session->issuedAt,
            'expires_at' => $session->expiresAt,
            'created_at' => gmdate('Y-m-d H:i:s', $session->issuedAt),
        ]);
        if ($written !== 1) {
            throw new \RuntimeException('MEMBER_SESSION_STORAGE_UNAVAILABLE');
        }
    }

    public function findByHash(string $sessionHash): ?MemberSessionRecord
    {
        $row = $this->query('verify')
            ->where('session_hash', $sessionHash)
            ->field([
                'session_hash', 'tenant_id', 'member_id', 'session_revision',
                'issued_at', 'expires_at', 'revoked_at',
            ])
            ->find();
        if ($row === null) {
            return null;
        }
        $data = $row->toArray();
        return new MemberSessionRecord(
            (string) $data['session_hash'],
            (int) $data['tenant_id'],
            (int) $data['member_id'],
            (int) $data['session_revision'],
            (int) $data['issued_at'],
            (int) $data['expires_at'],
            $data['revoked_at'] === null ? null : (int) $data['revoked_at'],
        );
    }

    public function revoke(
        string $sessionHash,
        int $tenantId,
        int $memberId,
        string $reason,
        int $revokedAt,
    ): bool {
        return $this->query('revoke-current')
            ->where('session_hash', $sessionHash)
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $revokedAt,
                'revoke_reason' => $reason,
                'updated_at' => gmdate('Y-m-d H:i:s', $revokedAt),
            ]) === 1;
    }

    public function revokeAll(
        int $tenantId,
        int $memberId,
        string $reason,
        int $revokedAt,
    ): void {
        $this->query('revoke-all')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $revokedAt,
                'revoke_reason' => $reason,
                'updated_at' => gmdate('Y-m-d H:i:s', $revokedAt),
            ]);
    }

    private function query(string $operation): \think\db\BaseQuery
    {
        return $this->tenantData->query(MemberSession::class, self::ACTOR, $operation);
    }
}
