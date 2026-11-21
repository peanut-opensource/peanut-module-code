<?php

declare(strict_types=1);

namespace app\common\services\authorization;

/** An admitted API satisfies its source-declared endpoint checks in this Request only. */
final readonly class EndpointPermissionGrant
{
    public function __construct(private \think\Request $request) {}

    public function record(object $context, array $endpoint): void
    {
        $this->request->withMiddleware([self::class => [
            'context' => $context,
            'api_id' => $endpoint['id'],
            'permission_keys' => json_decode($endpoint['default_permission_keys'], true, 32, JSON_THROW_ON_ERROR),
        ]]);
    }

    public function allows(object $context, string $key): bool
    {
        $grant = $this->request->middleware(self::class);
        return is_array($grant) && ($grant['context'] ?? null) === $context
            && in_array($key, $grant['permission_keys'], true);
    }
}
