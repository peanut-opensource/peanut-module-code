<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Contract;

use PeanutAdmin\Modules\ReferenceCodes\Dto\DictionaryEntryDto;

interface SystemReferenceCodeQuery
{
    /** @return list<DictionaryEntryDto> */
    public function systemEntriesByType(string $type): array;
}
