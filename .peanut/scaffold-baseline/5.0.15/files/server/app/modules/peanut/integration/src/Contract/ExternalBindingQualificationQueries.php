<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

use InvalidArgumentException;
use think\facade\Db;

/** Trusted platform diagnostics consume fixed flags/digests, never binding credentials or query objects. */
final readonly class ExternalBindingQualificationQueries
{
    private const PROVIDERS = [
        'payment.wechat' => 'payment.wechat',
        'payment.alipay' => 'payment.alipay',
        'notification.sms.aliyun' => 'notice.sms',
        'notification.sms.tencent' => 'notice.sms',
        'wechat.official-account' => 'wechat.official-account',
        'oauth.wechat.oa' => 'oauth.wechat.oa',
        'oauth.wechat.mini-program' => 'oauth.wechat.mini-program',
        'oauth.wechat.open-pc' => 'oauth.wechat.open-pc',
    ];

    /**
     * Caller must first authorize the platform overview and select authoritative active tenants.
     * Batches bound SQL parameters, not the result; missing bindings remain explicit unconfigured results.
     * @param list<int> $tenantIds
     * @param list<string> $providerKeys
     * @return list<ExternalBindingQualification>
     */
    public function forTenants(array $tenantIds, array $providerKeys, string $digestKey): array
    {
        if (strlen($digestKey) < 32 || !array_is_list($tenantIds) || !array_is_list($providerKeys)) {
            throw new InvalidArgumentException('PROVIDER_QUALIFICATION_INPUT_INVALID');
        }
        foreach ($tenantIds as $tenantId) {
            if (!is_int($tenantId) || $tenantId < 1) {
                throw new InvalidArgumentException('PROVIDER_QUALIFICATION_TENANT_INVALID');
            }
        }
        foreach ($providerKeys as $provider) {
            if (!is_string($provider) || !isset(self::PROVIDERS[$provider])) {
                throw new InvalidArgumentException('PROVIDER_QUALIFICATION_PROVIDER_INVALID');
            }
        }
        if ($tenantIds === [] || $providerKeys === []) {
            return [];
        }
        $tenantIds = array_values(array_unique($tenantIds));
        $providerKeys = array_values(array_unique($providerKeys));
        $bindingProviders = array_values(array_unique(array_map(static fn(string $key): string => self::PROVIDERS[$key], $providerKeys)));
        $results = [];
        foreach (array_chunk($tenantIds, 500) as $chunk) {
            $bindings = [];
            $rows = Db::name('external_channel_binding')->whereIn('tenant_id', $chunk)->whereIn('provider', $bindingProviders)
                ->field('id,tenant_id,provider,identity_hash,config_json,status,update_time')
                ->order('tenant_id')->order('provider')->select()->toArray();
            foreach ($rows as $row) {
                $bindings[(int) $row['tenant_id']][(string) $row['provider']] = $row;
            }
            foreach ($chunk as $tenantId) {
                foreach ($providerKeys as $providerKey) {
                    $row = $bindings[$tenantId][self::PROVIDERS[$providerKey]] ?? null;
                    $config = $row === null ? [] : json_decode((string) $row['config_json'], true);
                    $config = is_array($config) ? $config : [];
                    $status = $row === null ? 0 : (int) $row['status'];
                    // Keep the original raw-byte digest so existing evidence does not silently acquire a new identity.
                    $payload = $row === null
                        ? implode("\0", [$providerKey, (string) $tenantId, 'missing'])
                        : implode("\0", [$providerKey, (string) $row['id'], (string) $row['identity_hash'], (string) $row['status'], (string) $row['update_time'], (string) $row['config_json']]);
                    $results[] = new ExternalBindingQualification(
                        $tenantId,
                        $providerKey,
                        $this->configured($providerKey, $config, $status),
                        hash_hmac('sha256', $payload, $digestKey),
                    );
                }
            }
        }
        return $results;
    }

    /** @param array<string,mixed> $config */
    private function configured(string $providerKey, array $config, int $status): bool
    {
        if ($status !== 1) {
            return false;
        }
        if ($providerKey === 'payment.wechat') {
            return (int) ($config['wx_pay_status'] ?? 0) === 1 && $this->complete($config, [
                'wx_pay_appid', 'wx_pay_mch_id', 'wx_pay_secret', 'wx_pay_cert_path',
                'wx_pay_cert_key_path', 'wx_pay_platform_cert_path',
            ]);
        }
        if ($providerKey === 'payment.alipay') {
            return (int) ($config['ali_pay_status'] ?? 0) === 1 && $this->complete($config, [
                'ali_pay_app_id', 'ali_pay_private_key', 'ali_pay_public_key', 'ali_pay_seller_id',
            ]);
        }
        if (str_starts_with($providerKey, 'notification.sms.')) {
            $provider = $config[$providerKey === 'notification.sms.aliyun' ? 'sms_aliyun' : 'sms_tencent'] ?? [];
            if (is_string($provider)) {
                $provider = json_decode($provider, true);
            }
            if (!is_array($provider) || (int) ($provider['status'] ?? 0) !== 1) {
                return false;
            }
            return $providerKey === 'notification.sms.aliyun'
                ? $this->complete($provider, ['access_key_id', 'access_key_secret', 'sign_name'])
                : $this->complete($provider, ['secret_id', 'secret_key', 'sdk_app_id', 'sign_name', 'region']);
        }
        return $this->complete($config, $providerKey === 'wechat.official-account'
            ? ['app_id', 'app_secret', 'token']
            : ['app_id', 'app_secret']);
    }

    /** @param array<string,mixed> $config @param list<string> $fields */
    private function complete(array $config, array $fields): bool
    {
        foreach ($fields as $field) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }
}
