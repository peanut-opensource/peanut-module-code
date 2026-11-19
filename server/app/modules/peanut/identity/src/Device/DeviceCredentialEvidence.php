<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Device;

use app\common\exception\BusinessException;
use Opis\JsonSchema\Validator;
use PeanutAdmin\Modules\Identity\Contract\Dto\EnrollDeviceCredentialInput;

final class DeviceCredentialEvidence
{
    public static function original(#[\SensitiveParameter] array $source): array
    {
        $body = json_decode((string) $source['enroll_source_result_json'], true);
        $basis = json_decode((string) $source['enroll_basis_json'], true);
        if (!is_array($body) || !is_array($basis)) {
            throw self::invalid();
        }
        $input = new EnrollDeviceCredentialInput(
            (string) $source['client_key'],
            (string) $source['device_fingerprint_hash'],
            (string) $source['credential_public_key'],
            (string) $source['credential_signature'],
            (string) $source['enroll_source_command_key'],
        );
        try {
            DeviceCredentialInput::assertValid($input);
        } catch (BusinessException) {
            throw self::invalid();
        }
        static $schemas = null;
        if ($schemas === null) {
            $contract = json_decode((string) file_get_contents(__DIR__ . '/../../resources/device-credential-contract.json'), flags: JSON_THROW_ON_ERROR);
            $schemas = $contract->{'$defs'};
        }
        $validator = new Validator();
        if (!$validator->validate(json_decode((string) $source['enroll_basis_json']), $schemas->EnrollmentBasis)->isValid()
            || !$validator->validate(json_decode((string) $source['enroll_source_result_json']), $schemas->EnrollmentResult)->isValid()) {
            throw self::invalid();
        }
        $publicKey = hex2bin($input->credentialPublicKey);
        $signature = hex2bin($input->credentialSignature);
        $message = DeviceCredentialProof::message(
            (string) $source['tenant_id'],
            $basis['actor_account_id'],
            $basis['actor_member_id'],
            $basis['actor_client_key'],
            $input,
        );
        $expectedBasis = [
            'schema_version' => 1, 'credential_key' => $source['credential_key'], 'client_key' => $input->clientKey,
            'device_fingerprint_hash' => $input->deviceFingerprintHash, 'key_fingerprint' => hash('sha256', $publicKey),
            'algorithm' => 'ed25519', 'credential_revision' => '1', 'credential_status' => 'enrolled',
            'proof_hash' => hash('sha256', $signature), 'actor_account_id' => $basis['actor_account_id'],
            'actor_member_id' => (string) $source['created_by_member_id'], 'actor_client_key' => 'admin-web',
            'enrolled_at' => str_replace(' ', 'T', (string) $source['created_at']) . 'Z',
        ];
        ksort($basis, SORT_STRING);
        ksort($expectedBasis, SORT_STRING);
        $expectedReference = [
            'module' => 'peanut.identity', 'resource_type' => 'device_credential',
            'key' => $source['credential_key'], 'revision' => '1',
        ];
        $references = $body['data']['changed_refs'] ?? null;
        $actualReference = is_array($references) && array_is_list($references) && count($references) === 1 ? $references[0] : null;
        if (is_array($actualReference)) {
            ksort($actualReference, SORT_STRING);
        }
        ksort($expectedReference, SORT_STRING);
        if ($basis !== $expectedBasis || $source['key_fingerprint'] !== $expectedBasis['key_fingerprint']
            || !hash_equals((string) $source['enroll_input_hash'], hash('sha256', $message . $input->credentialSignature))
            || !sodium_crypto_sign_verify_detached($signature, $message, $publicKey)
            || $body['outcome'] !== 'accepted' || $body['resource_key'] !== $source['credential_key']
            || $body['resource_revision'] !== '1' || $body['problem'] !== null
            || $actualReference !== $expectedReference || $body['data']['status_code'] !== 'enrolled'
            || $body['data']['next_actions'] !== []) {
            throw self::invalid();
        }
        return $body;
    }

    private static function invalid(): BusinessException
    {
        return new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据首次登记依据无法完整核实。');
    }
}
