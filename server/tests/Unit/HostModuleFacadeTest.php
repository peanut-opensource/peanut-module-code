<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\OAuth\Service\OAuthBrowserCallbackService;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot;
use PeanutAdmin\Modules\Settings\Contract\WebsiteConfigStore;
use PeanutAdmin\Modules\Settings\Application\WebsiteConfigService as WebsiteValues;
use PeanutAdmin\Modules\Settings\Infrastructure\BrandDefaults;
use PeanutAdmin\Modules\Settings\Service\TenantSettingService;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use PHPUnit\Framework\TestCase;
use think\App;

/** Executes existing facade logic; persistence and file storage are explicit test doubles. */
final class HostModuleFacadeTest extends TestCase
{
    public function testPublishedFacadesDoNotExposeTheirPersistenceCollaborators(): void
    {
        foreach (['settings' => WebsiteConfigService::class, 'oauth' => OAuthBrowserCallbackService::class] as $module => $facade) {
            $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/peanut/' . $module . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertContains($facade, $manifest['contracts']['exports']);
            foreach ($manifest['contracts']['exports'] as $export) {
                self::assertFalse(\app\platform\validation\module\ModulePublicSurfacePolicy::isInternalPersistence($export));
            }
        }
    }

    public function testDefaultsAreFixedPublicValuesAndDoNotRequirePersistence(): void
    {
        $defaults = WebsiteConfigService::defaults();
        self::assertSame(BrandDefaults::website(), $defaults);
        self::assertSame(WebsiteConfigService::fields(), array_keys([...BrandDefaults::website(), ...BrandDefaults::sidebarFooter()]));
        $defaults['name'] = 'local change';
        self::assertNotSame($defaults, WebsiteConfigService::defaults());
        foreach (['common/services/readiness/FirstRunReadinessHost.php', 'platform/services/ApplicationTenantBootstrapService.php'] as $path) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/app/' . $path);
            self::assertStringNotContainsString('Settings\\Infrastructure\\BrandDefaults', $source);
            self::assertStringContainsString('WebsiteConfigService::defaults()', $source);
        }
    }

    public function testNativeContainerResolvesTheExistingFacade(): void
    {
        $app = new App(dirname(__DIR__, 2));
        $app->instance(FileReferences::class, $this->createStub(FileReferences::class));
        self::assertInstanceOf(WebsiteConfigService::class, $app->make(WebsiteConfigService::class));
    }

    public function testReadPreservesTenantSelectionAndImageMapping(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/modules/peanut/settings/src/Infrastructure/TenantSettingWebsiteStore.php');
        self::assertStringContainsString('$this->context,', $source);
        self::assertStringContainsString("'website'", $source);
        $persistence = file_get_contents(dirname(__DIR__, 2) . '/app/modules/peanut/settings/src/Service/TenantSettingService.php');
        self::assertStringContainsString('$tenantId = $context->tenantId;', $persistence);
        self::assertStringContainsString('$this->provider->find($tenantId, $namespace)', $persistence);
        foreach ([101, 202] as $tenant) {
            $store = $this->createMock(WebsiteConfigStore::class);
            $store->expects(self::once())->method('read')->willReturn(['name' => 'Tenant ' . $tenant, 'web_logo' => 'tenant/' . $tenant . '.svg', 'private_extra' => 'not public']);
            $store->expects(self::never())->method('replaceAtomically');
            $service = new WebsiteValues($store, static fn(string $path): string => 'public:' . $path, static fn(string $path): string => $path, [...BrandDefaults::website(), ...BrandDefaults::sidebarFooter()]);
            $result = $service->get();
            self::assertSame('Tenant ' . $tenant, $result['name']);
            self::assertSame('public:tenant/' . $tenant . '.svg', $result['web_logo']);
            self::assertArrayNotHasKey('private_extra', $result);
            self::assertSame(WebsiteConfigService::fields(), array_keys($result));
        }
    }

    public function testSaveKeepsTenantScopedFileMappingAndOneCompleteWrite(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/modules/peanut/settings/src/Service/WebsiteConfigService.php');
        self::assertStringContainsString('$this->files->setTenantFileUrl($context, $value)', $source);
        $store = $this->createMock(WebsiteConfigStore::class);
        $store->expects(self::once())->method('read')->willReturn([]);
        $store->expects(self::once())->method('replaceAtomically')->willReturnCallback(static function (array $document): void {
            self::assertSame(WebsiteConfigService::fields(), array_keys($document));
            self::assertSame('Tenant A', $document['name']);
            self::assertSame('stored:logo.svg', $document['web_logo']);
        });
        $service = new WebsiteValues($store, static fn(string $path): string => $path, static fn(string $path): string => 'stored:' . $path, [...BrandDefaults::website(), ...BrandDefaults::sidebarFooter()]);
        $service->save(['name' => ' Tenant A ', 'web_logo' => ' logo.svg ']);
    }

    public function testInvalidWebsiteInputAndStorageFailureRemainFailures(): void
    {
        $store = $this->createMock(WebsiteConfigStore::class);
        $store->expects(self::once())->method('replaceAtomically')->willThrowException(new DomainException('FIXTURE_STORAGE_FAILURE'));
        $service = new WebsiteValues($store, static fn(string $path): string => $path, static fn(string $path): string => $path, [...BrandDefaults::website(), ...BrandDefaults::sidebarFooter()]);
        foreach ([['name' => []], ['name' => 'Tenant A', 'tenant_id' => 202], ['name' => 'Tenant A', 'private_extra' => 'rejected']] as $input) {
            try {
                $service->save($input);
                self::fail('Invalid values reached persistence');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIXTURE_STORAGE_FAILURE');
        $service->save(['name' => 'Tenant A']);
    }

    public function testBrowserCallbacksKeepRoutesAndDoNotForwardUnapprovedFields(): void
    {
        self::assertSame('https://product.test/api/oauth/wechat/redirect/pc', OAuthBrowserCallbackService::callbackUrl('https://product.test/', 'open_pc'));
        self::assertSame('/pc/oauth/callback?code=code%20value&state=state', OAuthBrowserCallbackService::clientRedirectUrl('pc', ['code' => ' code value ', 'state' => 'state', 'ticket' => 'private', 'redirect' => 'https://other.test', 'error' => ['invalid']]));
        self::assertSame('/mobile/#/pages/oauth/callback?scene=oa&code=oa', OAuthBrowserCallbackService::clientRedirectUrl('official-account', ['code' => 'oa']));
        $this->expectException(InvalidArgumentException::class);
        OAuthBrowserCallbackService::clientRedirectUrl('unregistered', []);
    }

    public function testAuthorizationUsesTheStablePublicResourceIdentifier(): void
    {
        self::assertSame('peanut.import-export', ImportExportCommands::RESOURCE_KEY);
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/services/authorization/AdminAuthorizationService.php');
        self::assertStringNotContainsString('ImportExport\\Engine\\Application\\ImportExportService', $source);
        self::assertStringContainsString(ImportExportCommands::RESOURCE_KEY, $source);
        self::assertStringContainsString('peanut.import-export.operation-log.export', $source);
    }

    private function context(int $tenant): TenantSystemContext
    {
        return new TenantSystemContext($tenant, 'fixture', 'fixture.website', 'fixture-request');
    }
}
