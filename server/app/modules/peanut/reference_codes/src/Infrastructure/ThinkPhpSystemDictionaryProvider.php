<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Infrastructure;

use PeanutAdmin\Modules\ReferenceCodes\Contract\SystemDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryEntry;
use think\facade\Db;

/** Reads deployment-owned immutable dictionary values through ThinkPHP. */
final class ThinkPhpSystemDictionaryProvider implements SystemDictionaryProvider
{
    /** @return list<DictionaryEntry> */
    public function enabledEntriesByType(string $type): array
    {
        $rows = Db::name('system_dict_data')->alias('d')->join('system_dict_type t', 't.code = d.type_code')
            ->where('d.type_code', $type)->where('d.is_disable', 0)->where('t.is_disable', 0)
            ->field('d.id,d.name,d.value,d.sort')->order(['d.sort' => 'desc', 'd.id' => 'desc'])->select()->toArray();
        return array_map(static fn(array $row): DictionaryEntry => DictionaryEntry::fromArray($row + ['source' => 'system'], 'system'), $rows);
    }
}
