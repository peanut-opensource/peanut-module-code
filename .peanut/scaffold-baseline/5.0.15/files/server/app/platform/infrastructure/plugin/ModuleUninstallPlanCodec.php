<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

/** Canonical JSON and SHA-256 contract shared by CLI and Platform HTTP uninstall flows. */
final class ModuleUninstallPlanCodec
{
    /** @param array<string,mixed> $plan */
    public function encode(array $plan): string
    {
        return json_encode(
            $this->canonicalize($plan),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param array<string,mixed> $plan */
    public function digest(array $plan): string
    {
        return hash('sha256', $this->encode($plan));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
