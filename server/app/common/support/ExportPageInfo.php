<?php

declare(strict_types=1);

namespace app\common\support;

use InvalidArgumentException;

/** Mechanical metadata and bounded row ranges shared by paged export endpoints. */
final readonly class ExportPageInfo
{
    public const MAX_ROWS = 25000;

    public function __construct(
        public int $count,
        public int $pageSize,
        public int $sumPage,
        public int $maxPage,
        public int $allMaxSize,
        public int $pageStart,
        public int $pageEnd,
        public string $fileName,
    ) {}

    public static function from(
        int $count,
        int $pageSize,
        int $allMaxSize,
        string $fileName,
        int $pageEndLimit = 200,
    ): self {
        if ($pageSize < 1) {
            throw new InvalidArgumentException('Export page size must be positive.');
        }
        if ($allMaxSize < 1) {
            throw new InvalidArgumentException('Export maximum size must be positive.');
        }
        if ($pageEndLimit < 1) {
            throw new InvalidArgumentException('Export page end limit must be positive.');
        }

        $sumPage = max(1, (int) ceil(max(0, $count) / $pageSize));

        return new self(
            count: $count,
            pageSize: $pageSize,
            sumPage: $sumPage,
            maxPage: (int) floor($allMaxSize / $pageSize),
            allMaxSize: $allMaxSize,
            pageStart: 1,
            pageEnd: min($sumPage, $pageEndLimit),
            fileName: $fileName,
        );
    }

    /**
     * Resolve an explicit all-rows or page-range export without silently truncating it.
     * The caller must obtain count from its authorized, soft-delete-aware query.
     * @return array{0:int,1:int} Zero-based offset and bounded row count.
     */
    public function rowRange(int $pageType = 0, int $pageStart = 1, ?int $pageEnd = null): array
    {
        if (!in_array($pageType, [0, 1], true) || $this->pageSize < 1 || $this->allMaxSize < 1) {
            throw new InvalidArgumentException('EXPORT_RANGE_INVALID');
        }
        if ($this->count < 1) {
            throw new InvalidArgumentException('EXPORT_NO_DATA');
        }
        if ($pageType === 0) {
            if ($this->count > $this->allMaxSize) {
                throw new InvalidArgumentException('EXPORT_ALL_ROWS_EXCEEDS_LIMIT');
            }
            return [0, $this->count];
        }
        $pageEnd ??= $pageStart;
        // Check bounds before multiplication, including direct service callers.
        if ($pageStart < 1 || $pageEnd < $pageStart || $pageEnd > $this->sumPage
            || $pageEnd - $pageStart + 1 > intdiv($this->allMaxSize, $this->pageSize)) {
            throw new InvalidArgumentException('EXPORT_RANGE_INVALID');
        }
        $offset = ($pageStart - 1) * $this->pageSize;
        if ($offset >= $this->count) {
            throw new InvalidArgumentException('EXPORT_RANGE_NO_DATA');
        }
        return [$offset, min(($pageEnd - $pageStart + 1) * $this->pageSize, $this->count - $offset)];
    }

    /** @return array{count:int,page_size:int,sum_page:int,max_page:int,all_max_size:int,page_start:int,page_end:int,file_name:string} */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'page_size' => $this->pageSize,
            'sum_page' => $this->sumPage,
            'max_page' => $this->maxPage,
            'all_max_size' => $this->allMaxSize,
            'page_start' => $this->pageStart,
            'page_end' => $this->pageEnd,
            'file_name' => $this->fileName,
        ];
    }
}
