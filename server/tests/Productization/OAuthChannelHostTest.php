<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

use PeanutAdmin\Modules\OAuth\Service\OAuthBrowserCallbackService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectOAuthChannelHost(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$repositoryRoot = dirname($serverRoot);

expectOAuthChannelHost(
    OAuthBrowserCallbackService::callbackUrl('https://product.test/', 'open_pc')
        === 'https://product.test/api/oauth/wechat/redirect/pc',
    'PC callback URL is not canonical',
);
expectOAuthChannelHost(
    OAuthBrowserCallbackService::callbackUrl('https://product.test', 'oa')
        === 'https://product.test/api/oauth/wechat/redirect/official-account',
    'official-account callback URL is not canonical',
);
$pcRedirect = OAuthBrowserCallbackService::clientRedirectUrl('pc', [
    'code' => 'pc-code',
    'state' => 'pc-state',
    'ticket' => 'must-not-cross-the-bridge',
    'error' => ['must-not-be-cast'],
]);
expectOAuthChannelHost(
    $pcRedirect === '/pc/oauth/callback?code=pc-code&state=pc-state',
    'PC callback bridge target or query allowlist is invalid',
);
$oaRedirect = OAuthBrowserCallbackService::clientRedirectUrl('official-account', [
    'code' => 'oa-code',
    'state' => 'oa-state',
]);
expectOAuthChannelHost(
    $oaRedirect === '/mobile/#/pages/oauth/callback?scene=oa&code=oa-code&state=oa-state',
    'official-account callback bridge target is invalid',
);

$oauthLogic = (string) file_get_contents($serverRoot . '/app/modules/peanut/oauth/src/Service/OAuthCommandService.php');
$oauthProvider = (string) file_get_contents($serverRoot . '/app/modules/peanut/oauth/src/ModuleProvider.php');
$rechargeApplication = (string) file_get_contents($serverRoot . '/app/modules/peanut/payment/src/Service/RechargeApplicationService.php');
expectOAuthChannelHost(
    str_contains($oauthProvider, 'OAuthQueries::class =>')
        && str_contains($rechargeApplication, 'private readonly OAuthQueries $oauth')
        && str_contains($rechargeApplication, '$this->oauth->wechatSubjectForMember(')
        && !str_contains($rechargeApplication, 'OAuthModuleProvider'),
    'Recharge consumer bypasses the OAuth query contract binding',
);
foreach ([
    'private const ATTEMPT_TTL = 600',
    'private const COMPLETION_TTL = 600',
    "'state_hash' => hash('sha256', \$state)",
    'completionForUpdate($context, hash(\'sha256\', $rawTicket))',
    'private readonly OAuthTransport $transport',
    '$this->transport->exchange(',
    'ExternalTenantBinding $binding',
] as $marker) {
    expectOAuthChannelHost(str_contains($oauthLogic, $marker), 'OAuth invariant missing: ' . $marker);
}
foreach ([
    'app/api/application/OAuthApplicationService.php',
    'app/api/application/OfficialAccountApplicationService.php',
] as $retiredHost) {
    expectOAuthChannelHost(!is_file($serverRoot . '/' . $retiredHost), 'retired OAuth Host remains: ' . $retiredHost);
}
expectOAuthChannelHost(
    !str_contains($oauthLogic, 'app\\api\\')
        && str_contains($oauthLogic, 'private readonly ThinkPhpOAuthPersistence $persistence')
        && !str_contains($oauthLogic, 'Model\\'),
    'OAuth Module application bypasses its contracts',
);
$oauthWithoutModuleTypes = preg_replace('/PeanutAdmin\\\\Modules\\\\[^;]+;/', '', $oauthLogic);
$oauthWithoutAllowedContextTypes = str_replace([
    'PeanutAdmin\\Kernel\\Auth\\TenantContext',
    'PeanutAdmin\\Kernel\\Context\\AuthenticatedMemberContext',
    'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
    'PeanutAdmin\\IntegrationSecurity\\OAuth\\OAuthProfile',
    'PeanutAdmin\\IntegrationSecurity\\OAuth\\OAuthTransport',
], '', $oauthWithoutModuleTypes);
expectOAuthChannelHost(
    !str_contains($oauthWithoutAllowedContextTypes, 'PeanutAdmin\\'),
    'application OAuth owner imports core outside trusted notification context types',
);

$routeSource = peanut_route_registry_source($serverRoot);
$routeInventory = peanut_route_endpoint_inventory($serverRoot);
$oauthRouteSource = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/oauth/route/app.php',
);
foreach (['setting/channel/config', 'setting/channel/save'] as $legacyRoute) {
    expectOAuthChannelHost(
        !str_contains($routeSource, $legacyRoute) && !str_contains($oauthRouteSource, $legacyRoute),
        'legacy Channel route remains: ' . $legacyRoute,
    );
}
foreach (['api/oauth/wechat/redirect/pc', 'api/oauth/wechat/redirect/official-account'] as $callbackRoute) {
    $matches = array_filter(
        $routeInventory['endpoints'],
        static fn(array $endpoint): bool => $endpoint['method'] === 'GET'
            && $endpoint['path'] === '/' . $callbackRoute
            && $endpoint['owner'] === ['type' => 'module', 'key' => 'peanut.oauth'],
    );
    expectOAuthChannelHost(
        count($matches) === 1,
        'callback bridge route is missing: ' . $callbackRoute,
    );
}
foreach ([
    'app/adminapi/controller/setting/ChannelController.php',
    'app/adminapi/application/setting/ChannelLogic.php',
] as $retiredPath) {
    expectOAuthChannelHost(!is_file($serverRoot . '/' . $retiredPath), 'legacy Channel Runtime remains: ' . $retiredPath);
}

$officialAccountConfig = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/oauth/src/Service/OfficialAccountApplicationService.php',
);
$officialAccountApi = (string) file_get_contents($repositoryRoot . '/web/src/modules/peanut-oauth/api.ts');
$officialAccountView = (string) file_get_contents(
    $repositoryRoot . '/web/src/modules/peanut-oauth/views/channel/OfficialAccountConfig.vue',
);
$legacyWebApi = (string) file_get_contents($repositoryRoot . '/web/src/api/app.ts');
foreach ([$officialAccountConfig, $officialAccountApi, $officialAccountView] as $source) {
    expectOAuthChannelHost(!str_contains($source, 'encoding_aes_key'), 'unused AES key remains writable');
    expectOAuthChannelHost(!str_contains($source, 'encryption_type'), 'unused AES mode remains writable');
}
expectOAuthChannelHost(
    str_contains($officialAccountConfig, "'callback_mode' => 'plaintext'"),
    'official-account plaintext-only callback boundary is missing',
);
expectOAuthChannelHost(!str_contains($legacyWebApi, 'interface ChannelConfig'), 'legacy Web Channel facade remains');

$uniOAuthApi = (string) file_get_contents($repositoryRoot . '/uniapp/src/modules/peanut-oauth/api/oauth.ts');
$uniLogin = (string) file_get_contents($repositoryRoot . '/uniapp/src/modules/peanut-member/pages/login/login.vue');
$uniCallback = (string) file_get_contents($repositoryRoot . '/uniapp/src/modules/peanut-oauth/pages/oauth/callback.vue');
$uniComplete = (string) file_get_contents($repositoryRoot . '/uniapp/src/modules/peanut-oauth/pages/oauth/complete.vue');
foreach (['stashOAuthCompletion', 'consumeOAuthCompletion', 'sessionStorage', 'removeStorageSync'] as $marker) {
    expectOAuthChannelHost(str_contains($uniOAuthApi, $marker), 'UniApp completion boundary missing: ' . $marker);
}
foreach ([$uniLogin, $uniCallback] as $source) {
    expectOAuthChannelHost(
        !str_contains($source, '/pages/oauth/complete?ticket='),
        'completion ticket remains in a UniApp URL',
    );
}
expectOAuthChannelHost(
    str_contains($uniComplete, 'consumeOAuthCompletion()'),
    'UniApp completion state is not consumed once',
);

$freshSchema = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/oauth/database/migrations/20261102000004_initial_oauth_schema.php',
);
foreach (['wechat_open_secret', 'wechat_oa_secret', 'qq_secret', 'encoding_aes_key', 'encryption_type'] as $field) {
    expectOAuthChannelHost(
        !str_contains($freshSchema, $field),
        'retired credential remains in the fresh schema: ' . $field,
    );
}
// Historical browser receipts are not current module execution evidence.
foreach (['OAuthEntryService', 'MemberOAuthController'] as $consumer) {
    $directory = $consumer === 'OAuthEntryService' ? 'Service' : 'Controller';
    $source = (string) file_get_contents($serverRoot . '/app/modules/peanut/oauth/src/' . $directory . '/' . $consumer . '.php');
    expectOAuthChannelHost(str_contains($source, 'OAuthBrowserCallbackService::'), 'callback routing consumer is disconnected: ' . $consumer);
}
echo "PB07-OAUTH-CHANNEL-HOST-001 passed\n";
