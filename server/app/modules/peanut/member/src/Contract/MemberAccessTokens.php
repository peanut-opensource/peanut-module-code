<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

/** Verifies the signed token and the current tenant-bound persistent Member session. */
interface MemberAccessTokens
{
    public function createToken(int $memberId): string;

    public function revokeToken(string $token): void;

    /** @return array{member_id:int,tenant_id:int,session_key:string,session_revision:int,issued_at:int,expires_at:int} */
    public function parseSessionToken(string $token): array;
}
