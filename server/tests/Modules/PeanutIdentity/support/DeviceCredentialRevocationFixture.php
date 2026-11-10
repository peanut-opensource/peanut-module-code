<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\RevokeDeviceCredentialInput;
use PeanutAdmin\Modules\Identity\Device\RevokeDeviceCredential;

require __DIR__ . '/DeviceCredentialFixture.php';

$roles->grants[] = 'peanut.identity.device-credential.revoke';
$revokeService = new RevokeDeviceCredential($access, $commands, $audit);
$revoke = static fn(string $key, RevokeDeviceCredentialInput $input, ?TenantContext $current = null): array =>
    $asActor(static fn(TenantContext $trusted): array => $revokeService->revoke($trusted, $key, $input), $current);
$revokeInput = static fn(int $command, string $revision = '1', string $reason = "设备丢失\n办理凭据撤销"): RevokeDeviceCredentialInput =>
    new RevokeDeviceCredentialInput($reason, businessKey($command), $revision);
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks): void {
    businessCheck($passed, $message);
    $checks++;
};
