<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use app\platform\context\PlatformOperatorContext;

interface StorageConfiguration
{
    public function snapshot(): array;

    public function createAccount(PlatformOperatorContext $context, array $value): int;

    public function updateAccount(PlatformOperatorContext $context, array $value): void;

    public function createSpace(PlatformOperatorContext $context, array $value): int;

    public function updateSpace(PlatformOperatorContext $context, array $value): void;

    public function setRoute(PlatformOperatorContext $context, array $value): void;
}
