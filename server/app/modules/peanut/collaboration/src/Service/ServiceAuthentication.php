<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Service;

use app\common\exception\BusinessException;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblem;
use PeanutAdmin\Modules\Collaboration\Model\ServiceNonce;
use PeanutAdmin\Modules\Collaboration\Protocol\ServiceRequest;
use think\db\exception\PDOException;
use think\facade\Db;

/** Collaboration service authentication, not a human identity or a Document capability. */
final class ServiceAuthentication
{
    private \WeakMap $authenticated;
    private ?ServiceRequest $currentRequest = null;
    private \WeakMap $rawBodies;
    private \WeakMap $receivedTimes;

    public function __construct(private readonly ExecutionContextStore $execution, private readonly array $configuration)
    {
        $this->authenticated = new \WeakMap();
        $this->rawBodies = new \WeakMap();
        $this->receivedTimes = new \WeakMap();
    }

    public function authenticate(string $method, string $path, string $rawBody, array $headers): ServiceRequest
    {
        if ($this->execution->current() !== null) {
            throw BusinessException::forbidden('COLLABORATION_INTERNAL_AUDIENCE_INVALID', '内部接口不接受现有用户或任务身份');
        }
        $this->requireConfiguration();
        if ($method !== 'POST'
            || preg_match('~^/adminapi/internal/collaboration/v1/(authorize|check|load|store|receipt|references|target(?:/prepare)?|recovery|revoke)$~D', $path) !== 1
            || strlen($rawBody) > $this->configuration['max_request_bytes']) {
            throw BusinessException::invalid('COLLABORATION_SERVICE_REQUEST_INVALID', '内部请求格式无效');
        }
        foreach (['protocol', 'service_instance_id', 'key_id', 'holder_key', 'timestamp', 'nonce', 'signature'] as $key) {
            if (!isset($headers[$key]) || !is_string($headers[$key])) {
                throw BusinessException::forbidden('COLLABORATION_SERVICE_AUTH_INVALID', '内部服务认证无效');
            }
        }
        if ($headers['protocol'] !== '2' || !hash_equals($this->configuration['service_instance_id'], $headers['service_instance_id'])
            || !hash_equals($this->configuration['key_id'], $headers['key_id'])
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $headers['holder_key']) !== 1
            || preg_match('/^[0-9]{10}$/D', $headers['timestamp']) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $headers['nonce']) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $headers['signature']) !== 1
            || abs(time() - (int) $headers['timestamp']) > $this->configuration['clock_window_seconds']) {
            throw BusinessException::forbidden('COLLABORATION_SERVICE_AUTH_INVALID', '内部服务认证无效');
        }
        $request = new ServiceRequest(
            $headers['service_instance_id'],
            $headers['key_id'],
            $headers['holder_key'],
            $headers['nonce'],
            (int) $headers['timestamp'],
            $method,
            $path,
            hash('sha256', $rawBody),
        );
        $input = implode("\n", ['peanut.collaboration.request.v2', $this->configuration['instance_id'],
            $request->serviceInstanceId, $request->keyId, $request->holderKey, (string) $request->timestamp, $request->nonce,
            $request->method, $request->path, $request->bodySha256]);
        if (!hash_equals(hash_hmac('sha256', $input, $this->configuration['secret']), $headers['signature'])) {
            throw BusinessException::forbidden('COLLABORATION_SERVICE_AUTH_INVALID', '内部服务认证无效');
        }
        // Database waits must not move either the lease receipt time or the proof age forward.
        $receivedAt = time();
        $authenticatedAt = hrtime(true);
        // A rolled-back application transaction must never restore a consumed nonce.
        $pdo = Db::connect()->getPdo();
        // ThinkORM returns false before its first query; startTrans always initializes the PDO.
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            throw BusinessException::conflict('COLLABORATION_NONCE_TRANSACTION_INVALID', '服务认证必须先于业务事务');
        }
        try {
            ServiceNonce::create(['service_instance_id' => $request->serviceInstanceId, 'key_id' => $request->keyId,
                'nonce' => $request->nonce, 'request_sha256' => hash('sha256', $input),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', $request->timestamp + $this->configuration['clock_window_seconds'] + 1)]);
        } catch (PDOException $error) {
            if (($error->getData()['PDO Error Info']['Driver Error Code'] ?? null) !== 1062) {
                throw $error;
            }
            throw BusinessException::forbidden('COLLABORATION_SERVICE_REPLAY', '内部请求已使用');
        }
        $this->authenticated[$request] = $authenticatedAt;
        $this->receivedTimes[$request] = $receivedAt;
        $this->rawBodies[$request] = $rawBody;
        return $request;
    }

    public function run(ServiceRequest $request, callable $operation): mixed
    {
        $this->assertAuthenticated($request);
        if ($this->currentRequest !== null || $this->execution->current() !== null) {
            throw BusinessException::forbidden('COLLABORATION_INTERNAL_AUDIENCE_INVALID', '内部请求不能继承已有执行身份');
        }
        $this->currentRequest = $request;
        try {
            return $operation();
        } finally {
            $this->currentRequest = null;
            unset($this->rawBodies[$request]);
        }
    }

    public function current(): ServiceRequest
    {
        $request = $this->currentRequest;
        if ($request === null) {
            throw BusinessException::forbidden('COLLABORATION_SERVICE_PROOF_INVALID', '缺少当前内部服务请求');
        }
        $this->assertAuthenticated($request);
        return $request;
    }

    public function payload(): array
    {
        $request = $this->current();
        try {
            $value = json_decode($this->rawBodies[$request], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw BusinessException::invalid('COLLABORATION_REQUEST_JSON_INVALID', '内部请求正文无效');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw BusinessException::invalid('COLLABORATION_REQUEST_JSON_INVALID', '内部请求正文必须是对象');
        }
        return $value;
    }

    public function receivedAt(): int
    {
        $request = $this->current();
        if (!isset($this->receivedTimes[$request])) {
            throw BusinessException::forbidden('COLLABORATION_SERVICE_PROOF_INVALID', '缺少内部服务请求受理时间');
        }
        return $this->receivedTimes[$request];
    }

    public function instanceId(): string
    {
        $this->current();
        return $this->configuration['instance_id'];
    }

    public function projectorVersion(): string
    {
        $this->current();
        return $this->configuration['projector_version'];
    }

    public function edition(): string
    {
        $this->current();
        return $this->configuration['edition'];
    }

    public function assertAuthenticated(ServiceRequest $request): void
    {
        if (!isset($this->authenticated[$request])
            || hrtime(true) - $this->authenticated[$request] > $this->configuration['clock_window_seconds'] * 1000000000) {
            throw BusinessException::forbidden('COLLABORATION_SERVICE_PROOF_INVALID', '内部服务认证凭据无效');
        }
    }

    /** Sign the exact bytes returned by the existing API renderer, after committing business work. */
    public function responseHeaders(ServiceRequest $request, int $status, string $rawBody): array
    {
        $this->assertAuthenticated($request);
        if ($status < 200 || $status > 599 || strlen($rawBody) > $this->configuration['max_response_bytes']) {
            throw new \InvalidArgumentException('COLLABORATION_SERVICE_RESPONSE_INVALID');
        }
        $timestamp = (string) time();
        $bodySha256 = hash('sha256', $rawBody);
        $input = implode("\n", ['peanut.collaboration.response.v2', $this->configuration['instance_id'],
            $request->serviceInstanceId, $request->keyId, $request->holderKey, $request->nonce, $request->method, $request->path,
            $request->bodySha256, $timestamp, (string) $status, $bodySha256]);
        return ['X-Collaboration-Protocol' => '2', 'X-Collaboration-Key-Id' => $request->keyId,
            'X-Collaboration-Holder-Key' => $request->holderKey,
            'X-Collaboration-Service-Instance' => $request->serviceInstanceId,
            'X-Collaboration-Nonce' => $request->nonce, 'X-Collaboration-Timestamp' => $timestamp,
            'X-Collaboration-Body-Sha256' => $bodySha256,
            'X-Collaboration-Signature' => hash_hmac('sha256', $input, $this->configuration['secret'])];
    }

    private function requireConfiguration(): void
    {
        $config = $this->configuration;
        if (($config['enabled'] ?? null) !== true) {
            throw new ApiProblem('COLLAB_UNAVAILABLE', 503, '协作服务未启用');
        }
        foreach (['instance_id', 'service_instance_id', 'key_id'] as $key) {
            if (!is_string($config[$key] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $config[$key]) !== 1) {
                throw new ApiProblem('COLLAB_UNAVAILABLE', 503, '协作服务配置不完整');
            }
        }
        if (!in_array($config['edition'] ?? null, ['engine','standalone','multi-tenant'], true)
            || !is_string($config['projector_version'] ?? null) || preg_match('/^[A-Za-z0-9_.:\/-]{1,64}$/D', $config['projector_version']) !== 1
            || !is_string($config['secret'] ?? null) || strlen($config['secret']) < 32
            || !is_int($config['clock_window_seconds'] ?? null) || $config['clock_window_seconds'] < 1 || $config['clock_window_seconds'] > 60
            || !is_int($config['max_request_bytes'] ?? null) || $config['max_request_bytes'] < 1 || $config['max_request_bytes'] > 8388608
            || ($config['max_response_bytes'] ?? null) !== 33554432) {
            throw new ApiProblem('COLLAB_UNAVAILABLE', 503, '协作服务配置不完整');
        }
    }
}
