<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Device;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Identity\Contract\Dto\EnrollDeviceCredentialInput;

final class DeviceCredentialInput
{
    public const UUID_PATTERN = '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D';

    public function parse(#[\SensitiveParameter] string $body): EnrollDeviceCredentialInput
    {
        $value = json_decode($body);
        $fields = ['client_key', 'device_fingerprint_hash', 'credential_public_key', 'credential_signature', 'command_key'];
        if (!$value instanceof \stdClass
            || array_diff(array_keys(get_object_vars($value)), $fields) !== []
            || array_diff($fields, array_keys(get_object_vars($value))) !== []) {
            throw BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备凭据登记参数无效。');
        }
        foreach ($fields as $field) {
            if (!is_string($value->{$field})) {
                throw BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备登记参数须使用完整文本值。');
            }
        }
        $input = new EnrollDeviceCredentialInput(
            $value->client_key,
            $value->device_fingerprint_hash,
            $value->credential_public_key,
            $value->credential_signature,
            $value->command_key,
        );
        self::assertValid($input);
        return $input;
    }

    public static function assertValid(#[\SensitiveParameter] EnrollDeviceCredentialInput $input): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $input->clientKey) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $input->deviceFingerprintHash) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $input->credentialPublicKey) !== 1
            || preg_match('/^[0-9a-f]{128}$/D', $input->credentialSignature) !== 1
            || preg_match(self::UUID_PATTERN, $input->commandKey) !== 1) {
            throw BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备客户端、公钥、签名或原操作标识无效。');
        }
    }
}
