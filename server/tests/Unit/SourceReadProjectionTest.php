<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Identity\access\SourceRead\SourceReadCapability;
use PeanutAdmin\Modules\Identity\access\SourceRead\SourceReadProjection;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PHPUnit\Framework\TestCase;

/** 逐对象字段许可的纯规则测试：拒绝跨对象字段并集，保留合法增强。 */
final class SourceReadProjectionTest extends TestCase
{
    private const FIELDS = ['id', 'quantity', 'amount'];

    public function testRecipientAndSourceModuleAreIndependent(): void
    {
        $definition = new SourceReadCapability('inventory.summary', 'read', 'official.inventory', 'summary.read', 'sharing.manage', self::FIELDS, 'official.summary');
        self::assertSame('official.inventory', $definition->moduleKey);
        self::assertSame('official.summary', $definition->recipientModuleKey);
        $legacy = new SourceReadCapability('inventory.summary', 'read', 'official.inventory', 'summary.read', 'sharing.manage', self::FIELDS);
        self::assertSame('official.inventory', $legacy->recipientModuleKey);
    }

    public function testRequestedFieldsOnlyIncludeMatchingObjects(): void
    {
        $allows = [
            ['object_id' => 1, 'fields' => ['id', 'quantity']],
            ['object_id' => 2, 'fields' => ['id', 'amount']],
        ];
        self::assertSame([[1], ['id', 'quantity']], SourceReadProjection::resolve($allows, [], self::FIELDS, ['quantity', 'id']));
        self::assertSame([[2], ['amount', 'id']], SourceReadProjection::resolve($allows, [], self::FIELDS, ['id', 'amount']));
    }

    public function testDifferentObjectsCannotCombineFieldsToCreateNewPermission(): void
    {
        $this->expectException(DataAuthorizationException::class);
        SourceReadProjection::resolve([
            ['object_id' => 1, 'fields' => ['id', 'quantity']],
            ['object_id' => 2, 'fields' => ['id', 'amount']],
        ], [], self::FIELDS, ['quantity', 'amount']);
    }

    public function testAdditionalUnrelatedGrantDoesNotRemoveExistingExplicitProjection(): void
    {
        $original = [['object_id' => 1, 'fields' => ['id', 'quantity']]];
        $before = SourceReadProjection::resolve($original, [], self::FIELDS, ['id', 'quantity']);
        $original[] = ['object_id' => 2, 'fields' => ['id', 'amount']];
        self::assertSame($before, SourceReadProjection::resolve($original, [], self::FIELDS, ['id', 'quantity']));
    }

    public function testGlobalAndSameObjectPermissionsComposeWithoutOpeningOtherObjects(): void
    {
        self::assertSame([[2], ['amount', 'id']], SourceReadProjection::resolve([
            ['object_id' => null, 'fields' => ['id']],
            ['object_id' => 2, 'fields' => ['amount']],
        ], [], self::FIELDS, ['id', 'amount']));
    }

    public function testGlobalCompleteProjectionKeepsAllObjects(): void
    {
        self::assertSame([null, ['id', 'quantity']], SourceReadProjection::resolve([
            ['object_id' => null, 'fields' => ['id', 'quantity']],
            ['object_id' => 2, 'fields' => ['id']],
        ], [3], self::FIELDS, ['id', 'quantity']));
        // 返回 null 只表示允许全集；deny 列表由 SourceReadScope 独立强制执行。
    }

    public function testExplicitDenyRemovesAnOtherwisePermittedObject(): void
    {
        self::assertSame([[1], ['amount']], SourceReadProjection::resolve([
            ['object_id' => 1, 'fields' => ['amount']],
            ['object_id' => 2, 'fields' => ['amount']],
        ], [2], self::FIELDS, ['amount']));
    }

    public function testLegacyCommonProjectionIsExplicitlyConservative(): void
    {
        self::assertSame([[1, 2], ['id']], SourceReadProjection::resolve([
            ['object_id' => 1, 'fields' => ['id', 'quantity']],
            ['object_id' => 2, 'fields' => ['id', 'amount']],
        ], [], self::FIELDS, []));
    }

    public function testSameObjectAllowsCanCombine(): void
    {
        self::assertSame([[1], ['amount', 'id']], SourceReadProjection::resolve([
            ['object_id' => 1, 'fields' => ['id']],
            ['object_id' => 1, 'fields' => ['amount']],
        ], [], self::FIELDS, ['amount', 'id']));
    }

    public function testUnknownFieldIsRejected(): void
    {
        $this->expectException(DataAuthorizationException::class);
        SourceReadProjection::requestedFields(['password_hash'], self::FIELDS);
    }

    public function testDuplicateFieldIsRejected(): void
    {
        $this->expectException(DataAuthorizationException::class);
        SourceReadProjection::requestedFields(['id', 'id'], self::FIELDS);
    }

    public function testEmptyAvailableObjectsFailClosed(): void
    {
        $this->expectException(DataAuthorizationException::class);
        SourceReadProjection::resolve([['object_id' => 1, 'fields' => ['id']]], [1], self::FIELDS, []);
    }
}
