<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionRecord;

/**
 * 会员模块拥有的会话持久化边界。
 *
 * 该接口使会话语义可用受控假仓储验证；生产实现必须使用持久数据库，不能用短命缓存代替撤销账本。
 */
interface MemberSessionStore
{
    public function create(MemberSessionRecord $session): void;

    public function findByHash(string $sessionHash): ?MemberSessionRecord;

    public function revoke(
        string $sessionHash,
        int $tenantId,
        int $memberId,
        string $reason,
        int $revokedAt,
    ): bool;

    public function revokeAll(
        int $tenantId,
        int $memberId,
        string $reason,
        int $revokedAt,
    ): void;
}
