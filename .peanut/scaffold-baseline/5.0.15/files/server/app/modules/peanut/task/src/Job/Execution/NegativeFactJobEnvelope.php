<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Job\Execution;

use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;

/** This one domain signs fact references; it deliberately has no member/session context. */
final readonly class NegativeFactJobEnvelope
{
    public const PROFILE = 'identity_negative_fact';
    public const PURPOSE = 'identity.revocation.propagate';
    public const RESOURCE = 'peanut.identity.revocation';
    public const TASK = 'identity.revocation.propagate';
    private const DOMAIN = 'peanut.task.identity-negative-fact.v1';

    private function __construct(public int $tenantId, public string $factId, public string $factHash, public string $jobKey) {}

    public static function issue(int $tenantId, string $factId, string $factHash, string $jobKey, string $key): string
    {
        $payload = ['version' => 1, 'profile' => self::PROFILE, 'purpose' => self::PURPOSE,
            'resource_key' => self::RESOURCE, 'operation' => self::PURPOSE, 'tenant_id' => $tenantId,
            'fact_id' => $factId, 'fact_sha256' => $factHash, 'job_key' => $jobKey];
        self::parse($payload);
        if (strlen($key) < 32) {
            throw TaskJobException::denied();
        }
        return json_encode(['payload' => $payload, 'signature' => hash_hmac('sha256', self::DOMAIN . "\n" . self::canonical($payload), $key)], JSON_THROW_ON_ERROR);
    }

    public static function verify(string $encoded, string $key): self
    {
        if (strlen($key) < 32) {
            throw TaskJobException::denied();
        }
        try {
            $value = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw TaskJobException::denied();
        }
        if (!is_array($value) || array_keys($value) !== ['payload', 'signature'] || !is_array($value['payload'])
            || !is_string($value['signature']) || !hash_equals(hash_hmac('sha256', self::DOMAIN . "\n" . self::canonical($value['payload']), $key), $value['signature'])) {
            throw TaskJobException::denied();
        }
        return self::parse($value['payload']);
    }

    private static function parse(array $p): self
    {
        $keys = array_keys($p);
        sort($keys);
        if ($keys !== ['fact_id', 'fact_sha256', 'job_key', 'operation', 'profile', 'purpose', 'resource_key', 'tenant_id', 'version']
            || $p['version'] !== 1 || $p['profile'] !== self::PROFILE || $p['purpose'] !== self::PURPOSE
            || $p['resource_key'] !== self::RESOURCE || $p['operation'] !== self::PURPOSE
            || !is_int($p['tenant_id']) || $p['tenant_id'] < 1
            || !is_string($p['fact_id']) || preg_match('/^revocation_[0-9a-f]{32}$/D', $p['fact_id']) !== 1
            || !is_string($p['fact_sha256']) || preg_match('/^[0-9a-f]{64}$/D', $p['fact_sha256']) !== 1
            || !is_string($p['job_key']) || preg_match('/^job_[0-9a-f]{32}$/D', $p['job_key']) !== 1) {
            throw TaskJobException::denied();
        }
        return new self($p['tenant_id'], $p['fact_id'], $p['fact_sha256'], $p['job_key']);
    }

    private static function canonical(array $payload): string
    {
        ksort($payload, SORT_STRING);
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
