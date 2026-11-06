<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use Custom\Modules\AiAssistant\Definition\AiDefinitions as D;
use Custom\Modules\AiAssistant\Validation\AiInput as I;
use Custom\Modules\AiAssistant\Model\Configuration;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Settings\Secret\{SecretProtector,SecretStorageContext};
use think\facade\Db;

final readonly class AiConfiguration
{
    public function __construct(private AiAccess $access, private SecretProtector $secrets) {}
    public function save(TenantContext $c, string $kind, ?int $id, ?int $version, string $title, array $input, string $request): array
    {
        I::choice($kind, D::KINDS);
        I::only($input, array_keys(D::FIELDS[$kind]));
        $data = $this->validate($c, $kind, $input);
        return Db::transaction(function () use ($c, $kind, $id, $version, $title, $data, $request): array {
            if ($id === null) {
                $old = Configuration::where('tenant_id', $c->tenantId)->where('request_id', $request)->lock(true)->find();
                if ($old !== null) {
                    $this->access->find($c, $kind, (int) $old->id, 'configure');
                    if ((int) $old->created_by !== $c->memberId || AiAccess::hash(AiAccess::data($old)['_creation'] ?? []) !== AiAccess::hash([$kind,$title,$data])) {
                        throw BusinessException::conflict('AI_REQUEST_CONFLICT', '同请求的配置内容不同');
                    }return AiAccess::present($old);
                }
                $r = $this->access->create($c, $kind, $title, $data + ['_creation' => [$kind,$title,$data]], $request);
                $result = AiAccess::present($r);
                $this->access->event($c, $kind, $r, 'create', 'create:' . hash('sha256', $request), [$kind,$title,$data], $result);
                return $result;
            }
            $op = 'configure';
            $input = [$version,$title,$data];
            if ($saved = $this->access->replay($c, $kind, $id, 'modify', $request, $input, $op)) {
                return $saved;
            }
            $r = $this->access->find($c, $kind, $id, $op, true);
            $this->access->cas($r, $version ?? 0);
            $status = null;
            if ($kind === 'connection') {
                $previous = AiAccess::data($r);
                if ($r->secret_payload && ($previous['origin'] !== $data['origin'] || $previous['api_prefix'] !== $data['api_prefix'] || $previous['protocol'] !== $data['protocol'])) {
                    $r->secret_payload = null;
                    $status = 'disabled';
                }
                $data['credential_configured'] = $r->secret_payload !== null && $r->secret_payload !== '';
            }
            $r->title = $title;
            $this->access->update($r, $data, $status);
            $result = AiAccess::present($r);
            $this->access->event($c, $kind, $r, 'modify', $request, $input, $result);
            return $result;
        });
    }
    public function secret(TenantContext $c, int $id, int $version, string $plaintext, string $request): array
    {
        return Db::transaction(function () use ($c, $id, $version, $plaintext, $request): array {
            $r = $this->access->find($c, 'connection', $id, 'configure', true);
            $d = AiAccess::data($r);
            // Hash is only an equality check inside the protected config row, never an API/log field.
            $input = [$version,hash('sha256', $plaintext)];
            if ($saved = $this->access->replay($c, 'connection', $id, 'credential', $request, $input, 'configure')) {
                return $saved;
            }
            $this->access->cas($r, $version);
            if (strlen($plaintext) < 8 || strlen($plaintext) > 8192 || preg_match('/[\r\n\x00]/', $plaintext)) {
                I::invalid('凭据长度或格式无效');
            }
            try {
                $sealed = $this->secrets->protect($plaintext, SecretStorageContext::target('custom.ai-assistant.api-key', $c->tenantId, 'custom.ai-assistant.connection', (string) $id));
            } catch (\PeanutAdmin\Settings\Secret\SecretProtectionException) {
                throw BusinessException::conflict('AI_SECRET_PROTECTOR_UNAVAILABLE', '原生密钥环未配置或不可用；未保存凭据，请由机构管理员配置');
            }
            $sealed['ciphertext'] = base64_encode($sealed['ciphertext']);
            $sealed['nonce'] = base64_encode($sealed['nonce']);
            $sealed['encoding'] = 'base64';
            $r->secret_payload = AiAccess::json($sealed);
            $d['credential_configured'] = true;
            $this->access->update($r, $d);
            $result = AiAccess::present($r);
            $this->access->event($c, 'connection', $r, 'credential', $request, $input, $result);
            return $result;
        });
    }
    public function revealCredential(TenantContext $c, array $snapshot): string
    {
        $sealed = $snapshot['credential'];
        if (isset($sealed['encoding'])) {
            if ($sealed['encoding'] !== 'base64') {
                throw BusinessException::conflict('AI_CREDENTIAL_INVALID', '连接凭据格式无效，请重新绑定');
            }
            foreach (['ciphertext', 'nonce'] as $field) {
                $bytes = is_string($sealed[$field] ?? null) ? base64_decode($sealed[$field], true) : false;
                if ($bytes === false || $bytes === '') {
                    throw BusinessException::conflict('AI_CREDENTIAL_INVALID', '连接凭据格式无效，请重新绑定');
                }
                $sealed[$field] = $bytes;
            }
        }
        return $this->secrets->reveal($sealed['ciphertext'], $sealed['nonce'], $sealed['key_id'],
            SecretStorageContext::target('custom.ai-assistant.api-key', $c->tenantId, 'custom.ai-assistant.connection', (string) $snapshot['connection']['id']));
    }
    public function state(TenantContext $c, string $kind, int $id, int $version, string $action, string $request, string $reason): array
    {
        return Db::transaction(function () use ($c, $kind, $id, $version, $action, $request, $reason): array {
            $input = [$version,$action,$reason];
            if ($saved = $this->access->replay($c, $kind, $id, 'state', $request, $input, 'configure')) {
                return $saved;
            }
            $r = $this->access->find($c, $kind, $id, 'configure', true);
            $this->access->cas($r, $version);
            $d = AiAccess::data($r);
            if ($action === 'enable') {
                if ($kind === 'connection' && empty($d['credential_configured'])) {
                    throw BusinessException::conflict('AI_CREDENTIAL_REQUIRED', '须先使用原生加密保存实际凭据');
                }
                $this->validate($c, $kind, $d);
                $status = 'enabled';
            } elseif ($action === 'disable') {
                $status = 'disabled';
            } else {
                I::invalid('配置动作无效');
            }
            $d['last_reason'] = $reason;
            $this->access->update($r, $d, $status);
            $result = AiAccess::present($r);
            $this->access->event($c, $kind, $r, 'state', $request, $input, $result);
            return $result;
        });
    }
    public function snapshot(TenantContext $c, int $routeId): array
    {
        $route = $this->access->find($c, 'route', $routeId, 'use');
        $rd = AiAccess::data($route);
        $model = $this->access->find($c, 'model', $rd['model_id'], 'use');
        $md = AiAccess::data($model);
        $template = $this->access->find($c, 'template', $rd['template_id'], 'use');
        $td = AiAccess::data($template);
        $connection = $this->access->find($c, 'connection', $md['connection_id'], 'use');
        $cd = AiAccess::data($connection);
        foreach ([$route,$model,$template,$connection] as $r) {
            if ($r->status !== 'enabled') {
                throw BusinessException::conflict('AI_CONFIGURATION_DISABLED', '路由、模板、模型和连接须分别启用');
            }
        }
        if ($rd['capability'] !== $md['capability'] || $td['capability'] !== $md['capability'] || D::PROTOCOL_CAPABILITY[$cd['protocol']] !== $md['capability']) {
            I::invalid('能力路由与适配协议不匹配');
        }
        if (isset($md['valid_until']) && gmdate('Y-m-d\\TH:i:s\\Z') >= $md['valid_until']) {
            throw BusinessException::conflict('AI_MODEL_EXPIRED', '模型目录的有效期已到，请迁移到已核可用模型');
        }
        if (!$connection->secret_payload) {
            throw BusinessException::conflict('AI_CREDENTIAL_REQUIRED', '连接尚未配置实际凭据');
        }
        return ['route' => AiAccess::present($route),'model' => AiAccess::present($model),'template' => AiAccess::present($template),'connection' => AiAccess::present($connection),'credential' => AiAccess::decode($connection->secret_payload)];
    }
    public function recheck(TenantContext $c, array $snapshot): void
    {
        foreach (['route','model','template','connection'] as $kind) {
            $fixed = $snapshot[$kind];
            $r = $this->access->find($c, $kind, $fixed['id'], 'use');
            if ($r->status !== 'enabled' || (int) $r->version !== $fixed['version']) {
                throw BusinessException::conflict('AI_CONFIGURATION_CHANGED', '固定配置已停用或修订，原任务不自动改用新配置');
            }
        }
        $md = $snapshot['model']['details'];
        if (isset($md['valid_until']) && gmdate('Y-m-d\\TH:i:s\\Z') >= $md['valid_until']) {
            throw BusinessException::conflict('AI_MODEL_EXPIRED', '固定模型已经过期');
        }
    }
    private function validate(TenantContext $c, string $kind, array $v): array
    {
        I::choice($kind, D::KINDS);
        $allowed = array_keys(D::FIELDS[$kind]);
        $v = array_diff_key($v, array_flip(['_creation','credential_configured','last_reason']));
        I::only($v, $allowed);
        $d = [];
        foreach (D::FIELDS[$kind] as $key => $type) {
            if (!array_key_exists($key, $v)) {
                if (str_ends_with($type, '?')) {
                    continue;
                }I::invalid($key . ' 必填');
            }$value = $v[$key];
            $d[$key] = match (rtrim($type, '?')) {
                'integer','id' => I::integer($value),'bool' => I::boolean($value),default => I::text($value, $key === 'instructions' ? 16000 : 2000),
            };
        }
        if ($kind === 'connection') {
            I::choice($d['protocol'], D::PROTOCOLS);
            I::choice($d['responsibility'], ['self_deployed','institution_provider','platform_service']);
            $u = parse_url($d['origin']);
            if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || !isset($u['host']) || isset($u['user'],$u['pass']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || !in_array($u['path'] ?? '', ['','/'], true) || preg_match('/[\x00-\x20]/', $d['origin'])) {
                I::invalid('端点须为明确HTTPS机构服务origin，不含路径、凭据或查询');
            }
            $d['origin'] = rtrim($d['origin'], '/');
            if (!preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $d['api_prefix']) || strlen($d['api_prefix']) > 80) {
                I::invalid('API前缀无效');
            }
            if ($d['protocol'] === 'gemini_video' && ($d['origin'] !== 'https://generativelanguage.googleapis.com' || $d['api_prefix'] !== '/v1beta')) {
                I::invalid('Gemini视频仅支持已核官方Interactions端点');
            }
            $d['daily_request_limit'] = I::integer($d['daily_request_limit'], 1, 100000);
            $d['credential_configured'] = false;
        } elseif ($kind === 'model') {
            I::choice($d['capability'], D::CAPABILITIES);
            $con = $this->access->find($c, 'connection', $d['connection_id'], 'configure');
            $cd = AiAccess::data($con);
            if (D::PROTOCOL_CAPABILITY[$cd['protocol']] !== $d['capability']) {
                I::invalid('模型能力与连接协议不匹配');
            }
            if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/-]{0,159}$~D', $d['provider_model_id'])) {
                I::invalid('提供方模型标识无效');
            }
            if (str_starts_with($d['provider_model_id'], 'sora-') || str_starts_with($d['provider_model_id'], 'veo-3.1-') && str_ends_with($d['provider_model_id'], '-preview')) {
                I::invalid('该旧视频协议/模型已停止，不能登记为当前可用能力');
            }
            $d['input_limit_chars'] = I::integer($d['input_limit_chars'], 1, $d['capability'] === 'speech' ? 4096 : 32000);
            $d['max_output_tokens'] = I::integer($d['max_output_tokens'], 1, 16000);
            if ($d['capability'] === 'speech') {
                I::choice($d['voice'] ?? null, ['alloy','ash','ballad','coral','echo','fable','onyx','nova','sage','shimmer','verse','marin','cedar']);
            }
            if (isset($d['valid_until'])) {
                $d['valid_until'] = I::time($d['valid_until']);
            }
            if (in_array($d['provider_model_id'], D::SPEECH_DEPRECATIONS, true)) {
                $d['valid_until'] = min($d['valid_until'] ?? '2027-01-06T00:00:00Z', '2027-01-06T00:00:00Z');
            }
        } elseif ($kind === 'route') {
            I::choice($d['capability'], D::CAPABILITIES);
            $d['consumer_module'] = I::consumerModule($d['consumer_module']);
            foreach (['model','template'] as $ref) {
                $r = $this->access->find($c, $ref, $d[$ref . '_id'], 'configure');
                if (AiAccess::data($r)['capability'] !== $d['capability']) {
                    I::invalid('路由引用能力不一致');
                }
            }
        } else {
            I::choice($d['capability'], D::CAPABILITIES);
        }
        return $d;
    }
}
