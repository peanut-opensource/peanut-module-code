<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\validation\module\ModulePublicSurfacePolicy;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCallbackLocator;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCommands;
use PeanutAdmin\Modules\Payment\Contract\PaymentMethod;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/** Only existing stable host-facing contracts are checked; no persistence type is promoted. */
final class HostPublicContractsTest extends TestCase
{
    private function exports(string $module): array
    {
        $path = dirname(__DIR__, 2) . '/app/modules/peanut/' . $module . '/module.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return $manifest['contracts']['exports'];
    }

    public function testTheExistingPaymentMethodValueIsDeclaredWithoutChangingProviderCodes(): void
    {
        self::assertContains(PaymentMethod::class, $this->exports('payment'));
        self::assertFalse(ModulePublicSurfacePolicy::isInternalPersistence(PaymentMethod::class));
        self::assertSame(2, PaymentMethod::WECHAT);
        self::assertSame(3, PaymentMethod::ALIPAY);
        self::assertTrue(PaymentMethod::isProvider(2));
        self::assertTrue(PaymentMethod::isProvider(3));
        self::assertFalse(PaymentMethod::isProvider(0));
    }

    public function testTheExistingOAuthCandidatePortIsExplicitRatherThanAnImplicitInternalImport(): void
    {
        self::assertContains(OAuthCallbackLocator::class, $this->exports('oauth'));
        self::assertTrue((new ReflectionClass(OAuthCallbackLocator::class))->isInterface());
        self::assertFalse(ModulePublicSurfacePolicy::isInternalPersistence(OAuthCallbackLocator::class));
        self::assertSame('array', (string) (new ReflectionClass(OAuthCallbackLocator::class))->getMethod('locateState')->getReturnType());
    }

    public function testOAuthCommandResultTypesArePublicReadonlyValues(): void
    {
        $seen = [];
        foreach ((new ReflectionClass(OAuthCommands::class))->getMethods() as $method) {
            $return = $method->getReturnType();
            if (!$return instanceof ReflectionNamedType || !str_starts_with($return->getName(), 'PeanutAdmin\\Modules\\OAuth\\Contract\\Dto\\')) {
                continue;
            }
            $type = $return->getName();
            self::assertContains($type, $this->exports('oauth'));
            self::assertTrue((new ReflectionClass($type))->isReadOnly());
            self::assertFalse(ModulePublicSurfacePolicy::isInternalPersistence($type));
            $seen[$type] = true;
        }
        self::assertCount(2, $seen);
    }
}
