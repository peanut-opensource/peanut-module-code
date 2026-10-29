<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use app\common\http\PageResult;

/** Tenant-admin use cases exposed by the Member Module. */
interface MemberAdministration
{
    /** @return PageResult|array<string,mixed> */
    public function members(array $params): PageResult|array;

    /** @return array<string,mixed> */
    public function memberDetail(int $id): array;

    public function createMember(array $params): void;

    public function updateMember(array $params): void;

    public function updateMemberField(array $params): void;

    public function updateMemberStatus(int $id, int $status): void;

    public function adjustMemberBalance(array $params, int $adminId, string $idempotencyKey): void;

    public function balanceLogs(array $params): PageResult;

    /** @return list<array<string,mixed>> */
    public function tags(): array;

    public function createTag(array $params): void;

    public function updateTag(array $params): void;

    public function deleteTag(int $id): void;
}
