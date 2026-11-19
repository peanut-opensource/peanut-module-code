<?php

declare(strict_types=1);

namespace app\common\services;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use ZipArchive;

/** 小型无外部依赖 XLSX 导出器，供管理端表格导出复用。 */
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
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('服务器未安装 ZipArchive 扩展，无法导出 XLSX');
        }
        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', trim($name)) ?: '导出数据';
        $name = preg_replace('/\.xlsx$/i', '', $name) ?: '导出数据';
        $fileName = $name . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.xlsx';
        $temporary = tempnam(sys_get_temp_dir(), 'pa-xlsx-');
        if ($temporary === false) {
            throw new \RuntimeException('导出临时文件创建失败');
        }
        @unlink($temporary);
        $path = $temporary . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('导出文件创建失败');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml(mb_substr($name, 0, 31))
            . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');
        array_unshift($rows, $headings);
        $zip->addFromString('xl/worksheets/sheet1.xml', self::worksheet($rows));
        $zip->close();
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

    private static function worksheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $xml .= '<row r="' . $rowNumber . '">';
            foreach (array_values((array) $row) as $columnIndex => $value) {
                $cell = self::columnName($columnIndex + 1) . $rowNumber;
                if (is_int($value) || is_float($value)) {
                    $numeric = rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
                    $xml .= '<c r="' . $cell . '"><v>' . $numeric . '</v></c>';
                    continue;
                }
                $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">'
                    . self::xml((string) $value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    private static function xml(string $value): string
    {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }
}
