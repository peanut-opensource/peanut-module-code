<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Device;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Identity\Contract\Dto\RevokeDeviceCredentialInput;

final class DeviceCredentialRevocationInput
{
    public const UINT64_MAX = '18446744073709551615';

    public function parse(#[\SensitiveParameter] string $body): RevokeDeviceCredentialInput
    {
        $value = json_decode($body);
        $fields = ['reason_note', 'command_key', 'expected_revision'];
        if (!$value instanceof \stdClass
            || array_diff(array_keys(get_object_vars($value)), $fields) !== []
            || array_diff($fields, array_keys(get_object_vars($value))) !== []) {
            throw self::invalid();
        }
        foreach ($fields as $field) {
            if (!is_string($value->{$field})) {
                throw self::invalid();
            }
        }
        $input = new RevokeDeviceCredentialInput($value->reason_note, $value->command_key, $value->expected_revision);
        self::assertValid($input);
        return $input;
    }

    public static function assertValid(#[\SensitiveParameter] RevokeDeviceCredentialInput $input): void
    {
        if (preg_match(DeviceCredentialInput::UUID_PATTERN, $input->commandKey) !== 1
            || !self::isRevision($input->expectedRevision)
            || !mb_check_encoding($input->reasonNote, 'UTF-8')
            || mb_strlen($input->reasonNote, 'UTF-8') < 1 || mb_strlen($input->reasonNote, 'UTF-8') > 1000
            || preg_match('/\S/u', $input->reasonNote) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $input->reasonNote) !== 0) {
            throw self::invalid();
        }
    }

    public static function isRevision(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, self::UINT64_MAX) <= 0);
    }

    private static function invalid(): BusinessException
    {
        return BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备撤销原因、原命令或期望版本无效。');
    }
}
