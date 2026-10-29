<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

interface DocumentAcceptedWriteContributor
{
    /** @return class-string<DocumentAcceptedWriteProvider> */
    public function acceptedWriteProvider(): string;
}
