<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

use app\common\http\PageResult;

interface NotificationQueries
{
    public function channelDetail(): array;

    public function scenes(): array;

    public function sceneDetail(int $id): array;

    public function sceneExists(int $id): bool;

    /** @param array<string,mixed> $params */
    public function logs(array $params): PageResult;

    public function logDetail(int $id): array;
}
