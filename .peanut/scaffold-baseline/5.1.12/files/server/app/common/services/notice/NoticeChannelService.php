<?php

declare(strict_types=1);

namespace app\common\services\notice;

use app\common\context\notice\NoticeTenantContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantContext;
use app\common\infrastructure\notice\sms\AliyunSms;
use app\common\contract\notice\sms\SmsDriver;
use app\common\value\notice\sms\SmsDriverResult;
use app\common\infrastructure\notice\sms\TencentSms;
use app\common\contract\http\OutboundHttpTransport;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;

/** Tenant 短信凭据、默认 Provider、驱动选择与回执脱敏的唯一 Host。 */
final class NoticeChannelService
{
    private const BINDING_PROVIDER = 'notice.sms';
    private const PROVIDERS = ['aliyun', 'tencent'];

    public function __construct(
        private readonly ExternalChannelBindings $bindings,
        private readonly ExternalTenantResolutionService $resolver,
        private readonly OutboundHttpTransport $transport,
    ) {}

    public function detail(TenantContext $context): array
    {
        $stored = $this->bindingConfig($context);
        $default = strtolower(trim((string) ($stored['sms_default'] ?? '')));
        $aliyun = self::providerConfig($stored, 'aliyun');
        $tencent = self::providerConfig($stored, 'tencent');
        $active = $default === 'aliyun' ? $aliyun : ($default === 'tencent' ? $tencent : []);

        return [
            'sms_default' => $default,
            'sms_aliyun' => [
                'access_key_id' => (string) ($aliyun['access_key_id'] ?? ''),
                'access_key_secret' => empty($aliyun['access_key_secret']) ? '' : '******',
                'sign_name' => (string) ($aliyun['sign_name'] ?? ''),
                'status' => (int) ($aliyun['status'] ?? 0),
            ],
            'sms_tencent' => [
                'secret_id' => (string) ($tencent['secret_id'] ?? ''),
                'secret_key' => empty($tencent['secret_key']) ? '' : '******',
                'sdk_app_id' => (string) ($tencent['sdk_app_id'] ?? ''),
                'sign_name' => (string) ($tencent['sign_name'] ?? ''),
                'region' => (string) ($tencent['region'] ?? 'ap-guangzhou'),
                'status' => (int) ($tencent['status'] ?? 0),
            ],
            'status' => ['sms' => in_array($default, self::PROVIDERS, true)
                && self::complete($default, $active)],
        ];
    }

    public function save(CurrentExecutionContext $executionContext, TenantContext $context, string $section, array $input): void
    {
        $tenantId = NoticeTenantContext::tenantId($executionContext, $context);
        $this->bindings->mutate(
            $context,
            self::BINDING_PROVIDER,
            'tenant:' . $tenantId . ':' . self::BINDING_PROVIDER,
            static function (array $stored) use ($section, $input): array {
                self::saveLocked($section, $input, $stored);
                return $stored;
            },
            static function (array $stored): bool {
                $default = strtolower(trim((string) ($stored['sms_default'] ?? '')));
                return in_array($default, self::PROVIDERS, true)
                    && self::complete($default, self::providerConfig($stored, $default));
            },
            self::BINDING_PROVIDER,
        );
    }

    private static function saveLocked(string $section, array $input, array &$stored): void
    {
        if ($section === 'sms_default') {
            $provider = strtolower(trim((string) ($input['value'] ?? '')));
            if (!in_array($provider, self::PROVIDERS, true)
                || !self::complete($provider, self::providerConfig($stored, $provider))) {
                throw new \RuntimeException('只能选择已启用且配置完整的短信服务商');
            }
            $stored['sms_default'] = $provider;
            return;
        }

        $provider = str_starts_with($section, 'sms_') ? substr($section, 4) : '';
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new \RuntimeException('无效的短信配置节');
        }

        $allowed = $provider === 'aliyun'
            ? ['access_key_id', 'access_key_secret', 'sign_name', 'status']
            : ['secret_id', 'secret_key', 'sdk_app_id', 'sign_name', 'region', 'status'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new \RuntimeException('短信配置包含未知字段');
        }

        $current = self::providerConfig($stored, $provider);
        $config = [];
        foreach ($allowed as $field) {
            if ($field === 'status') {
                $config[$field] = (int) ($input[$field] ?? $current[$field] ?? 0);
                continue;
            }
            $value = trim((string) ($input[$field] ?? $current[$field] ?? ''));
            $config[$field] = $value === '******' ? (string) ($current[$field] ?? '') : $value;
        }
        if (!in_array($config['status'], [0, 1], true)) {
            throw new \RuntimeException('短信服务状态无效');
        }
        if ($config['status'] === 1 && !self::complete($provider, $config)) {
            throw new \RuntimeException('启用短信服务前请完整填写服务商配置');
        }

        $changes = [$section => $config];
        if ($config['status'] === 1) {
            $other = $provider === 'aliyun' ? 'tencent' : 'aliyun';
            $otherConfig = self::providerConfig($stored, $other);
            $otherConfig['status'] = 0;
            $changes['sms_' . $other] = $otherConfig;
            $changes['sms_default'] = $provider;
        } elseif ((string) ($stored['sms_default'] ?? '') === $provider) {
            $changes['sms_default'] = '';
        }
        $stored = array_replace($stored, $changes);
    }

    /** @return array{success:bool,outcome:string,provider:string,error:string,result:array<string,mixed>} */
    public function sendSms(
        CurrentExecutionContext $executionContext,
        TenantContext|TenantSystemContext $context,
        string $mobile,
        string $templateId,
        array $variables,
        ?callable $beforeSend = null,
    ): array {
        NoticeTenantContext::verificationTenantId($executionContext, $context, 'notice.verification.send');
        $stored = $this->bindingConfig($context);
        $provider = strtolower(trim((string) ($stored['sms_default'] ?? '')));
        $config = in_array($provider, self::PROVIDERS, true)
            ? self::providerConfig($stored, $provider)
            : [];
        if (!self::complete($provider, $config)) {
            return self::result(SmsDriverResult::OUTCOME_FAILED, $provider, '短信服务商未启用或配置不完整');
        }

        $driver = self::makeDriver($provider, $config, $this->transport);
        if ($beforeSend !== null) {
            $beforeSend($provider);
        }
        try {
            $delivery = $driver->send($mobile, $templateId, $variables);
            return self::result(
                $delivery->outcome,
                $provider,
                self::sanitizeError($delivery->error, $mobile, $config),
                self::safeReceipt($provider, $delivery->receipt),
            );
        } catch (\Throwable $exception) {
            return self::result(
                SmsDriverResult::OUTCOME_UNKNOWN,
                $provider,
                self::sanitizeError('短信服务商调用结果未知', $mobile, $config),
            );
        }
    }

    /** @return array{success:bool,outcome:string,provider:string,error:string,result:array<string,mixed>} */
    private static function result(
        string $outcome,
        string $provider,
        string $error,
        array $result = [],
    ): array {
        return [
            'success' => $outcome === SmsDriverResult::OUTCOME_SUCCEEDED,
            'outcome' => $outcome,
            'provider' => $provider,
            'error' => $error,
            'result' => $result,
        ];
    }

    private function bindingConfig(TenantContext|TenantSystemContext $context): array
    {
        try {
            return $this->resolver
                ->bindingForTenant(ExternalTenantContext::tenantId($context), self::BINDING_PROVIDER, false)
                ->config;
        } catch (ExternalTenantResolutionException) {
            return [];
        }
    }

    private static function providerConfig(array $stored, string $provider): array
    {
        $config = $stored['sms_' . $provider] ?? [];
        return is_array($config) ? $config : self::decodeConfig($config);
    }

    private static function decodeConfig(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function complete(string $provider, array $config): bool
    {
        if ((int) ($config['status'] ?? 0) !== 1) {
            return false;
        }
        $required = $provider === 'aliyun'
            ? ['access_key_id', 'access_key_secret', 'sign_name']
            : ($provider === 'tencent'
                ? ['secret_id', 'secret_key', 'sdk_app_id', 'sign_name', 'region'] : []);
        if ($required === []) {
            return false;
        }
        foreach ($required as $field) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    private static function makeDriver(
        string $provider,
        array $config,
        OutboundHttpTransport $transport,
    ): SmsDriver {
        return match ($provider) {
            'aliyun' => new AliyunSms($config, $transport),
            'tencent' => new TencentSms($config, $transport),
            default => throw new \RuntimeException('短信服务商不受支持'),
        };
    }

    private static function sanitizeError(string $error, string $mobile, array $config): string
    {
        $secrets = [$mobile];
        foreach (['access_key_secret', 'secret_key'] as $field) {
            if (trim((string) ($config[$field] ?? '')) !== '') {
                $secrets[] = (string) $config[$field];
            }
        }
        return mb_substr(str_replace($secrets, '[redacted]', $error), 0, 500);
    }

    private static function safeReceipt(string $provider, array $result): array
    {
        if ($provider === 'aliyun') {
            return array_intersect_key($result, array_flip(['Code', 'Message', 'RequestId', 'BizId']));
        }
        $response = is_array($result['Response'] ?? null) ? $result['Response'] : [];
        $receipt = ['RequestId' => (string) ($response['RequestId'] ?? '')];
        $receipt['SendStatusSet'] = array_map(
            static fn(array $status): array => array_intersect_key(
                $status,
                array_flip(['Code', 'Message', 'SerialNo']),
            ),
            is_array($response['SendStatusSet'] ?? null) ? $response['SendStatusSet'] : [],
        );
        return $receipt;
    }
}
