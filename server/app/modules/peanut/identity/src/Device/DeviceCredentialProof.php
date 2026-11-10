<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Device;

use PeanutAdmin\Modules\Identity\Contract\Dto\EnrollDeviceCredentialInput;

/** Pure encoding of signed enrollment evidence; it confers no execution authority. */
final class DeviceCredentialProof
{
    public static function message(
        string $tenantId,
        string $accountId,
        string $memberId,
        string $actorClientKey,
        #[\SensitiveParameter]
        EnrollDeviceCredentialInput $input,
    ): string {
        return implode("\n", [
            'peanut.device-credential.enroll.v1',
            'tenant_id=' . $tenantId,
            'account_id=' . $accountId,
            'member_id=' . $memberId,
            'actor_client_key=' . $actorClientKey,
            'client_key=' . $input->clientKey,
            'device_fingerprint_hash=' . $input->deviceFingerprintHash,
            'credential_public_key=' . $input->credentialPublicKey,
            'command_key=' . $input->commandKey,
            '',
        ]);
    }
}
