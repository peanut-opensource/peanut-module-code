<?php

declare(strict_types=1);

use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ColumnNotIn;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\OrConstraint;
use PeanutAdmin\DataPermission\Constraint\TenantEquals;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Scope\AuthorizedReadScope;
use PeanutAdmin\DataPermission\Scope\SourceReadScope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;

final class SourceReadProjectionTest extends TestCase
{
    public function testTenantAndObjectsRemainPairedInTheQueryConstraint(): void
    {
        $scope = $this->scope([
            new SourceReadScope(202, [2], [3], ['id', 'quantity'], 'source-r1'),
            new SourceReadScope(303, null, [9], ['id', 'amount'], 'source-r2'),
        ], ['id']);
        self::assertSame(101, $scope->actor->tenantId);
        self::assertSame([202, 303], $scope->sourceTenantIds());
        self::assertSame(['id'], $scope->requestedFields);
        $scope->assertFields(['id']);

        $tenant = new ColumnReference('tenant_id');
        $object = new ColumnReference('id');
        $constraint = $scope->constraint($tenant, $object);
        self::assertInstanceOf(OrConstraint::class, $constraint);
        self::assertCount(2, $constraint->constraints);
        self::assertEquals([
            new TenantEquals($tenant, 202),
            new ColumnIn($object, [2]),
            new ColumnNotIn($object, [3]),
        ], $constraint->constraints[0]->constraints);
        self::assertEquals([
            new TenantEquals($tenant, 303),
            new ColumnNotIn($object, [9]),
        ], $constraint->constraints[1]->constraints);
    }

    public function testFieldsFromDifferentSourcesCannotBeCombined(): void
    {
        $scope = $this->scope([
            new SourceReadScope(202, [2], [], ['id', 'quantity'], 'source-r1'),
            new SourceReadScope(303, [3], [], ['id', 'amount'], 'source-r2'),
        ]);

        $this->expectException(DataAuthorizationException::class);
        $scope->assertFields(['quantity', 'amount']);
    }

    public function testEmptyProjectionFailsClosed(): void
    {
        $scope = $this->scope([new SourceReadScope(202, [2], [], ['id'], 'source-r1')]);

        $this->expectException(DataAuthorizationException::class);
        $scope->assertFields([]);
    }

    public function testRequestedFieldsMustBeGrantedByEverySource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->scope([
            new SourceReadScope(202, [2], [], ['id', 'quantity'], 'source-r1'),
            new SourceReadScope(303, [3], [], ['id'], 'source-r2'),
        ], ['quantity']);
    }

    /** @param non-empty-list<SourceReadScope> $sources */
    private function scope(array $sources, array $requestedFields = []): AuthorizedReadScope
    {
        $actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1, 'fixture-session', 101, 10, 11, 'admin-web',
            new DateTimeImmutable('2031-01-01T00:00:00Z'), 1, 1,
        ), 'fixture-read');

        return new AuthorizedReadScope($actor, 'inventory.summary', 'read', $sources, 'fixture-r1', $requestedFields);
    }
}
