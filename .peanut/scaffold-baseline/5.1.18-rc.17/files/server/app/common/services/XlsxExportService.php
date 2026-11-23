<?php

declare(strict_types=1);

namespace app\common\services;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\ImportExport\SpreadsheetWriter;

/** Private XLSX exports through the canonical file service. */
class XlsxExportService
{
    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly FileStorage $storage,
    ) {}

    /** Creates a private XLSX through the canonical storage service. */
    public function create(
        string $name,
        array $headings,
        array $rows,
    ): array {
        $tenantId = $this->trustedTenantId();
        $memberId = $this->executionContext->tenantAdmin()->memberId;
        // Export authorization never implicitly grants File's independent download right.
        $this->storage->requireOriginalDownloadPermission($tenantId);
        [$path,$filename] = self::createArtifact($name, $headings, $rows);
        try {
            return $this->storage->storePath(
                $tenantId,
                (int) $memberId,
                'export.xlsx',
                $path,
                $filename,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
        } finally {
            @unlink($path);
        }
    }

    private static function createArtifact(
        string $name,
        array $headings,
        array $rows,
    ): array {
        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', trim($name)) ?: '导出数据';
        $name = preg_replace('/\.xlsx$/i', '', $name) ?: '导出数据';
        $fileName = $name . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.xlsx';
        $temporary = tempnam(sys_get_temp_dir(), 'pa-xlsx-');
        if ($temporary === false) {
            throw new \RuntimeException('导出临时文件创建失败');
        }
        @unlink($temporary);
        $path = $temporary . '.xlsx';
        try {
            $normalized = static function () use ($headings, $rows): \Generator {
                foreach ((static function () use ($headings, $rows): \Generator { yield $headings; yield from $rows; })() as $row) {
                    yield array_map(static function ($value) {
                        if (is_int($value) || is_float($value)) {
                            // Match the previous export's ten-decimal numeric representation.
                            return (float) rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
                        }
                        return preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $value) ?? '';
                    }, array_values((array) $row));
                }
            };
            (new SpreadsheetWriter())->write($path, $normalized(), mb_substr($name, 0, 31));
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        return [$path,$fileName];
    }

    private function trustedTenantId(): int
    {
        $context = $this->executionContext->tenantAdmin();
        if ($context->tenantId < 1
            || $context->accountId < 1
            || $context->memberId < 1
            || $context->authorizationRevision < 1
            || $context->sessionKey === ''
            || $context->clientKey === ''
            || $context->requestId === '') {
            throw new \InvalidArgumentException('可信租户上下文缺失');
        }
        return TenantScope::fromTrustedContext($context->tenantId, $context->requestId)->tenantId();
    }

}
