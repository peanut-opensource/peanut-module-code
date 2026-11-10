<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Dto;

use PeanutAdmin\Modules\ReferenceCodes\DictionaryType;

final readonly class DictionaryTypeDto
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public bool $disabled,
        public string $remark = '',
        private array $attributes = [],
    ) {}

    public static function fromCore(DictionaryType $type): self
    {
        return new self($type->id, $type->name, $type->type, $type->disabled, $type->remark, $type->toArray());
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        if ($this->attributes !== []) {
            $values = $this->attributes;
            foreach ([
                'id' => $this->id,
                'name' => $this->name,
                'type' => $this->type,
                'is_disable' => $this->disabled ? 1 : 0,
                'remark' => $this->remark,
            ] as $key => $value) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = $value;
                }
            }
            return $values;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_disable' => $this->disabled ? 1 : 0,
            'remark' => $this->remark,
        ];
    }
}
