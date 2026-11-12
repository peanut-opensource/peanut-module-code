<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Tests\Auth;

use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PDO;
use PHPUnit\Framework\TestCase;
use ThinkPhpTestConnection;

require_once dirname(__DIR__, 4) . '/Support/ThinkPhpTestConnection.php';

/** Password hashing is a login cost, never a Token-service construction cost. No database is used. */
final class AuthRequestPasswordCostTest extends TestCase
{
    private const HMAC_KEY = 'synthetic-auth-cost-test-identifier-key-00000000000000';

    public function testTenantConstructionAndInvalidTokenDoNotUsePasswordHasher(): void
    {
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::never())->method('hash');
        $passwords->expects(self::never())->method('verify');
        $repository = $this->createMock(TenantAuthRepository::class);
        $repository->expects(self::never())->method('sessionByTokenHash');
        $service = $this->tenant($repository, $passwords);
        self::assertSame('admin-web', $service->client()->key);

        $this->expectException(AuthException::class);
        $service->context('invalid-token', 'auth-cost-tenant-token');
    }

    public function testPlatformConstructionAndInvalidTokenDoNotUsePasswordHasher(): void
    {
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::never())->method('hash');
        $passwords->expects(self::never())->method('verify');
        $repository = $this->createMock(PlatformAuthRepository::class);
        $repository->expects(self::never())->method('sessionByTokenHash');
        $service = $this->platform($repository, $passwords);

        $this->expectException(AuthException::class);
        $service->context('invalid-token', 'auth-cost-platform-token');
    }

    public function testTenantRateLimitedLoginDoesNotHash(): void
    {
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::never())->method('hash');
        $repository = $this->createMock(TenantAuthRepository::class);
        $repository->method('failedLoginCountByIp')->willReturn(20);
        $repository->expects(self::once())->method('recordSecurityEvent');
        $repository->expects(self::never())->method('credentialByEmail');
        $service = $this->tenant($repository, $passwords);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Too many authentication attempts.');
        $service->login('person@example.test', 'synthetic-password', null, '192.0.2.1', null, 'auth-cost-rate');
    }

    public function testPlatformRateLimitedLoginDoesNotHash(): void
    {
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::never())->method('hash');
        $repository = $this->createMock(PlatformAuthRepository::class);
        $repository->method('failedLoginCountByIp')->willReturn(20);
        $repository->expects(self::once())->method('recordEvent');
        $repository->expects(self::never())->method('principalByEmail');
        $service = $this->platform($repository, $passwords);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Too many authentication attempts.');
        $service->login('person@example.test', 'synthetic-password', '192.0.2.1', null, 'auth-cost-rate');
    }

    public function testUnknownTenantCredentialUsesTheFixedVerificationHash(): void
    {
        $connection = ThinkPhpTestConnection::fromPdo(new PDO('sqlite::memory:'));
        $repository = $this->createMock(TenantAuthRepository::class);
        $repository->expects(self::once())->method('failedLoginCountByIp')->willReturn(0);
        $repository->expects(self::once())->method('failedLoginCountByIdentifier')->willReturn(0);
        $repository->expects(self::once())->method('credentialByEmail')->willReturnCallback(static function () use ($connection): null {
            self::assertTrue($connection->getPdo()->inTransaction());
            return null;
        });
        $repository->expects(self::once())->method('registerFailedLogin');
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::never())->method('hash');
        $passwords->expects(self::once())->method('verify')
            ->with('synthetic-password', PasswordHasher::INVALID_CREDENTIAL_HASH)->willReturn(false);
        $service = $this->tenant($repository, $passwords);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Email or password is incorrect.');
        $service->login('person@example.test', 'synthetic-password', null, '192.0.2.1', null, 'auth-cost-login');
    }

    public function testUnknownPlatformPrincipalUsesTheFixedVerificationHash(): void
    {
        $connection = ThinkPhpTestConnection::fromPdo(new PDO('sqlite::memory:'));
        $repository = $this->createMock(PlatformAuthRepository::class);
        $repository->expects(self::once())->method('failedLoginCountByIp')->willReturn(0);
        $repository->expects(self::once())->method('failedLoginCountByIdentifier')->willReturn(0);
        $repository->expects(self::once())->method('principalByEmail')->willReturnCallback(static function () use ($connection): null {
            self::assertTrue($connection->getPdo()->inTransaction());
            return null;
        });
        $repository->expects(self::once())->method('registerFailedLogin');
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::never())->method('hash');
        $passwords->expects(self::once())->method('verify')
            ->with('synthetic-password', PasswordHasher::INVALID_CREDENTIAL_HASH)->willReturn(false);
        $service = $this->platform($repository, $passwords);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Email or password is incorrect.');
        $service->login('person@example.test', 'synthetic-password', '192.0.2.1', null, 'auth-cost-login');
    }

    private function tenant(TenantAuthRepository $repository, PasswordHasher $passwords): TenantAuthService
    {
        return new TenantAuthService($repository, $passwords, new SystemClock(), new TokenIssuer(), self::HMAC_KEY);
    }

    private function platform(PlatformAuthRepository $repository, PasswordHasher $passwords): PlatformAuthService
    {
        return new PlatformAuthService($repository, $passwords, new SystemClock(), new TokenIssuer(), self::HMAC_KEY);
    }
}
