<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use app\common\exception\BusinessException;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Modules\File\Service\FileAuthorizationService;
use PeanutAdmin\Modules\File\Service\FileDocumentReferenceRegistry;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;

final class FilePreviewPageAuthorizationTest extends TestCase
{
    #[DataProvider('permissions')]
    public function testPageUsesOneLiveAuthorizationAndPreservesSinglePreviewGrant(bool $canDownload): void
    {
        $actor = $this->actor();
        $sessions = $this->createMock(TenantSessionAccess::class);
        $sessions->expects(self::exactly(2))->method('revalidateSession')
            ->with(101, 3, 7, $actor->sessionKey, 'admin-web', $actor->requestId)->willReturn($actor);
        $authorization = $this->createMock(AdminAuthorizationQuery::class);
        $authorization->method('principal')->willReturn($this->principal($actor));
        $authorization->expects(self::once())->method('decisions')
            ->with($actor, ['peanut.file.list', 'peanut.file.download-original'])->willReturn([
                'peanut.file.list' => PermissionDecision::allow('peanut.file.list'),
                'peanut.file.download-original' => $canDownload
                    ? PermissionDecision::allow('peanut.file.download-original')
                    : PermissionDecision::deny('peanut.file.download-original', 'PERMISSION_NOT_GRANTED'),
            ]);
        $authorization->expects(self::once())->method('decide')->willReturn(PermissionDecision::allow('peanut.file.list'));
        $service = $this->service($sessions, $authorization);
        $first = ['file_key' => 'first', 'tenant_id' => 101, 'status' => 'ready'];
        $page = $service->authorizePreviewPage($actor);

        self::assertSame($canDownload, $page['can_download_original']);
        $grant = $service->authorize($actor, $first, 'preview');
        self::assertSame($grant['authorization_revision'], $page['grant']['authorization_revision']);
        self::assertSame($grant['context'], $page['grant']['context']);
        self::assertSame($grant['reference_digest'], $page['grant']['reference_digest']);
        self::assertSame($actor->tenantAuthorizationRevision, $page['grant']['tenant_authorization_revision']);
    }

    public static function permissions(): iterable
    {
        yield 'preview and download' => [true];
        yield 'preview alone' => [false];
    }

    #[DataProvider('unreadableObjects')]
    public function testEachObjectMustBeReadyAndBelongToTheActorTenant(int $tenantId, string $status): void
    {
        $sessions = $this->createMock(TenantSessionAccess::class);
        $sessions->expects(self::never())->method('revalidateSession');
        $service = $this->service($sessions, $this->createStub(AdminAuthorizationQuery::class));
        $this->expectException(BusinessException::class);
        $service->assertReadableObject($this->actor(), ['tenant_id' => $tenantId, 'status' => $status]);
    }

    public static function unreadableObjects(): iterable
    {
        yield 'foreign tenant' => [202, 'ready'];
        yield 'unavailable object' => [101, 'archived'];
    }

    public function testSessionForWrongMemberCannotIssuePageGrants(): void
    {
        $actor = $this->actor(8);
        $sessions = $this->createMock(TenantSessionAccess::class);
        $sessions->expects(self::once())->method('revalidateSession')
            ->with(101, 3, 8, $actor->sessionKey, 'admin-web', $actor->requestId)
            ->willThrowException(TenantSessionAccessException::denied());
        $authorization = $this->createMock(AdminAuthorizationQuery::class);
        $authorization->expects(self::never())->method('decisions');
        $this->expectException(TenantSessionAccessException::class);
        $this->service($sessions, $authorization)->authorizePreviewPage($actor);
    }

    public function testDownloadPermissionAloneCannotIssuePageGrants(): void
    {
        $actor = $this->actor();
        $sessions = $this->createStub(TenantSessionAccess::class);
        $sessions->method('revalidateSession')->willReturn($actor);
        $authorization = $this->createStub(AdminAuthorizationQuery::class);
        $authorization->method('principal')->willReturn($this->principal($actor));
        $authorization->method('decisions')->willReturn([
            'peanut.file.list' => PermissionDecision::deny('peanut.file.list', 'PERMISSION_NOT_GRANTED'),
            'peanut.file.download-original' => PermissionDecision::allow('peanut.file.download-original'),
        ]);
        $this->expectException(BusinessException::class);
        $this->service($sessions, $authorization)->authorizePreviewPage($actor);
    }

    public function testChangedMemberRevisionStopsPageAuthorization(): void
    {
        $sessions = $this->createStub(TenantSessionAccess::class);
        $sessions->method('revalidateSession')->willReturn($this->actor(revision: 4));
        $authorization = $this->createMock(AdminAuthorizationQuery::class);
        $authorization->expects(self::never())->method('decisions');
        try {
            $this->service($sessions, $authorization)->authorizePreviewPage($this->actor());
            self::fail('A stale member revision authorized the page');
        } catch (BusinessException $error) {
            self::assertSame('FILE_AUTHORIZATION_STALE', $error->errorCode);
        }
    }

    #[DataProvider('decisionFailures')]
    public function testOnlyUnavailablePrincipalIsMappedToFileDenial(string $message, bool $deny): void
    {
        $actor = $this->actor();
        $sessions = $this->createStub(TenantSessionAccess::class);
        $sessions->method('revalidateSession')->willReturn($actor);
        $authorization = $this->createStub(AdminAuthorizationQuery::class);
        $authorization->method('principal')->willReturn($this->principal($actor));
        $failure = new \DomainException($message);
        $authorization->method('decisions')->willThrowException($failure);

        try {
            $this->service($sessions, $authorization)->authorizePreviewPage($actor);
            self::fail('The authorization failure was ignored');
        } catch (BusinessException|\DomainException $error) {
            if ($deny) {
                self::assertInstanceOf(BusinessException::class, $error);
                self::assertSame('FILE_ACCESS_DENIED', $error->errorCode);
            } else {
                self::assertSame($failure, $error);
            }
        }
    }

    public static function decisionFailures(): iterable
    {
        yield 'unavailable principal' => ['TENANT_ADMIN_PRINCIPAL_UNAVAILABLE', true];
        yield 'unrelated domain error' => ['UNEXPECTED_PERMISSION_INPUT', false];
    }

    private function actor(int $memberId = 7, int $revision = 3): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1, '01K00000000000000000000001', 101, 3, $memberId, 'admin-web',
            new \DateTimeImmutable('2026-01-01T00:00:00Z'), $revision, 5,
        ), 'asset-page-test');
    }

    private function principal(TenantContext $context): AdminPrincipal
    {
        return AdminPrincipal::fromArray([
            'id' => $context->memberId, 'tenant_id' => $context->tenantId, 'account_id' => $context->accountId,
            'authorization_revision' => $context->authorizationRevision,
        ]);
    }

    private function service(TenantSessionAccess $sessions, AdminAuthorizationQuery $authorization): FileAuthorizationService
    {
        $modules = $this->createStub(ModuleRuntimeRepository::class);
        $modules->method('installation')->willReturn(new ModuleInstallationRecord('peanut.file', '1.0.50-rc.4', 'active', 1, str_repeat('a', 64)));
        $modules->method('tenantModule')->willReturn(new TenantModuleRecord(101, 'peanut.file', 'enabled', null, null, 1));
        return new FileAuthorizationService($authorization, $sessions, $modules, new ExecutionContextStore(), new FileDocumentReferenceRegistry(new App(), null));
    }
}
