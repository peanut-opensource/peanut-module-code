<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

use app\common\model\TenantOwnedModel;
use think\Model;

/** @internal */
final class DeviceCredential extends TenantOwnedModel
{
    protected function getOptions(): array
    {
        return [
            ...parent::getOptions(),
            'table' => 'pa_device_credential',
            'pk' => 'id',
            'autoWriteTimestamp' => false,
            'jsonAssoc' => true,
            'readonly' => [
                'id', 'tenant_id', 'credential_key', 'client_key', 'device_fingerprint_hash',
                'credential_public_key', 'key_fingerprint', 'credential_signature',
                'enroll_source_command_key', 'enroll_input_hash', 'enroll_source_result_json', 'enroll_basis_json',
                'revoke_source_command_key', 'revoke_input_hash', 'revoke_source_result_json', 'revoke_basis_json',
                'created_at', 'created_by_member_id',
            ],
            'hidden' => [
                'device_fingerprint_hash', 'credential_public_key', 'key_fingerprint', 'credential_signature',
                'enroll_source_command_key', 'enroll_input_hash', 'enroll_source_result_json', 'enroll_basis_json',
                'revoke_source_command_key', 'revoke_input_hash', 'revoke_source_result_json', 'revoke_basis_json',
            ],
            'schema' => [
                'id' => 'bigint', 'tenant_id' => 'bigint', 'credential_key' => 'string', 'revision' => 'bigint',
                'client_key' => 'string', 'device_fingerprint_hash' => 'string', 'credential_public_key' => 'string',
                'key_fingerprint' => 'string', 'credential_signature' => 'string', 'status' => 'string',
                'bound_installation_key' => 'string', 'bound_at' => 'string', 'bound_by_member_id' => 'bigint',
                'revocation_epoch' => 'bigint', 'revoked_at' => 'string', 'revoked_by_member_id' => 'bigint', 'revoke_reason' => 'string',
                'enroll_source_command_key' => 'string', 'enroll_input_hash' => 'string',
                'enroll_source_result_json' => 'json', 'enroll_basis_json' => 'json',
                'revoke_source_command_key' => 'string', 'revoke_input_hash' => 'string',
                'revoke_source_result_json' => 'json', 'revoke_basis_json' => 'json',
                'created_at' => 'string', 'created_by_member_id' => 'bigint',
                'updated_at' => 'string', 'updated_by_member_id' => 'bigint',
            ],
        ];
    }

    public static function onBeforeDelete(Model $model): bool
    {
        return false;
    }
}
