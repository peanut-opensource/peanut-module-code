<?php

declare(strict_types=1);

namespace app\common\http;

use think\Response;

/** APP browser policy: a late response can only mutate cookies selected by its own access credential. */
final class SessionRefreshCookie
{
    private const OPTIONS = ['path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];

    public static function name(string $baseName, #[\SensitiveParameter] string $accessToken): string
    {
        if ($accessToken === '') {
            throw new \InvalidArgumentException('SESSION_COOKIE_ACCESS_REQUIRED');
        }
        return $baseName . '_' . hash('sha256', $accessToken);
    }

    /** Snapshot only this client's access-bound names present in the incoming request. @return list<string> */
    public static function observedNames(object $request, string $baseName): array
    {
        $names = [];
        foreach (array_keys((array) $request->cookie()) as $name) {
            if (is_string($name) && preg_match('/^' . preg_quote($baseName, '/') . '_[a-f0-9]{64}$/D', $name) === 1) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /** @param list<string> $names @return array<string,null> */
    public static function clear(array $names): array
    {
        return array_fill_keys($names, null);
    }

    /** @param array<string,string|null> $cookies */
    public static function apply(Response $response, #[\SensitiveParameter] array $cookies): Response
    {
        foreach ($cookies as $name => $value) {
            if ($value === null) {
                $response->getCookie()->delete($name, self::OPTIONS);
            } else {
                $response->cookie($name, $value, ['expire' => 1209600, ...self::OPTIONS]);
            }
        }
        return $response;
    }

    private function __construct() {}
}
