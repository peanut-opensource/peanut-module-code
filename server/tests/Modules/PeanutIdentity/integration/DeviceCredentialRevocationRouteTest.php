<?php

declare(strict_types=1);

use app\adminapi\http\middleware\{AuthMiddleware, LoginMiddleware, OperationLogMiddleware};
use app\common\infrastructure\module\OfficialModuleMiddleware;
use PeanutAdmin\Modules\Identity\Controller\DeviceCredentialController;
use think\{App, Request, Response};
use think\exception\RouteNotFoundException;

// Run the installed dispatcher with the actual Identity declarations and native route config.
$server = dirname(__DIR__, 4);
$loader = require $server . '/vendor/autoload.php';
$loader->setPsr4('PeanutAdmin\\Modules\\Identity\\', $server . '/app/modules/peanut/identity/src');

function dispatchDeviceRoute(string $method, string $path, string $application = 'adminapi'): ?array
{
    global $server;
    $app = new App(__DIR__);
    $app->config->set(require $server . '/config/route.php', 'route');
    $peanutRouteApplication = $application;
    require $server . '/app/modules/peanut/identity/route/app.php';
    $request = (new Request())->withServer(['HTTP_HOST' => 'dcs.invalid'])->setMethod($method)->setPathinfo($path);
    $app->instance('request', $request);
    $matched = null;
    $app->middleware->add(static function (Request $request) use (&$matched): Response {
        $rule = $request->rule();
        $matched = [$rule->getRoute(), $request->route(), $rule->getOption('peanut_permission'), $rule->getOption('middleware')];
        return Response::create('', 'html', 204);
    }, 'route');
    try {
        $app->route->dispatch($request);
    } catch (RouteNotFoundException) {
        return null;
    }
    return $matched;
}

$checks = 0;
$check = static function (mixed $expected, mixed $actual, string $message) use (&$checks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message . ' Expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    $checks++;
};
$middleware = [
    [LoginMiddleware::class, []], [OfficialModuleMiddleware::class, ['peanut.identity', 'http.admin']],
    [AuthMiddleware::class, []], [OperationLogMiddleware::class, []],
];
$key = '8c428297-4047-4708-8fa4-35c44232783d';
$path = 'api/v1/identity/device-credentials/' . $key . '/revoke';
$check([
    [DeviceCredentialController::class, 'revoke'], ['key' => $key], 'peanut.identity.device-credential.revoke', $middleware,
], dispatchDeviceRoute('POST', $path), 'The real UUID path reaches the exact revocation action and independent middleware.');
$check([
    [DeviceCredentialController::class, 'enroll'], [], 'peanut.identity.device-credential.enroll', $middleware,
], dispatchDeviceRoute('POST', 'api/v1/identity/device-credentials'), 'The existing enrollment action retains its real route.');

foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
    $check(null, dispatchDeviceRoute($method, $path), 'A different HTTP method cannot execute revocation.');
}
foreach (['not-a-uuid', strtoupper($key), str_replace('-', '', $key), '8c428297-4047-4708-8fa4-35c44232783z', ''] as $invalid) {
    $check(
        null,
        dispatchDeviceRoute('POST', 'api/v1/identity/device-credentials/' . $invalid . '/revoke'),
        'Only the canonical lowercase UUID reaches this action.',
    );
}
$check(null, dispatchDeviceRoute('POST', $path . '/unregistered'), 'An additional suffix cannot reach revocation.');
$check(null, dispatchDeviceRoute('POST', 'api/v1/identity/device-credentials/' . $key), 'The explicit revoke suffix is required.');
$check(null, dispatchDeviceRoute('POST', $path, 'platformapi'), 'The tenant Identity route is excluded from the platform application.');
echo json_encode(['suite' => 'DeviceCredentialRevocationRoute', 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
