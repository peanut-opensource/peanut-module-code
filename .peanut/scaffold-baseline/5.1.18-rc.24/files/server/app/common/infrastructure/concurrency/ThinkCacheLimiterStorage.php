<?php

declare(strict_types=1);

namespace app\common\infrastructure\concurrency;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/** Uses the explicitly selected application cache; the caller supplies a matching lock store. */
final readonly class ThinkCacheLimiterStorage implements StorageInterface
{
    public function __construct(private CacheInterface $cache) {}

    public function save(LimiterStateInterface $limiterState): void
    {
        $ttl = $limiterState->getExpirationTime();
        if (!$this->cache->set($this->key($limiterState->getId()), $limiterState, $ttl === null ? null : max(1, $ttl))) {
            throw new \RuntimeException('LIMITER_CACHE_WRITE_FAILED');
        }
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $state = $this->cache->get($this->key($limiterStateId));
        return $state instanceof LimiterStateInterface ? $state : null;
    }

    public function delete(string $limiterStateId): void
    {
        if (!$this->cache->delete($this->key($limiterStateId))) throw new \RuntimeException('LIMITER_CACHE_DELETE_FAILED');
    }

    private function key(string $id): string { return 'limiter.' . hash('sha256', $id); }
}
