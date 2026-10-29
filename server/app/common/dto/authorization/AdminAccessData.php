<?php

declare(strict_types=1);

namespace app\common\dto\authorization;

final readonly class AdminAccessData
{
    /** @param list<array<string,mixed>> $menu @param list<string> $permissions */
    public function __construct(
        public array $menu,
        public array $permissions,
    ) {}
}
