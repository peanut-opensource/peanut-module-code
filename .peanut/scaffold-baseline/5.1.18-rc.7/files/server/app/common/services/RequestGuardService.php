<?php

declare(strict_types=1);

namespace app\common\services;

use app\common\http\ApiProblem;
use PeanutAdmin\Kernel\Concurrency\ConcurrencyServices;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/** Explicit opt-in only; scope must come from the authenticated boundary. */
final readonly class RequestGuardService
{
    public function __construct(private StorageInterface $storage, private LockFactory $locks) {}

    public function limit(string $tenant, string $subject, string $action, int $limit, string $interval): void
    {
        $this->consume('rate', $tenant, $subject, $action, '', $limit, $interval);
    }

    public function preventRepeat(string $tenant, string $subject, string $action, string $commandKey, string $interval): void
    {
        if ($commandKey === '') throw new \InvalidArgumentException('提交标识不能为空');
        $this->consume('repeat', $tenant, $subject, $action, $commandKey, 1, $interval);
    }

    private function consume(string $kind, string $tenant, string $subject, string $action, string $key, int $limit, string $interval): void
    {
        if ($tenant === '' || $subject === '' || $action === '') throw new \InvalidArgumentException('请求范围不能为空');
        $scope = hash('sha256', json_encode([$tenant, $subject, $action, $key], JSON_THROW_ON_ERROR));
        $factory = ConcurrencyServices::rateLimiter([
            'id' => 'peanut-' . $kind,
            'policy' => 'fixed_window',
            'limit' => $limit,
            'interval' => $interval,
        ], $this->storage, $this->locks);
        $result = $factory->create($scope)->consume();
        if (!$result->isAccepted()) {
            $retry = max(1, $result->getRetryAfter()->getTimestamp() - time());
            if ($kind === 'repeat') throw new ApiProblem('DUPLICATE_SUBMISSION', 409, '请勿重复提交。', headers: ['Retry-After' => (string) $retry]);
            throw new ApiProblem('REQUEST_RATE_LIMITED', 429, '请求过于频繁，请稍后重试。', headers: ['Retry-After' => (string) $retry]);
        }
    }
}
