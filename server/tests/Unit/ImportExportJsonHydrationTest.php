<?php

declare(strict_types=1);

namespace tests\Unit;

use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** 仅验证实际持久化适配的纯数据转换；真实读写另由 MySQL 任务测试覆盖。 */
final class ImportExportJsonHydrationTest extends TestCase
{
    public function testRawJsonAndOrmCastsHaveIdenticalMapping(): void
    {
        foreach ([[], ['姓名' => 'name', 'email' => 'email']] as $mapping) {
            self::assertSame($mapping, $this->decode($mapping));
            self::assertSame($mapping, $this->decode(json_encode($mapping, JSON_THROW_ON_ERROR)));
        }
        self::assertSame([], $this->decode('{}'));
    }

    public function testWrongShapesStillFailClosed(): void
    {
        foreach ([null, false, 1, 'null', '{', '"field"', '["name"]', ['name'], ['name' => 1], ['name' => []], new \stdClass()] as $stored) {
            try {
                $this->decode($stored);
                self::fail('Invalid field mapping was accepted.');
            } catch (ImportExportException $exception) {
                self::assertSame('IMPORT_EXPORT_INTERNAL_ERROR', $exception->problemCode);
                self::assertSame('IMPORT_EXPORT_INTERNAL_ERROR', $exception->getMessage());
            }
        }
    }

    public function testOrmRecordsPreserveMysqlMillisecondPrecision(): void
    {
        foreach ([
            \PeanutAdmin\Modules\ImportExport\Engine\Persistence\Model\ImportExportOperationRecord::class,
            \PeanutAdmin\Modules\ImportExport\Engine\Persistence\Model\ImportExportRowErrorRecord::class,
        ] as $type) {
            // 单元检查默认精度和真实框架格式器；完整模型创建/读写由 MySQL 组验证。
            $format = (new ReflectionClass($type))->getDefaultProperties()['dateFormat'];
            self::assertSame('Y-m-d H:i:s.v', $format);
            $value = new \think\model\type\DateTime();
            $value->data('2026-09-22 12:34:56.789', $format);
            self::assertSame('2026-09-22 12:34:56.789', $value->value());
        }
    }

    private function decode(mixed $stored): array
    {
        $type = new ReflectionClass(ImportExportStore::class);
        return $type->getMethod('mapping')->invoke($type->newInstanceWithoutConstructor(), $stored);
    }
}
