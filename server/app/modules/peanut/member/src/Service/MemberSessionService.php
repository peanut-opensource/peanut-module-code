<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Service;

use PeanutAdmin\Modules\Member\Contract\MemberSessionStore;
use PeanutAdmin\Modules\Member\Contract\MemberSessions;
use PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionGrant;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionRecord;

/** 会员端会话签发、权威校验与撤销语义。 */
final readonly class MemberSessionService implements MemberSessions
{
    private const CURRENT_SESSION_REVOKE_REASON = 'logout';

    public function __construct(
        private MemberSubjectLookup $subjects,
        private MemberSessionStore $store,
    ) {}

    public function issue(int $memberId, int $issuedAt, int $expiresAt): MemberSessionGrant
    {
        if ($memberId < 1 || $issuedAt < 1 || $expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('MEMBER_SESSION_ISSUE_INVALID');
        }
        $subject = $this->subjects->sessionSubject($memberId);
        if ($subject === null) {
            throw new \UnexpectedValueException('MEMBER_SESSION_SUBJECT_UNAVAILABLE');
        }

        $sessionKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->store->create(new MemberSessionRecord(
            self::hash($sessionKey),
            $subject->tenantId,
            $subject->memberId,
            $subject->sessionRevision,
            $issuedAt,
            $expiresAt,
        ));

        return new MemberSessionGrant(
            $sessionKey,
            $subject->tenantId,
            $subject->memberId,
            $subject->sessionRevision,
            $issuedAt,
            $expiresAt,
        );
    }

    public function verify(
        string $sessionKey,
        int $tenantId,
        int $memberId,
        int $sessionRevision,
        int $issuedAt,
        int $expiresAt,
        int $now,
    ): void {
        self::assertShape($sessionKey, $tenantId, $memberId, $sessionRevision, $issuedAt, $expiresAt, $now);
        $sessionHash = self::hash($sessionKey);
        $record = $this->store->findByHash($sessionHash);
        if ($record === null
            || !hash_equals($record->sessionHash, $sessionHash)
            || $record->tenantId !== $tenantId
            || $record->memberId !== $memberId
            || $record->sessionRevision !== $sessionRevision
            || $record->issuedAt !== $issuedAt
            || $record->expiresAt !== $expiresAt
            || $record->revokedAt !== null
            || $issuedAt > $now
            || $expiresAt <= $now) {
            throw new \UnexpectedValueException('MEMBER_SESSION_INVALID');
        }

        $subject = $this->subjects->sessionSubject($memberId);
        if ($subject === null
            || $subject->tenantId !== $tenantId
            || $subject->memberId !== $memberId
            || $subject->sessionRevision !== $sessionRevision) {
            throw new \UnexpectedValueException('MEMBER_SESSION_INVALID');
        }
    }

    public function revokeCurrent(
        string $sessionKey,
        int $tenantId,
        int $memberId,
        int $sessionRevision,
        int $issuedAt,
        int $expiresAt,
        int $now,
    ): void {
        $this->verify($sessionKey, $tenantId, $memberId, $sessionRevision, $issuedAt, $expiresAt, $now);
        if (!$this->store->revoke(
            self::hash($sessionKey),
            $tenantId,
            $memberId,
            self::CURRENT_SESSION_REVOKE_REASON,
            $now,
        )) {
            throw new \UnexpectedValueException('MEMBER_SESSION_INVALID');
        }
    }

    public function revokeAll(int $tenantId, int $memberId, string $reason, int $now): void
    {
        if ($tenantId < 1
            || $memberId < 1
            || $now < 1
            || preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('MEMBER_SESSION_REVOCATION_INVALID');
        }
        $this->store->revokeAll($tenantId, $memberId, $reason, $now);
    }

    private static function assertShape(
        string $sessionKey,
        int $tenantId,
        int $memberId,
        int $sessionRevision,
        int $issuedAt,
        int $expiresAt,
        int $now,
    ): void {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $sessionKey) !== 1
            || $tenantId < 1
            || $memberId < 1
            || $sessionRevision < 1
            || $issuedAt < 1
            || $expiresAt <= $issuedAt
            || $now < 1) {
            throw new \UnexpectedValueException('MEMBER_SESSION_INVALID');
        }
    }

    private static function hash(string $sessionKey): string
    {
        return hash('sha256', $sessionKey);
    }
}
