<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Dto;

use PeanutAdmin\Modules\ReferenceCodes\DictionaryEntry;

final readonly class DictionaryEntryDto
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public int $id,
        public string $name,
        public string $value,
        public int $typeId = 0,
        public string $type = '',
        public int $sort = 0,
        public bool $disabled = false,
        public string $remark = '',
        public string $source = 'tenant',
        private array $attributes = [],
    ) {}

    public static function fromCore(DictionaryEntry $entry): self
    {
        return new self(
            $entry->id,
            $entry->name,
            $entry->value,
            $entry->typeId,
            $entry->type,
            $entry->sort,
            $entry->disabled,
            $entry->remark,
            $entry->source,
            $entry->toArray(),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        if ($this->attributes !== []) {
            $values = $this->attributes;
            foreach ([
                'id' => $this->id,
                'name' => $this->name,
                'value' => $this->value,
                'type_id' => $this->typeId,
                'type_value' => $this->type,
                'sort' => $this->sort,
                'is_disable' => $this->disabled ? 1 : 0,
                'remark' => $this->remark,
                'source' => $this->source,
            ] as $key => $value) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = $value;
                }
            }
            return $values;
        }

        $values = [
            'id' => $this->id,
            'name' => $this->name,
            'value' => $this->value,
            'type_id' => $this->typeId,
            'type_value' => $this->type,
            'sort' => $this->sort,
            'is_disable' => $this->disabled ? 1 : 0,
            'remark' => $this->remark,
        ];
        if ($this->source !== 'tenant') {
            $values['source'] = $this->source;
        }
        return $values;
    }
}
