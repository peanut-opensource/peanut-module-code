<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

/** Whole-batch admission, durable per-object progress and explicit recovery. */
interface FileVisibility
{
    public function preview(array $fileKeys): array;
    public function submit(array $confirmation, string $signature, string $requestKey, bool $impactConfirmed): array;
    public function status(string $batchKey): array;
    public function recent(): array;
    public function resume(string $batchKey): array;
}
