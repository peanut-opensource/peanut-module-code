<?php

declare(strict_types=1);

namespace app\platform\value\plugin;

use InvalidArgumentException;

/** Normalizes the legacy admin entry and the v1 multi-client Module manifest into canonical source roots. */
final class ModuleFrontendLayout
{
    private const CLIENT_ROOTS = [
        'admin-web' => 'web/src/modules',
        'platform-web' => 'platform/src/modules',
        'pc-web' => 'pc/modules',
        'uniapp' => 'uniapp/src/modules',
    ];

    /** @return list<array{client_key:string,entry:string,root:string}> */
    public static function contributions(array $frontend, string $moduleKey): array
    {
        $slug = str_replace('.', '-', $moduleKey);
        $contributions = [];
        if (array_key_exists('entry', $frontend)) {
            $entry = $frontend['entry'];
            $expected = self::CLIENT_ROOTS['admin-web'] . '/' . $slug . '/contribution.ts';
            if (!is_string($entry) || $entry !== $expected) {
                throw new InvalidArgumentException('Legacy frontend.entry is not the canonical admin-web entry.');
            }
            $contributions['admin-web'] = [
                'client_key' => 'admin-web',
                'entry' => $entry,
                'root' => dirname($entry),
            ];
        }

        $clients = $frontend['clients'] ?? [];
        if (!is_array($clients)) {
            throw new InvalidArgumentException('frontend.clients must be an object.');
        }
        foreach ($clients as $clientKey => $client) {
            if (!is_string($clientKey) || !isset(self::CLIENT_ROOTS[$clientKey])
                || !is_array($client) || array_keys($client) !== ['entry']) {
                throw new InvalidArgumentException('Frontend client contribution is invalid.');
            }
            if (isset($contributions[$clientKey])) {
                throw new InvalidArgumentException('Frontend client contribution is duplicated.');
            }
            $expected = self::CLIENT_ROOTS[$clientKey] . '/' . $slug . '/contribution.ts';
            if (!is_string($client['entry']) || $client['entry'] !== $expected) {
                throw new InvalidArgumentException('Frontend client entry is not canonical.');
            }
            $contributions[$clientKey] = [
                'client_key' => $clientKey,
                'entry' => $expected,
                'root' => dirname($expected),
            ];
        }
        ksort($contributions, SORT_STRING);
        return array_values($contributions);
    }

    public static function clientRoot(string $clientKey): string
    {
        return self::CLIENT_ROOTS[$clientKey]
            ?? throw new InvalidArgumentException('Frontend client key is not supported.');
    }
}
