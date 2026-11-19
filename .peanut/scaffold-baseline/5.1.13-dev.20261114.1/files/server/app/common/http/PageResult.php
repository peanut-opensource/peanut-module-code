<?php

declare(strict_types=1);

namespace app\common\http;

use think\Paginator;

/** Framework-neutral page returned by application queries. */
final readonly class PageResult
{
    /**
     * @param list<mixed> $items
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
        public array $metadata = [],
    ) {
        if ($total < 0 || $page < 1 || $pageSize < 1) {
            throw new \InvalidArgumentException('PAGE_RESULT_INVALID');
        }
    }

    public function map(callable $transform): self
    {
        return new self(
            array_values(array_map($transform, $this->items)),
            $this->total,
            $this->page,
            $this->pageSize,
            $this->metadata,
        );
    }

    public static function fromPaginator(Paginator $paginator, ?int $requestedPage = null): self
    {
        return new self(
            $paginator->items(),
            $paginator->total(),
            $requestedPage ?? $paginator->currentPage(),
            $paginator->listRows(),
        );
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->items,
            $this->total,
            $this->page,
            $this->pageSize,
            $metadata,
        );
    }

    /** @return array{lists:list<mixed>,count:int,pageNo:int,pageSize:int} */
    public function responseData(): array
    {
        return [
            'lists' => $this->items,
            'count' => $this->total,
            'pageNo' => $this->page,
            'pageSize' => $this->pageSize,
        ] + $this->metadata;
    }
}
