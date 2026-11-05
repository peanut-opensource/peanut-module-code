<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\OAuth\Service\OfficialAccountApplicationService;
use PeanutAdmin\Modules\OAuth\Service\OfficialAccountReplyApplicationService;
use PHPUnit\Framework\TestCase;

final class OfficialAccountSecretRedactionBindings implements ExternalChannelBindings
{
    /** @param array<string,mixed> $configuration */
    public function __construct(private array $configuration) {}

    public function config(TenantContext $context, string $provider): array
    {
        TestCase::assertSame(ExternalProvider::WECHAT_OFFICIAL_CALLBACK, $provider);
        return $this->configuration;
    }

    public function callbackKey(TenantContext $context, string $provider): string
    {
        TestCase::assertSame(ExternalProvider::WECHAT_OFFICIAL_CALLBACK, $provider);
        return 'test-binding';
    }

    public function update(TenantContext $context, string $provider, array $config, string $identity): void
    {
        throw new LogicException('not used by this no-database test');
    }

    public function mutate(
        TenantContext $context,
        string $provider,
        string $identity,
        callable $mutator,
        ?callable $enabledResolver = null,
        ?string $identityHint = null,
    ): void {
        throw new LogicException('not used by this no-database test');
    }
}

final class OfficialAccountSecretRedactionFiles implements FileReferences
{
    public function getFileUrl(string $reference = ''): string
    {
        return $reference === '' ? '' : '/files/' . ltrim($reference, '/');
    }

    public function setTenantFileUrl(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $value = '',
    ): string {
        return $value;
    }
}

/** No-database proof that management reads and keep-secret writes share one sentinel. */
final class OfficialAccountSecretRedactionTest extends TestCase
{
    public function testManagementProjectionNeverReturnsStoredSecrets(): void
    {
        $storedAppSecret = 'stored-app-value';
        $storedToken = 'stored-token-value';
        $service = new OfficialAccountApplicationService(
            new OfficialAccountSecretRedactionBindings([
                'name' => 'Test account',
                'original_id' => 'test-original',
                'qr_code' => 'qr-code',
                'app_id' => 'test-app-id',
                'app_secret' => $storedAppSecret,
                'token' => $storedToken,
            ]),
            new OfficialAccountSecretRedactionFiles(),
            new OfficialAccountReplyApplicationService(),
        );
        $context = (new ReflectionClass(TenantContext::class))->newInstanceWithoutConstructor();
        $config = $service->getConfig($context, 'https://tenant.test');

        self::assertSame('******', $config['app_secret']);
        self::assertTrue($config['app_secret_configured']);
        self::assertSame('******', $config['token']);
        self::assertTrue($config['token_configured']);
        self::assertNotSame($storedAppSecret, $config['app_secret']);
        self::assertNotSame($storedToken, $config['token']);
        self::assertSame('plaintext', $config['callback_mode']);
    }

    public function testSecretSentinelRetainsExistingValueAndEmptyInputStillClearsOptionalToken(): void
    {
        $method = new ReflectionMethod(OfficialAccountApplicationService::class, 'retainedSecret');
        $method->setAccessible(true);

        self::assertSame('stored-value', $method->invoke(null, '******', 'stored-value'));
        self::assertSame('replacement-value', $method->invoke(null, 'replacement-value', 'stored-value'));
        self::assertSame('', $method->invoke(null, '', 'stored-value'));
    }

    public function testMetadataAndSingleFrontendConsumerUseTheRedactedTokenContract(): void
    {
        $root = dirname(__DIR__, 3);
        $metadata = require $root . '/server/app/modules/peanut/oauth/api/metadata/openapi.php';
        $schema = $metadata['components']['schemas']['OAuthOfficialAccountConfig'];
        self::assertContains('token_configured', $schema['required']);
        self::assertSame(['', '******'], $schema['properties']['token']['enum']);
        self::assertTrue($schema['properties']['token']['readOnly']);

        $api = (string) file_get_contents($root . '/web/src/modules/peanut-oauth/api.ts');
        $view = (string) file_get_contents(
            $root . '/web/src/modules/peanut-oauth/views/channel/OfficialAccountConfig.vue',
        );
        self::assertStringContainsString('token_configured: boolean', $api);
        self::assertStringContainsString('form.token_configured', $view);
        self::assertStringContainsString('type="password"', $view);
    }
}
