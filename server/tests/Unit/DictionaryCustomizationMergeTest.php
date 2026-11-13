<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\ReferenceCodes\Application\DictionaryService;
use PeanutAdmin\Modules\ReferenceCodes\Contract\DictionaryCustomizationQuery;
use PeanutAdmin\Modules\ReferenceCodes\Contract\SystemDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommandProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryQueryProvider;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryEntry;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/** Pure read-resolution tests. No database, migration or running application required. */
#[AllowMockObjectsWithoutExpectations]
final class DictionaryCustomizationMergeTest extends TestCase
{
    private function context(): TenantContext
    {
        return TenantContext::fromValidatedSession(
            new ValidatedTenantSession(1, str_repeat('a', 26), 101, 201, 301, 'admin-web', new DateTimeImmutable('+1 hour'), 1, 1),
            'dict-test',
        );
    }

    private function entry(string $value, string $name, string $source): DictionaryEntry
    {
        return DictionaryEntry::fromArray(['id' => 1, 'value' => $value, 'name' => $name], $source);
    }

    public function testExtendHidesAndRenamesPlatformValuesWithoutMutatingStableCodes(): void
    {
        $tenant = $this->createMock(TenantDictionaryQueryProvider::class);
        $tenant->expects(self::once())->method('enabledEntriesByType')
            ->willReturn([$this->entry('open', '自定义同值', 'tenant'), $this->entry('custom', '新增', 'tenant')]);
        $system = $this->createMock(SystemDictionaryProvider::class);
        $system->expects(self::once())->method('enabledEntriesByType')
            ->willReturn([$this->entry('open', '公开', 'system'), $this->entry('closed', '关闭', 'system')]);
        $policy = $this->createMock(DictionaryCustomizationQuery::class);
        $policy->method('mode')->willReturn('extend');
        $policy->method('overrides')->willReturn([
            'open' => ['action' => 'rename', 'name' => '本租户开放'],
            'closed' => ['action' => 'hide', 'name' => null],
        ]);
        $service = new DictionaryService(
            $tenant,
            $this->createMock(TenantDictionaryCommandProvider::class),
            $system,
            $policy,
        );
        $result = $service->enabledByType($this->context(), 'status');
        self::assertSame(['open', 'custom'], array_map(static fn(DictionaryEntry $e): string => $e->value, $result));
        self::assertSame(['本租户开放', '新增'], array_map(static fn(DictionaryEntry $e): string => $e->name, $result));
        self::assertSame(['tenant-override', 'tenant'], array_map(static fn(DictionaryEntry $e): string => $e->source, $result));
    }

    #[DataProvider('modes')]
    public function testScopeModeSelectsOnlyItsPermittedSource(string $mode, int $systemReads, int $tenantReads, array $names): void
    {
        $tenant = $this->createMock(TenantDictionaryQueryProvider::class);
        $tenant->expects(self::exactly($tenantReads))->method('enabledEntriesByType')
            ->willReturn([$this->entry('tenant', '租户', 'tenant')]);
        $system = $this->createMock(SystemDictionaryProvider::class);
        $system->expects(self::exactly($systemReads))->method('enabledEntriesByType')
            ->willReturn([$this->entry('system', '平台', 'system')]);
        $policy = $this->createMock(DictionaryCustomizationQuery::class);
        $policy->method('mode')->willReturn($mode);
        $policy->expects(self::never())->method('overrides');
        $service = new DictionaryService(
            $tenant,
            $this->createMock(TenantDictionaryCommandProvider::class),
            $system,
            $policy,
        );
        self::assertSame($names, array_map(
            static fn(DictionaryEntry $entry): string => $entry->name,
            $service->enabledByType($this->context(), 'status'),
        ));
    }

    public static function modes(): array
    {
        return [
            'platform only' => ['system', 1, 0, ['平台']],
            'tenant only' => ['tenant', 0, 1, ['租户']],
            'disabled platform type' => ['disabled', 0, 0, []],
        ];
    }
}
