<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Service;

use app\common\contract\http\OutboundHttpTransport;
use app\common\exception\BusinessException;
use app\common\value\http\OutboundHttpRequest;
use PeanutAdmin\Modules\Document\Contract\BusinessBarrierPlan;
use PeanutAdmin\Modules\Document\Contract\DocumentBusinessReceipt;
use think\facade\Db;

/** Lazy configuration validation: disabled collaboration never breaks ordinary Document reads. */
final readonly class BusinessControlClient
{
    private const PATH = '/internal/collaboration/control/v1/business-barrier';

    public function __construct(private OutboundHttpTransport $http, private array $configuration) {}

    public function call(string $stage, BusinessBarrierPlan $plan, ?DocumentBusinessReceipt $result = null): array
    {
        if ($plan->data['control_mode'] !== 'native' || !in_array($stage, ['drain','release'], true)
            || $result !== null && $result->plan->hash() !== $plan->hash()) {
            throw new \InvalidArgumentException('DOCUMENT_BUSINESS_RECEIPT_INVALID');
        }
        if (($this->configuration['instance_id'] ?? null) !== $plan->data['binding']['instance_id']
            || ($this->configuration['service_instance_id'] ?? null) !== $plan->data['service_instance_id']) {
            throw new \DomainException('COLLABORATION_CONTROL_BINDING_INVALID');
        }
        $input = ['protocol' => 2,'action' => $stage,'holder_key' => $plan->data['holder_key'],'operation_id' => $plan->data['operation_id'],
            'input_sha256' => $plan->data['input_sha256'],'room_key' => $plan->data['room_key'],'lease_epoch' => $plan->data['lease_epoch']];
        if ($stage === 'release') {
            if ($result === null || ($result->data['lease_epoch'] ?? 0) <= $plan->data['lease_epoch']
                || !in_array($result->data['status'] ?? null, ['active','archived','blocked_recovery'], true)) {
                throw new \InvalidArgumentException('DOCUMENT_BUSINESS_RELEASE_INVALID');
            }
            $input += ['result_epoch' => $result->data['lease_epoch'],'status' => $result->data['status']];
        }
        $response = $this->exchange($input);
        if (($response['protocol'] ?? null) !== 2 || ($response['holder_key'] ?? null) !== $input['holder_key']
            || ($response['operation_id'] ?? null) !== $input['operation_id']
            || ($response['input_sha256'] ?? null) !== $input['input_sha256'] || ($response['room_key'] ?? null) !== $input['room_key']
            || ($response['lease_epoch'] ?? null) !== $input['lease_epoch']) {
            throw new \DomainException('COLLABORATION_CONTROL_BINDING_INVALID');
        }
        return $response;
    }

    private function exchange(array $input): array
    {
        $path = self::PATH;
        $domain = 'control';
        $pdo = Db::connect()->getPdo();
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            throw new \LogicException('COLLABORATION_CONTROL_TRANSACTION_DENIED');
        }
        $c = $this->configuration;
        $origin = $c['control_origin'] ?? '';
        $url = is_string($origin) ? parse_url($origin) : false;
        $developmentHttp = ($c['environment'] ?? null) === 'development' && ($url['scheme'] ?? null) === 'http';
        $caBundleFile = $developmentHttp ? null : ($c['ca_bundle_file'] ?? null);
        if (!$developmentHttp && (!is_string($caBundleFile) || $caBundleFile !== dirname(__DIR__, 6) . '/private/collaboration/service/ca.pem')) {
            throw new \DomainException('COLLABORATION_CA_REFERENCE_INVALID');
        }
        if (!$developmentHttp && (!is_file($caBundleFile) || is_link($caBundleFile) || preg_match('/^[a-f0-9]{64}$/D', $c['ca_sha256'] ?? '') !== 1
            || !hash_equals($c['ca_sha256'], hash_file('sha256', $caBundleFile)))) {
            throw new \DomainException('COLLABORATION_CA_REFERENCE_CHANGED');
        }
        if (($c['enabled'] ?? null) !== true || !$url || !(($url['scheme'] ?? '') === 'https' || $developmentHttp)
            || !isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true)
            || !is_string($c['secret'] ?? null) || strlen($c['secret']) < 32
            || !is_string($c['instance_id'] ?? null) || !is_string($c['service_instance_id'] ?? null)
            || !is_string($c['key_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $c['key_id']) !== 1
        ) {
            throw BusinessException::conflict('COLLAB_UNAVAILABLE', '协作控制入口尚未就绪');
        }
        $raw = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $nonce = bin2hex(random_bytes(32));
        $timestamp = (string) time();
        $bodyHash = hash('sha256', $raw);
        $prefix = [$c['instance_id'],$c['service_instance_id'],$c['key_id'],$input['holder_key']];
        $signature = hash_hmac('sha256', implode("\n", ['peanut.collaboration.' . $domain . '.request.v2', ...$prefix, $timestamp,$nonce,'POST',$path,$bodyHash]), $c['secret']);
        $response = $this->http->send(new OutboundHttpRequest(
            'POST',
            rtrim($origin, '/') . $path,
            ['Content-Type' => 'application/json','X-Collaboration-Protocol' => '2','X-Collaboration-Service-Instance' => $c['service_instance_id'],
                'X-Collaboration-Holder-Key' => $input['holder_key'],
                'X-Collaboration-Key-Id' => $c['key_id'],'X-Collaboration-Timestamp' => $timestamp,'X-Collaboration-Nonce' => $nonce,'X-Collaboration-Signature' => $signature],
            $raw,
            3,
            20,
            false,
            null,
            false,
            caBundleFile: $caBundleFile,
            directConnection: true,
            allowDevelopmentHttp: $developmentHttp,
        ));
        $h = array_change_key_case($response->headers, CASE_LOWER);
        $time = $h['x-collaboration-timestamp'] ?? '';
        $digest = hash('sha256', $response->body);
        $expected = hash_hmac('sha256', implode("\n", ['peanut.collaboration.' . $domain . '.response.v2', ...$prefix, $nonce,'POST',$path,$bodyHash,$time,(string) $response->status,$digest]), $c['secret']);
        if (strlen($response->body) > ($domain === 'operator' ? 33554432 : 8388608) || ($h['x-collaboration-protocol'] ?? null) !== '2'
            || ($h['x-collaboration-holder-key'] ?? null) !== $input['holder_key']
            || ($h['x-collaboration-service-instance'] ?? null) !== $c['service_instance_id'] || ($h['x-collaboration-key-id'] ?? null) !== $c['key_id']
            || ($h['x-collaboration-nonce'] ?? null) !== $nonce || ($h['x-collaboration-body-sha256'] ?? null) !== $digest
            || preg_match('/^[0-9]{10}$/D', $time) !== 1 || abs(time() - (int) $time) > 30
            || !hash_equals($expected, $h['x-collaboration-signature'] ?? '')) {
            throw BusinessException::conflict('COLLABORATION_CONTROL_UNKNOWN', '协作控制响应未能验证，屏障保持关闭');
        }
        $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        if ($response->status === 409 && is_array($decoded) && ($decoded['ok'] ?? null) === false
            && ($decoded['error_code'] ?? null) === 'CONTROL_HOLDER_CHANGED') {
            // Authentication of the former holder proves rejection, never an offline drain.
            throw BusinessException::conflict('CONTROL_HOLDER_CHANGED', '原屏障服务代际已退出，须保留原操作等待明确恢复');
        }
        if ($response->status !== 200 || !is_array($decoded) || ($decoded['ok'] ?? null) !== true
            || !is_array($decoded['data'] ?? null)) {
            throw BusinessException::conflict('COLLABORATION_CONTROL_UNKNOWN', '协作控制未完成，须继续查询原操作');
        }
        return $decoded['data'];
    }
}
