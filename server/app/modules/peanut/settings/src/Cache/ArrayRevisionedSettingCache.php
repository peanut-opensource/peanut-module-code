<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Cache;

use PeanutAdmin\Modules\Settings\Application\EffectiveSetting;

final class ArrayRevisionedSettingCache
{
    /** @var array<string, EffectiveSetting> */
    private array $values = [];

    public function get(string $key): ?EffectiveSetting
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, EffectiveSetting $setting): void
    {
        $this->values[$key] = $setting;
    }
}
