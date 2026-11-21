<?php

declare(strict_types=1);

namespace Custom\Modules\AiAccess\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Settings\Secret\SecretProtectionException;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SecretStorageContext;
use think\db\BaseQuery;
use think\facade\Db;

final readonly class Catalog
{
    public const PROTOCOLS = [
        'text' => ['openai_text', 'openai_responses', 'anthropic_messages'],
        'image' => ['openai_image'],
    ];

    public function __construct(
        private AdminAuthorizationQuery $authorization,
        private SecretProtector $secrets,
    ) {}

    public function listing(TenantContext $tenant): array
    {
        $result = ['providers' => [], 'credentials' => [], 'models' => [], 'authors' => [], 'definitions' => [], 'defaults' => []];
        foreach ($this->query($tenant)->order('priority', 'desc')->order('entry_key')->select() as $row) {
            $result[match ($row['kind']) {
                'provider' => 'providers',
                'credential' => 'credentials',
                'model' => 'models',
                'author' => 'authors',
                'definition' => 'definitions',
                'default' => 'defaults',
            }][] = $this->present($row);
        }
        return $result + ['can_configure' => $this->allowed($tenant, 'configure')];
    }

    public function save(TenantContext $tenant, array $input): array
    {
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['provider', 'model', 'credential', 'author', 'definition', 'default'], true)) {
            $this->invalid('请选择配置类型');
        }
        $key = $this->identifier($input['key'] ?? null);
        $provider = match ($kind) {
            'provider' => $key,
            'author', 'definition', 'default' => '',
            default => $this->identifier($input['provider'] ?? null),
        };
        if ($kind === 'default' && !isset(self::PROTOCOLS[$key])) {
            $this->invalid('默认模型能力无效');
        }
        $name = $this->text($input['name'] ?? null, 120, '名称');
        $enabled = $input['enabled'] ?? null;
        if (!is_bool($enabled)) {
            $this->invalid('启用状态无效');
        }
        $priority = !in_array($kind, ['provider', 'model'], true) ? 0 : $this->number($input['priority'] ?? 0, 0, 10000);
        $details = match ($kind) {
            'provider' => $this->endpoint($input),
            'model' => $this->model($input),
            'credential' => ['models' => $this->modelScope($input['models'] ?? null)],
            'author' => [],
            'definition' => [
                'author' => $this->identifier($input['author'] ?? null),
                'capabilities' => $this->capabilities($input['capabilities'] ?? null),
            ],
            'default' => [
                'provider' => $this->identifier($input['provider'] ?? null),
                'offering' => $this->identifier($input['offering'] ?? null),
            ],
        };
        if ($kind === 'provider' && $enabled && !$this->isApprovedOrigin($details['origin'])) {
            throw BusinessException::forbidden('AI_ACCESS_ORIGIN_DENIED', '该 HTTPS 地址未由实例维护者登记');
        }
        $secret = $kind === 'credential' ? ($input['secret'] ?? '') : '';
        if (!is_string($secret) || strlen($secret) > 8192 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
            $this->invalid('令牌格式无效');
        }

        return Db::transaction(function () use ($tenant, $kind, $provider, $key, $name, $enabled, $priority, $details, $secret): array {
            $parent = $this->query($tenant, 'provider')->where('entry_key', $provider)->lock(true)->find();
            if (in_array($kind, ['model', 'credential'], true) && $parent === null) {
                $this->invalid('服务商不存在');
            }
            $record = $kind === 'provider' ? $parent : $this->query($tenant, $kind)
                ->where('provider', $provider)->where('entry_key', $key)->lock(true)->find();
            if ($kind === 'provider' && $record !== null && $details != $this->details($record)) {
                $this->invalid('服务地址和 API 前缀固定，变更时请登记新的服务商标识');
            }
            if ($kind === 'credential' && $details['models'] !== [] && $details['models'] !== ['*']) {
                $models = $this->query($tenant, 'model')->where('provider', $provider)
                    ->whereIn('entry_key', $details['models'])->count();
                if ((int) $models !== count($details['models'])) {
                    $this->invalid('令牌范围包含未在此服务商登记的模型');
                }
            }
            if ($kind === 'definition') {
                if (!$this->query($tenant, 'author')->where('entry_key', $details['author'])->find()) {
                    $this->invalid('模型厂商不存在');
                }
                foreach ($this->query($tenant, 'model')->select() as $offering) {
                    $registered = $this->details($offering);
                    if (($registered['definition'] ?? null) === $key
                        && array_diff(array_keys($registered['protocols']), $details['capabilities']) !== []) {
                        $this->invalid('请先调整该模型的接入能力');
                    }
                }
            }
            if ($kind === 'model') {
                $definition = $this->query($tenant, 'definition')->where('entry_key', $details['definition'])->lock(true)->find();
                if ($definition === null || array_diff(array_keys($details['protocols']), $this->details($definition)['capabilities']) !== []) {
                    $this->invalid('接入能力必须由目录模型支持');
                }
            }
            if ($kind === 'default' && $enabled) {
                $offering = $this->query($tenant, 'model')->where('provider', $details['provider'])
                    ->where('entry_key', $details['offering'])->where('enabled', 1)->find();
                if ($offering === null || !isset($this->details($offering)['protocols'][$key])) {
                    $this->invalid('请选择支持该能力且已启用的模型接入');
                }
            }
            $values = [
                'tenant_id' => $tenant->tenantId, 'kind' => $kind, 'provider' => $provider,
                'entry_key' => $key, 'name' => $name, 'enabled' => (int) $enabled,
                'priority' => $priority, 'details' => $this->json($details),
                'secret_payload' => $record['secret_payload'] ?? null,
            ];
            if ($secret !== '') {
                try {
                    $sealed = $this->secrets->protect($secret, $this->secretContext($tenant, $provider, $key));
                } catch (SecretProtectionException) {
                    throw BusinessException::conflict('AI_ACCESS_SECRET_UNAVAILABLE', '系统密钥环不可用');
                }
                $sealed['ciphertext'] = base64_encode($sealed['ciphertext']);
                $sealed['nonce'] = base64_encode($sealed['nonce']);
                $values['secret_payload'] = $this->json($sealed);
            }
            if ($kind === 'credential' && $values['secret_payload'] === null) {
                $this->invalid('请填写令牌');
            }
            if ($record === null) {
                $this->query($tenant)->insert($values);
            } else {
                $this->query($tenant)->where('id', $record['id'])->update($values);
            }
            return $this->present($values);
        });
    }

    public function query(TenantContext $tenant, ?string $kind = null): BaseQuery
    {
        // @phpstan-ignore peanut.rawDbQuery (Every catalog operation starts with the verified tenant predicate.)
        $query = Db::name('ai_access_catalog')->where('tenant_id', $tenant->tenantId);
        return $kind === null ? $query : $query->where('kind', $kind);
    }

    public function allowed(TenantContext $tenant, string $operation): bool
    {
        return $this->authorization->decide(
            $tenant, $this->authorization->principal($tenant), 'custom.ai-access.' . $operation,
        )->allowed;
    }

    public function details(array $row): array
    {
        return json_decode($row['details'], true, 16, JSON_THROW_ON_ERROR);
    }

    public function reveal(TenantContext $tenant, array $row): string
    {
        $sealed = json_decode($row['secret_payload'], true, 16, JSON_THROW_ON_ERROR);
        try {
            return $this->secrets->reveal(
                base64_decode($sealed['ciphertext'], true),
                base64_decode($sealed['nonce'], true),
                $sealed['key_id'],
                $this->secretContext($tenant, $row['provider'], $row['entry_key']),
            );
        } catch (SecretProtectionException) {
            throw BusinessException::conflict('AI_ACCESS_SECRET_UNAVAILABLE', '令牌无法解密');
        }
    }

    public function isApprovedOrigin(string $origin): bool
    {
        $configured = explode(',', (string) env('AI_ACCESS_ALLOWED_ORIGINS', ''));
        $allowed = array_map(static fn (string $value): string => rtrim(trim($value), '/'), $configured);
        return in_array($origin, $allowed, true);
    }

    public function identifier(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $value)) {
            $this->invalid('标识须以小写字母开头，仅使用小写字母、数字、连字符或下划线');
        }
        return $value;
    }

    private function present(array $row): array
    {
        $result = ['key' => $row['entry_key'], 'name' => $row['name'], 'enabled' => (bool) $row['enabled']];
        return $result + match ($row['kind']) {
            'provider' => ['priority' => (int) $row['priority']] + $this->details($row),
            'model' => ['provider' => $row['provider'], 'priority' => (int) $row['priority']] + $this->details($row),
            'credential' => ['provider' => $row['provider'], 'configured' => $row['secret_payload'] !== null] + $this->details($row),
            default => $this->details($row),
        };
    }

    private function endpoint(array $input): array
    {
        $origin = rtrim($this->text($input['origin'] ?? null, 255, '服务地址'), '/');
        $prefix = $this->text($input['api_prefix'] ?? null, 80, 'API 前缀');
        $url = parse_url($origin);
        if ($url === false || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || array_diff(array_keys($url), ['scheme', 'host', 'port']) !== []
            || preg_match('/[\x00-\x20\\\\]/', $origin)
            || !preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $prefix)) {
            $this->invalid('请填写 HTTPS 服务地址和独立的 API 前缀');
        }
        return ['origin' => $origin, 'api_prefix' => $prefix];
    }

    private function model(array $input): array
    {
        $protocols = $input['protocols'] ?? null;
        if (!is_array($protocols) || $protocols === []) {
            $this->invalid('至少选择一项已支持的能力');
        }
        foreach ($protocols as $capability => $protocol) {
            if (!in_array($protocol, self::PROTOCOLS[$capability] ?? [], true)) {
                $this->invalid('能力与协议不匹配');
            }
        }
        return [
            'definition' => $this->identifier($input['definition'] ?? null),
            'remote_model' => $this->text($input['remote_model'] ?? null, 200, '服务商模型标识'),
            'protocols' => $protocols,
            'input_limit_chars' => $this->number($input['input_limit_chars'] ?? null, 1, 200000),
            'max_output_tokens' => $this->number($input['max_output_tokens'] ?? null, 1, 128000),
        ];
    }

    private function capabilities(mixed $capabilities): array
    {
        if (!is_array($capabilities) || !array_is_list($capabilities) || $capabilities === []) {
            $this->invalid('至少选择一项模型能力');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !isset(self::PROTOCOLS[$capability])) {
                $this->invalid('模型能力无效');
            }
        }
        return array_values(array_unique($capabilities));
    }

    private function modelScope(mixed $scope): array
    {
        if ($scope === ['*']) {
            return $scope;
        }
        if (!is_array($scope) || !array_is_list($scope)) {
            $this->invalid('请明确令牌可用的模型范围');
        }
        return array_values(array_unique(array_map($this->identifier(...), $scope)));
    }

    private function secretContext(TenantContext $tenant, string $provider, string $key): SecretStorageContext
    {
        return SecretStorageContext::target('custom.ai-access.api-key', $tenant->tenantId, 'custom.ai-access.credential', hash('sha256', "$provider:$key"));
    }

    private function text(mixed $value, int $max, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            $this->invalid($label . '无效');
        }
        return trim($value);
    }

    private function number(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->invalid('数值超出允许范围');
        }
        return $value;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function invalid(string $message): never
    {
        throw BusinessException::invalid('AI_ACCESS_CONFIGURATION_INVALID', $message);
    }
}
