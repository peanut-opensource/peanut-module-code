<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Contract;

use PeanutAdmin\Modules\ReferenceCodes\DictionaryEntry;

interface SystemDictionaryProvider
{
    /** @return list<DictionaryEntry> */
    public function enabledEntriesByType(string $type): array;
}
