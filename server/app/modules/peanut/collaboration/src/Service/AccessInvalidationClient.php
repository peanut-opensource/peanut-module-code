<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Service;

use app\common\contract\http\OutboundHttpTransport;
use app\common\value\http\OutboundHttpRequest;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;
use think\facade\Db;

/** One negative domain; no drain, grant, room mutation or member credential. */
final readonly class AccessInvalidationClient
{
    public const PATH = '/internal/collaboration/access/v1/invalidate';
    private const DOMAIN = 'peanut.collaboration.access-invalidation-v1';

    public function __construct(private OutboundHttpTransport $http, private array $configuration) {}

    public function invalidate(array $input): array
    {
        $pdo = Db::connect()->getPdo();
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            throw new \DomainException('REVOCATION_RPC_TRANSACTION_DENIED');
        }
        $c = $this->configuration;
        $origin = $c['control_origin'] ?? null;
        $url = is_string($origin) ? parse_url($origin) : false;
        $developmentHttp = ($c['environment'] ?? null) === 'development' && ($url['scheme'] ?? null) === 'http';
        $ca = $developmentHttp ? null : ($c['ca_bundle_file'] ?? null);
        if (($c['enabled'] ?? null) !== true || !$url || !(($url['scheme'] ?? '') === 'https' || $developmentHttp)
            || !isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true) || (!$developmentHttp && (!is_string($ca)
            || $ca !== dirname(__DIR__, 6) . '/private/collaboration/service/ca.pem'
            || !is_file($ca) || is_link($ca) || !is_string($c['ca_sha256'] ?? null)
            || !hash_equals($c['ca_sha256'], hash_file('sha256', $ca)))) || !is_string($c['secret'] ?? null) || strlen($c['secret']) < 32
            || ($input['instance_id'] ?? null) !== ($c['instance_id'] ?? null)
            || ($input['service_instance_id'] ?? null) !== ($c['service_instance_id'] ?? null)
            || !is_string($c['key_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $c['key_id']) !== 1) {
            throw new RetryableTaskException('REVOCATION_CONTROL_UNAVAILABLE');
        }
        $raw = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($raw) > 65536 || count($input['targets']) > 100) {
            throw new \DomainException('REVOCATION_SCOPE_TOO_LARGE');
        }
        $nonce = bin2hex(random_bytes(32));
        $timestamp = (string) time();
        $prefix = [$c['instance_id'], $c['service_instance_id'], $c['key_id'], $input['holder_key']];
        $sign = static fn(string $direction, array $parts): string => hash_hmac('sha256', implode("\n", [self::DOMAIN . '.' . $direction . '.v1', ...$prefix, ...$parts]), $c['secret']);
        try {
            $reply = $this->http->send(new OutboundHttpRequest(
                'POST',
                rtrim($origin, '/') . self::PATH,
                ['Content-Type' => 'application/json', 'X-Collaboration-Protocol' => '1',
                    'X-Collaboration-Service-Instance' => $c['service_instance_id'], 'X-Collaboration-Holder-Key' => $input['holder_key'],
                    'X-Collaboration-Key-Id' => $c['key_id'], 'X-Collaboration-Timestamp' => $timestamp, 'X-Collaboration-Nonce' => $nonce,
                    'X-Collaboration-Signature' => $sign('request', [$timestamp, $nonce, 'POST', self::PATH, hash('sha256', $raw)])],
                $raw,
                3,
                10,
                false,
                caBundleFile: $ca,
                directConnection: true,
                allowDevelopmentHttp: $developmentHttp,
            ));
        } catch (\Throwable) {
            throw new RetryableTaskException('REVOCATION_NODE_UNREACHABLE');
        }
        $headers = array_change_key_case($reply->headers, CASE_LOWER);
        $replyTime = $headers['x-collaboration-timestamp'] ?? '';
        $digest = hash('sha256', $reply->body);
        if (strlen($reply->body) > 65536 || ($headers['x-collaboration-protocol'] ?? null) !== '1'
            || ($headers['x-collaboration-holder-key'] ?? null) !== $input['holder_key']
            || ($headers['x-collaboration-service-instance'] ?? null) !== $c['service_instance_id']
            || ($headers['x-collaboration-key-id'] ?? null) !== $c['key_id'] || ($headers['x-collaboration-nonce'] ?? null) !== $nonce
            || ($headers['x-collaboration-body-sha256'] ?? null) !== $digest || preg_match('/^[0-9]{10}$/D', $replyTime) !== 1
            || abs(time() - (int) $replyTime) > 30
            || !hash_equals($sign('response', [$nonce, 'POST', self::PATH, hash('sha256', $raw), $replyTime, (string) $reply->status, $digest]), $headers['x-collaboration-signature'] ?? '')) {
            throw new \DomainException('REVOCATION_ACK_AUTHENTICATION_FAILED');
        }
        if ($reply->status !== 200) {
            throw new RetryableTaskException('REVOCATION_NODE_UNCONFIRMED');
        }
        if (strlen($reply->body) > 65536) {
            throw new \DomainException('REVOCATION_ACK_INVALID');
        }
        try {
            $value = json_decode($reply->body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('REVOCATION_ACK_INVALID');
        }
        if (!is_array($value) || array_keys($value) !== ['ok', 'data'] || $value['ok'] !== true || !is_array($value['data'])) {
            throw new \DomainException('REVOCATION_ACK_INVALID');
        }
        $ack = $value['data'];
        $keys = array_keys($ack);
        sort($keys);
        if ($keys !== ['closed_count', 'fact_id', 'fact_sha256', 'holder_key', 'instance_id', 'matched_count', 'operation_id', 'outcome', 'processed_at', 'protocol_version', 'scope_digest', 'service_instance_id']) {
            throw new \DomainException('REVOCATION_ACK_INVALID');
        }
        foreach (['protocol_version', 'fact_id', 'fact_sha256', 'operation_id', 'instance_id', 'service_instance_id', 'holder_key', 'scope_digest'] as $key) {
            if ($ack[$key] !== $input[$key]) {
                throw new \DomainException('REVOCATION_ACK_BINDING_INVALID');
            }
        }
        if (!in_array($ack['outcome'], ['applied', 'no_matching_connection'], true) || !is_int($ack['matched_count']) || !is_int($ack['closed_count'])
            || $ack['matched_count'] < 0 || $ack['closed_count'] !== $ack['matched_count'] || $ack['matched_count'] > count($input['targets'])
            || ($ack['outcome'] === 'no_matching_connection') !== ($ack['matched_count'] === 0)
            || !is_string($ack['processed_at']) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $ack['processed_at']) !== 1) {
            throw new \DomainException('REVOCATION_ACK_INVALID');
        }
        return $ack;
    }
}
