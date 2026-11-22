<?php

declare(strict_types=1);

namespace app\common\http\middleware;

use app\common\execution\CurrentExecutionContext;
use app\common\http\ApiProblem;
use app\common\infrastructure\concurrency\ThinkCacheLimiterStorage;
use app\common\services\RequestGuardService;
use Closure;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\RedisStore;
use think\facade\Cache;
use think\facade\Config;
use think\Request;
use think\Response;

/** Attach after tenant authentication to a route, passing its named policy. */
final readonly class RequestGuardMiddleware
{
    public function __construct(private CurrentExecutionContext $executionContext) {}

    public function handle(Request $request, Closure $next, string $policy): Response
    {
        $config = Config::get('request_guard.policies.' . $policy);
        if (!is_array($config)) throw new \LogicException('REQUEST_GUARD_POLICY_MISSING');
        $cacheName = $config['cache_store'] ?? null;
        $lockType = $config['lock_store'] ?? null;
        if (!is_string($cacheName) || $cacheName === '' || !in_array($lockType, ['file', 'redis'], true)) {
            throw new \LogicException('REQUEST_GUARD_STORAGE_INVALID');
        }
        $cache = Cache::store($cacheName);
        if (($lockType === 'file' && !$cache instanceof \think\cache\driver\File)
            || ($lockType === 'redis' && !$cache instanceof \think\cache\driver\Redis)) {
            throw new \LogicException('REQUEST_GUARD_STORAGE_MISMATCH');
        }
        $lockStore = match ($lockType) {
            'file' => new FlockStore($config['lock_path'] ?? null),
            'redis' => new RedisStore($cache->handler()),
        };
        $guard = new RequestGuardService(new ThinkCacheLimiterStorage($cache), new LockFactory($lockStore));
        $context = $this->executionContext->tenantAdmin();
        $tenant = (string) $context->tenantId;
        $subject = (string) $context->memberId;
        $action = $policy . ':' . $request->method() . ':' . $request->pathinfo();
        $limit = $config['limit'] ?? null;
        $interval = $config['interval'] ?? null;
        $repeatInterval = $config['repeat_interval'] ?? null;
        if ($limit !== null || $interval !== null) {
            if (!is_int($limit) || $limit < 1 || !is_string($interval) || $interval === '') throw new \LogicException('REQUEST_GUARD_RATE_INVALID');
            $guard->limit($tenant, $subject, $action, $limit, $interval);
        } elseif ($repeatInterval === null) {
            throw new \LogicException('REQUEST_GUARD_POLICY_EMPTY');
        }
        if ($repeatInterval !== null) {
            if (!is_string($repeatInterval) || $repeatInterval === '') throw new \LogicException('REQUEST_GUARD_REPEAT_INVALID');
            // Do not attach this policy to durable idempotent commands: their
            // existing service must remain able to replay the stored result.
            $token = trim((string) $request->header('X-Submit-Token', ''));
            if ($token === '' || strlen($token) > 128) throw new ApiProblem('SUBMIT_TOKEN_INVALID', 422, '提交标识无效。');
            $guard->preventRepeat($tenant, $subject, $action, $token, $repeatInterval);
        }
        return $next($request);
    }
}
