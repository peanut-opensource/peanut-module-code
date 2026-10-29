<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

final readonly class DurableSaveReceipt
{
    public function __construct(public array $data) {}
}
