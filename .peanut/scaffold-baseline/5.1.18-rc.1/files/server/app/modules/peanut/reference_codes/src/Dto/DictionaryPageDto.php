<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Dto;

final readonly class DictionaryPageDto
{
    /** @param list<DictionaryTypeDto|DictionaryEntryDto> $items */
    public function __construct(
        public array $items,
        public int $count,
        public int $page,
        public int $pageSize,
    ) {}

    /** @return array{lists:list<array<string,mixed>>,count:int,pageNo:int,pageSize:int} */
    public function toArray(): array
    {
        return [
            'lists' => array_map(static fn(DictionaryTypeDto|DictionaryEntryDto $item): array => $item->toArray(), $this->items),
            'count' => $this->count,
            'pageNo' => $this->page,
            'pageSize' => $this->pageSize,
        ];
    }
}
