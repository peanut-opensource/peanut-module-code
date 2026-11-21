<?php

declare(strict_types=1);

namespace app\common\contract\storage;

interface StorageCredentialResolver
{
    /** @return array{access_key:string,secret_key:string} */
    public function resolve(array $account): array;
}
