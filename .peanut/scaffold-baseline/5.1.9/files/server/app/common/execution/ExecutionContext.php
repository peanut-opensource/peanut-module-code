<?php

declare(strict_types=1);

namespace app\common\execution;

/** Shared lifecycle contract; authorization always depends on a concrete audience type. */
interface ExecutionContext
{
    public function operation(): string;

    public function requestId(): string;

    public function tenantId(): ?int;

    /** @return array<string,int|string> */
    public function actor(): array;
}
