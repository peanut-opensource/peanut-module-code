<?php

declare(strict_types=1);

namespace app\adminapi\infrastructure;

/** Exact, method-aware exceptions to Tenant Admin API default-deny RBAC. */
final class AdminApiAccessRegistry
{
    public function __construct(
        private readonly int $configuredVersion,
        private readonly array $configuredRoutes,
    ) {}

    public function isAuthenticatedOnly(string $method, string $path): bool
    {
        return in_array(self::routeKey($method, $path), $this->authenticatedRoutes(), true);
    }

    public function isPublic(string $method, string $path): bool
    {
        return in_array(self::routeKey($method, $path), $this->publicRoutes(), true);
    }

    public function isPlatformPublic(string $method, string $path): bool
    {
        return in_array(self::routeKey($method, $path), $this->routes('platform_public'), true);
    }

    public function isPlatformAuthenticatedOnly(string $method, string $path): bool
    {
        return in_array(self::routeKey($method, $path), $this->routes('platform_authenticated'), true);
    }

    /** @return list<string> */
    public function authenticatedRoutes(): array
    {
        return $this->routes('authenticated');
    }

    /** @return list<string> */
    public function publicRoutes(): array
    {
        return $this->routes('public');
    }

    public function version(): int
    {
        return $this->configuredVersion;
    }

    private static function routeKey(string $method, string $path): string
    {
        return strtoupper(trim($method)) . ' ' . strtolower(trim($path, '/'));
    }

    /** @return list<string> */
    private function routes(string $kind): array
    {
        $routes = $this->configuredRoutes[$kind] ?? [];
        if (!is_array($routes)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn(mixed $route): string => self::normalizeConfiguredRoute((string) $route),
            $routes,
        )));
    }

    private static function normalizeConfiguredRoute(string $route): string
    {
        if (preg_match('/^([A-Z]+)\s+(.+)$/i', trim($route), $matches) !== 1) {
            return '';
        }
        return self::routeKey($matches[1], $matches[2]);
    }
}
