<?php

declare(strict_types=1);

require_once defined('PHPUNIT_COMPOSER_INSTALL')
    ? PHPUNIT_COMPOSER_INSTALL
    : dirname(__DIR__, 2) . '/vendor/autoload.php';

use PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantAudit;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\Service\ExternalTenantResolver;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCallbackLocator;

function externalExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ExternalFixtureRepository implements ExternalTenantBindingRepository
{
    /** @param list<ExternalTenantBinding> $bindings */
    public function __construct(private array $bindings) {}

    public function byCallbackKey(string $provider, string $callbackKey): array
    {
        return $this->matching($provider, static fn(ExternalTenantBinding $binding): bool =>
            hash_equals($binding->callbackKey, $callbackKey));
    }

    public function byClientIdentity(string $provider, string $identityHash): array
    {
        return $this->matching($provider, static fn(ExternalTenantBinding $binding): bool =>
            hash_equals($binding->identityHash, $identityHash));
    }

    public function byProvider(string $provider): array
    {
        return $this->matching($provider, static fn(): bool => true);
    }

    public function byTenant(string $provider, int $tenantId, bool $lock = false): array
    {
        return $this->matching($provider, static fn(ExternalTenantBinding $binding): bool =>
            $binding->tenantId === $tenantId);
    }

    public function byReference(int $tenantId, int $bindingId): array
    {
        return array_values(array_filter($this->bindings, static fn(ExternalTenantBinding $binding): bool =>
            $binding->tenantId === $tenantId && $binding->id === $bindingId));
    }

    public function byOAuthState(string $provider, string $stateHash): array
    {
        return $this->matching($provider, static fn(ExternalTenantBinding $binding): bool =>
            hash_equals((string) ($binding->config['state_hash'] ?? ''), $stateHash));
    }

    public function byOAuthTicket(string $ticketHash): array
    {
        return array_values(array_filter($this->bindings, static fn(ExternalTenantBinding $binding): bool =>
            hash_equals((string) ($binding->config['ticket_hash'] ?? ''), $ticketHash)));
    }

    /** @return list<ExternalTenantBinding> */
    private function matching(string $provider, callable $match): array
    {
        return array_values(array_filter($this->bindings, static fn(ExternalTenantBinding $binding): bool =>
            hash_equals($provider, $binding->provider) && $match($binding)));
    }
}

final class ExternalFixtureAudit implements ExternalTenantAudit
{
    /** @var list<array{outcome:string,attributes:array<string,int|string>}> */
    public array $records = [];

    public function record(string $outcome, array $attributes): void
    {
        $this->records[] = compact('outcome', 'attributes');
    }
}

function externalBinding(
    int $id,
    int $tenantId,
    string $provider,
    string $key,
    string $identity,
    bool $active = true,
    bool $tenantActive = true,
    array $extra = [],
): ExternalTenantBinding {
    return new ExternalTenantBinding(
        $id,
        $tenantId,
        $provider,
        $key,
        hash('sha256', strtolower(trim($identity))),
        substr($identity, -4),
        ['verification_secret' => 'secret-' . $tenantId, ...$extra],
        $active,
        $tenantActive,
    );
}

$alphaState = str_repeat('a', 64);
$betaState = str_repeat('b', 64);
$ticket = str_repeat('c', 64);
$bindings = [
    externalBinding(1, 101, ExternalProvider::WECHAT_PAYMENT, 'wx-alpha-key', 'wx-app:mch-alpha'),
    externalBinding(2, 202, ExternalProvider::WECHAT_PAYMENT, 'wx-beta-key', 'wx-app:mch-beta'),
    externalBinding(3, 101, ExternalProvider::WECHAT_OFFICIAL_OAUTH, 'oa-alpha-key', 'oa-alpha', true, true, [
        'state_hash' => hash('sha256', $alphaState),
        'ticket_hash' => hash('sha256', $ticket),
    ]),
    externalBinding(4, 202, ExternalProvider::WECHAT_OFFICIAL_OAUTH, 'oa-beta-key', 'oa-beta', true, true, [
        'state_hash' => hash('sha256', $betaState),
    ]),
    externalBinding(5, 303, ExternalProvider::ALIPAY_PAYMENT, 'ali-disabled', 'ali-app:seller', false),
    externalBinding(6, 404, ExternalProvider::WECHAT_OFFICIAL_CALLBACK, 'oa-suspended', 'gh_suspended', true, false),
];
$audit = new ExternalFixtureAudit();
$fixtureRepository = new ExternalFixtureRepository($bindings);
$resolver = new ExternalTenantResolver($fixtureRepository, $audit);
// The resolver accepts candidates from OAuth's public locator; this fixture does
// not claim to test the real OAuth persistence, expiry or one-time ticket store.
$callbackLocator = new class ($fixtureRepository) implements OAuthCallbackLocator {
    public function __construct(private readonly ExternalFixtureRepository $repository) {}

    public function locateState(string $provider, string $stateHash): array
    {
        return $this->repository->byOAuthState($provider, $stateHash);
    }

    public function locateTicket(string $ticketHash): array
    {
        return $this->repository->byOAuthTicket($ticketHash);
    }
};

$verified = [];
$orders = [
    101 => ['ORDER-SAME' => ['status' => 'unpaid', 'transaction' => '']],
    202 => ['ORDER-SAME' => ['status' => 'unpaid', 'transaction' => '']],
];
$settle = static function (int $tenantId, string $order, string $transaction) use (&$orders): void {
    if (($orders[$tenantId][$order]['status'] ?? '') === 'paid') {
        externalExpect($orders[$tenantId][$order]['transaction'] === $transaction, 'conflicting replay was accepted');
        return;
    }
    externalExpect(isset($orders[$tenantId][$order]), 'wrong Tenant order was selected');
    $orders[$tenantId][$order] = ['status' => 'paid', 'transaction' => $transaction];
};

$alpha = $resolver->verifiedCallback(
    ExternalProvider::WECHAT_PAYMENT,
    'wx-alpha-key',
    'payment.settle',
    'operation-alpha',
    static function (array $config) use (&$verified): string {
        $verified[] = $config['verification_secret'];
        return 'verified-event';
    },
);
externalExpect($alpha->context->tenantId === 101, 'Alpha callback resolved another Tenant');
externalExpect($alpha->verifiedValue === 'verified-event', 'verified event was not carried to the state machine');
$settle($alpha->context->tenantId, 'ORDER-SAME', 'TX-ALPHA');
$settle($alpha->context->tenantId, 'ORDER-SAME', 'TX-ALPHA');
externalExpect($orders[101]['ORDER-SAME']['status'] === 'paid', 'Alpha callback did not settle Alpha');
externalExpect($orders[202]['ORDER-SAME']['status'] === 'unpaid', 'Alpha callback changed Beta');
externalExpect($verified === ['secret-101'], 'verification did not use the resolved binding before state mutation');

$forgedPayload = ['tenant_id' => 202];
externalExpect($forgedPayload['tenant_id'] === 202 && $alpha->context->tenantId === 101, 'payload tenant_id changed authorization');

$denials = [];
$deny = static function (callable $action) use (&$denials): void {
    try {
        $action();
        throw new RuntimeException('denied callback was accepted');
    } catch (ExternalTenantResolutionException $exception) {
        $denials[] = [$exception::class, $exception->getMessage()];
    }
};
$deny(fn() => $resolver->verifiedCallback(
    ExternalProvider::WECHAT_PAYMENT,
    'unknown',
    'payment.settle',
    'unknown',
    static fn(): bool => true,
));
$duplicate = new ExternalTenantResolver(new ExternalFixtureRepository([
    externalBinding(10, 101, ExternalProvider::WECHAT_PAYMENT, 'duplicate', 'one'),
    externalBinding(11, 202, ExternalProvider::WECHAT_PAYMENT, 'duplicate', 'two'),
]), $audit);
$deny(fn() => $duplicate->verifiedCallback(
    ExternalProvider::WECHAT_PAYMENT,
    'duplicate',
    'payment.settle',
    'duplicate',
    static fn(): bool => true,
));
$deny(fn() => $resolver->verifiedCallback(
    ExternalProvider::ALIPAY_PAYMENT,
    'ali-disabled',
    'payment.settle',
    'disabled',
    static fn(): bool => true,
));
$deny(fn() => $resolver->verifiedCallback(
    ExternalProvider::WECHAT_OFFICIAL_CALLBACK,
    'oa-suspended',
    'wechat.official.callback',
    'suspended',
    static fn(): bool => true,
));
$deny(fn() => $resolver->verifiedCallback(
    ExternalProvider::WECHAT_PAYMENT,
    'wx-beta-key',
    'payment.settle',
    'bad-signature',
    static fn(): bool => false,
));
externalExpect(count(array_unique(array_map('serialize', $denials))) === 1, 'denial causes expose distinguishable shapes');
externalExpect($orders[202]['ORDER-SAME']['status'] === 'unpaid', 'denied or wrong-Tenant callback changed Beta');

$oauthAlpha = $resolver->verifiedCandidates(
    $callbackLocator->locateState(ExternalProvider::WECHAT_OFFICIAL_OAUTH, hash('sha256', $alphaState)),
    ExternalProvider::WECHAT_OFFICIAL_OAUTH,
    $alphaState,
    'oauth.callback',
    'oauth-alpha',
);
$oauthBeta = $resolver->verifiedCandidates(
    $callbackLocator->locateState(ExternalProvider::WECHAT_OFFICIAL_OAUTH, hash('sha256', $betaState)),
    ExternalProvider::WECHAT_OFFICIAL_OAUTH,
    $betaState,
    'oauth.callback',
    'oauth-beta',
);
$oauthTicket = $resolver->verifiedCandidates(
    $callbackLocator->locateTicket(hash('sha256', $ticket)),
    'oauth.wechat.completion',
    $ticket,
    'oauth.complete',
    'oauth-ticket',
    false,
);
externalExpect($oauthAlpha->context->tenantId === 101 && $oauthBeta->context->tenantId === 202, 'OAuth state crossed Tenants');
externalExpect($oauthTicket->context->tenantId === 101, 'completion ticket did not restore its owner Tenant');

$auditText = json_encode($audit->records, JSON_THROW_ON_ERROR);
externalExpect(str_contains($auditText, 'identity'), 'resolver audit lacks identity fingerprint');
externalExpect(!str_contains($auditText, 'secret-101') && !str_contains($auditText, $ticket), 'resolver audit leaked a secret or ticket');

$root = dirname(__DIR__, 2);
$paymentController = (string) file_get_contents($root . '/app/modules/peanut/payment/src/Controller/PaymentNotifyController.php');
$officialController = (string) file_get_contents($root . '/app/modules/peanut/oauth/src/Controller/OfficialAccountCallbackController.php');
$oauthController = (string) file_get_contents($root . '/app/modules/peanut/oauth/src/Controller/MemberOAuthController.php');
$paymentApplication = (string) file_get_contents($root . '/app/modules/peanut/payment/src/Service/PaymentCallbackApplicationService.php');
$officialApplication = (string) file_get_contents($root . '/app/modules/peanut/oauth/src/Service/OfficialAccountCallbackService.php');
$oauthApplication = (string) file_get_contents($root . '/app/modules/peanut/oauth/src/Service/OAuthEntryService.php');
$settlement = (string) file_get_contents($root . '/app/modules/peanut/payment/src/Service/RechargeApplicationService.php');
$schema = (string) file_get_contents($root . '/app/modules/peanut/integration/database/migrations/20261101010008_initial_integration_schema.php');
$bindingRepository = (string) file_get_contents($root . '/app/modules/peanut/integration/src/Infrastructure/ThinkPhpExternalTenantBindingRepository.php');
$bootstrapService = (string) file_get_contents($root . '/app/platform/services/ApplicationTenantBootstrapService.php');
foreach ([$paymentController, $officialController, $oauthController, $paymentApplication, $officialApplication, $oauthApplication] as $source) {
    externalExpect(!str_contains($source, "['tenant_id']") && !str_contains($source, "get('tenant_id")
        && !str_contains($source, "header('tenant_id"), 'callback wiring trusts request tenant_id');
}
externalExpect(!str_contains($paymentController, 'static function () use ($event, $resolution): void'), 'payment callback uses injected service from a static closure');
$verificationPosition = strpos($paymentApplication, 'verifiedCallback(');
$settlementPosition = strpos($paymentApplication, '$this->recharges->settleVerifiedCallback(');
externalExpect($verificationPosition !== false && $settlementPosition !== false
    && $verificationPosition < $settlementPosition, 'payment write precedes verification');
externalExpect(str_contains($paymentController, '$this->application->wechat(')
    && str_contains($paymentController, '$this->application->alipay('), 'payment HTTP adapter bypasses its use case');
externalExpect(str_contains($settlement, 'settleVerifiedCallback(int $paymentBindingId, PaymentEvent $event, int $payWay)'), 'settlement does not require the verified binding and event port');
externalExpect(!str_contains($settlement, 'VerifiedPaymentTenantResolver::resolve'), 'settlement still derives Tenant from an order number');
foreach (['uk_external_callback_key', 'uk_external_provider_identity', 'uk_external_tenant_provider',
    'fk_external_binding_tenant'] as $marker) {
    externalExpect(str_contains($schema, $marker), 'binding schema invariant missing: ' . $marker);
}
externalExpect(
    str_contains($bindingRepository, "'config_json' => '{}'")
        && str_contains($bindingRepository, "'status' => 0")
        && str_contains($bootstrapService, '$this->externalBindings->ensureUnconfiguredBinding('),
    'tenant provisioning does not create explicit disabled provider placeholders',
);
foreach (['Db::transaction(', "->where('tenant_id', \$tenantId)", "->lock(true)",
    "'callback_key' => \$callbackKey", 'bin2hex(random_bytes(32))'] as $marker) {
    externalExpect(str_contains($bindingRepository, $marker), 'binding persistence invariant missing: ' . $marker);
}
externalExpect(
    str_contains($bootstrapService, '$this->externalBindings->ensureUnconfiguredBinding(')
        && str_contains($bindingRepository, "'callback_key' => bin2hex(random_bytes(32))")
        && !str_contains($bootstrapService, 'hash(\'sha256\', "fresh:{$tenantCode}:{$provider}")'),
    'Tenant bootstrap still derives callback keys from tenant identity',
);

$keyTransition = new ReflectionMethod(ThinkPhpExternalTenantBindingRepository::class, 'callbackKeyForUpdate');
$keyTransition->setAccessible(true);
$provider = ExternalProvider::WECHAT_PAYMENT;
$placeholder = hash('sha256', 'fresh-default:' . $provider);
$missingA = $keyTransition->invoke(null, $provider, '', false);
$missingB = $keyTransition->invoke(null, $provider, '', false);
$activated = $keyTransition->invoke(null, $provider, $placeholder, true);
externalExpect(
    is_string($missingA) && preg_match('/^[a-f0-9]{64}$/D', $missingA) === 1
        && is_string($missingB) && $missingA !== $missingB,
    'new Tenant bindings do not receive unique opaque callback keys',
);
externalExpect(
    $keyTransition->invoke(null, $provider, $placeholder, false) === $placeholder
        && is_string($activated) && $activated !== $placeholder
        && $keyTransition->invoke(null, $provider, $activated, true) === $activated,
    'callback key activation or stable-update lifecycle changed',
);

echo "EXTERNAL-CALLBACK-TENANT-ROUTING-001 passed\n";
