<?php

declare(strict_types=1);

namespace app\common\validate;

/**
 * 一次成功校验的不可变输入快照。
 *
 * raw 保留审计所需的原始形状；validated 只含动作明确接受的字段；writable
 * 进一步缩小到可交给持久化层的字段。旧入口未声明字段政策时 all() 仍保持
 * 原有完整输入，避免一次性改变所有既有 Controller。
 */
final readonly class ValidatedInput
{
    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $validated
     * @param array<string,mixed> $writable
     */
    public function __construct(
        private array $raw,
        private array $validated,
        private array $writable,
    ) {}

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->validated;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->raw;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    /** @return array<string,mixed> */
    public function writable(): array
    {
        return $this->writable;
    }
}
