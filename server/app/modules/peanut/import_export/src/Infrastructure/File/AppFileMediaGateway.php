<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\File;

use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Engine\File\FileMediaGateway;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class AppFileMediaGateway implements FileMediaGateway
{
    public function __construct(private FileStorage $storage) {}
    public function openCsvInput(AuthorizedOperationContext $context, string $fileKey)
    {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1) {
            throw ImportExportException::fileUnavailable();
        }
        $opened = $this->storage->openForTenant($context->tenantContext->tenantId, $fileKey);
        $source = null;
        $copy = null;
        try {
            $source = fopen($opened['path'], 'rb');
            $copy = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (!is_resource($source) || !is_resource($copy)) {
                throw ImportExportException::fileUnavailable();
            }
            $bytes = stream_copy_to_stream($source, $copy, 20 * 1024 * 1024 + 1);
            if (!is_int($bytes) || $bytes < 1 || $bytes > 20 * 1024 * 1024) {
                throw ImportExportException::limitExceeded();
            }
            rewind($copy);
            return $copy;
        } catch (\Throwable $error) {
            if (is_resource($copy)) {
                fclose($copy);
            }throw $error;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }if ($opened['temporary'] && is_file($opened['path'])) {
                @unlink($opened['path']);
            }
        }
    }
    public function storePrivateCsv(AuthorizedOperationContext $context, string $operationKey, string $purpose, string $filename, $stream): string
    {
        if (!is_resource($stream) || $purpose !== 'result' || preg_match('/^iox_[0-9a-f]{32}$/D', $operationKey) !== 1) {
            throw ImportExportException::fileUnavailable();
        }
        $temporary = tempnam(sys_get_temp_dir(), 'pa-csv-');
        if ($temporary === false) {
            throw ImportExportException::fileUnavailable();
        }
        $output = fopen($temporary, 'w+b');
        if (!is_resource($output)) {
            @unlink($temporary);
            throw ImportExportException::fileUnavailable();
        }
        try {
            $bytes = stream_copy_to_stream($stream, $output, 20 * 1024 * 1024 + 1);
            fclose($output);
            $output = null;
            if (!is_int($bytes) || $bytes < 1 || $bytes > 20 * 1024 * 1024) {
                throw ImportExportException::limitExceeded();
            }
            $stored = $this->storage->storePath($context->tenantContext->tenantId, $context->tenantContext->memberId, 'export.csv', $temporary, $filename, 'text/csv');
            return $stored['file_key'];
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }@unlink($temporary);
        }
    }
    public function download(AuthorizedOperationContext $context, string $fileKey): array
    {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1) {
            throw ImportExportException::fileUnavailable();
        }
        return ['url' => $this->storage->accessUrlForTenant($context->tenantContext->tenantId, $fileKey),'filename' => 'operation-logs.csv'];
    }
}
