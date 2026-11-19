<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Device;

use app\common\exception\BusinessException;
use Opis\JsonSchema\Validator;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\RevokeDeviceCredentialInput;

final class DeviceCredentialRevocationEvidence
{
    public static function inputHash(
        TenantContext $actor,
        string $credentialKey,
        #[\SensitiveParameter]
        RevokeDeviceCredentialInput $input,
    ): string {
        return self::hash(
            (string) $actor->tenantId,
            (string) $actor->accountId,
            (string) $actor->memberId,
            $actor->clientKey,
            $credentialKey,
            $input,
        );
    }

    public static function original(#[\SensitiveParameter] array $source): array
    {
        DeviceCredentialEvidence::original($source);
        $basis = json_decode((string) ($source['revoke_basis_json'] ?? ''), true);
        $body = json_decode((string) ($source['revoke_source_result_json'] ?? ''), true);
        if (!is_array($basis) || !is_array($body)) {
            throw self::invalid();
        }
        static $schemas = null;
        if ($schemas === null) {
            $contract = json_decode(
                (string) file_get_contents(__DIR__ . '/../../resources/device-credential-revocation-contract.json'),
                flags: JSON_THROW_ON_ERROR,
            );
            $schemas = $contract->{'$defs'};
        }
        $validator = new Validator();
        if (!$validator->validate(json_decode((string) $source['revoke_basis_json']), $schemas->RevocationBasis)->isValid()
            || !$validator->validate(json_decode((string) $source['revoke_source_result_json']), $schemas->RevocationResult)->isValid()) {
            throw self::invalid();
        }
        if ($body['outcome'] !== 'accepted' || !is_array($body['data'])
            || ($source['bound_installation_key'] === null) !== ($source['bound_at'] === null)
            || ($source['bound_installation_key'] === null) !== ($source['bound_by_member_id'] === null)) {
            throw self::invalid();
        }
        $before = $basis['credential_revision_before'];
        $after = $basis['credential_revision_after'];
        if (!DeviceCredentialRevocationInput::isRevision($before)
            || !DeviceCredentialRevocationInput::isRevision($after)
            || !DeviceCredentialRevocationInput::isRevision((string) $source['revision'])
            || $before === DeviceCredentialRevocationInput::UINT64_MAX
            || bcadd($before, '1', 0) !== $after || bccomp((string) $source['revision'], $after, 0) < 0) {
            throw self::invalid();
        }
        $input = new RevokeDeviceCredentialInput(
            (string) $source['revoke_reason'],
            (string) $source['revoke_source_command_key'],
            $before,
        );
        try {
            DeviceCredentialRevocationInput::assertValid($input);
        } catch (BusinessException) {
            throw self::invalid();
        }
        $expectedBasis = [
            'schema_version' => 1, 'credential_key' => $source['credential_key'], 'client_key' => $source['client_key'],
            'key_fingerprint' => $source['key_fingerprint'], 'bound_installation_key' => $source['bound_installation_key'],
            'bound_at' => $source['bound_at'] === null ? null : str_replace(' ', 'T', (string) $source['bound_at']) . 'Z',
            'bound_by_member_id' => $source['bound_by_member_id'] === null ? null : (string) $source['bound_by_member_id'],
            'credential_revision_before' => $before, 'credential_revision_after' => $after,
            'credential_status_before' => $source['bound_installation_key'] === null ? 'enrolled' : 'bound',
            'credential_status_after' => 'revoked', 'revocation_epoch_before' => '0', 'revocation_epoch_after' => '1',
            'enrollment_input_hash' => $source['enroll_input_hash'],
            'actor_account_id' => $basis['actor_account_id'], 'actor_member_id' => (string) $source['revoked_by_member_id'],
            'actor_client_key' => 'admin-web', 'reason_note' => $source['revoke_reason'],
            'revoked_at' => str_replace(' ', 'T', (string) $source['revoked_at']) . 'Z',
            'correlation_id' => $body['correlation_id'],
        ];
        ksort($basis, SORT_STRING);
        ksort($expectedBasis, SORT_STRING);
        $expectedData = [
            'status_code' => 'revoked',
            'changed_refs' => [[
                'module' => 'peanut.identity', 'resource_type' => 'device_credential',
                'key' => $source['credential_key'], 'revision' => $after,
            ]],
            'next_actions' => [], 'revocation_epoch' => '1', 'installation_key' => $source['bound_installation_key'],
        ];
        $actualData = $body['data'];
        ksort($actualData['changed_refs'][0], SORT_STRING);
        ksort($expectedData['changed_refs'][0], SORT_STRING);
        ksort($actualData, SORT_STRING);
        ksort($expectedData, SORT_STRING);
        if ($basis !== $expectedBasis || $source['status'] !== 'revoked' || (string) $source['revocation_epoch'] !== '1'
            || $body['outcome'] !== 'accepted' || $body['resource_key'] !== $source['credential_key']
            || $body['resource_revision'] !== $after || $body['problem'] !== null
            || $actualData !== $expectedData
            || !hash_equals((string) $source['revoke_input_hash'], self::hash(
                (string) $source['tenant_id'],
                $basis['actor_account_id'],
                $basis['actor_member_id'],
                $basis['actor_client_key'],
                (string) $source['credential_key'],
                $input,
            ))) {
            throw self::invalid();
        }
        return $body;
    }

    private static function hash(
        string $tenantId,
        string $accountId,
        string $memberId,
        string $clientKey,
        string $credentialKey,
        #[\SensitiveParameter]
        RevokeDeviceCredentialInput $input,
    ): string {
        return hash('sha256', json_encode([
            'schema_version' => 1, 'operation' => 'peanut.identity.device-credential.revoke',
            'tenant_id' => $tenantId, 'account_id' => $accountId, 'member_id' => $memberId,
            'actor_client_key' => $clientKey, 'credential_key' => $credentialKey,
            'command_key' => $input->commandKey, 'expected_revision' => $input->expectedRevision, 'reason_note' => $input->reasonNote,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function invalid(): BusinessException
    {
        return new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据原撤销依据无法完整核实。');
    }
}
