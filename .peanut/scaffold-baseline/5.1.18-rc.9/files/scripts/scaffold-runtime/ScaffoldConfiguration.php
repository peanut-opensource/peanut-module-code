<?php

declare(strict_types=1);

namespace app\common\value\scaffold;

use RuntimeException;

/** Static template declarations; transformation behavior stays in ApplicationCreator. */
final class ScaffoldConfiguration
{
    public static function load(): array
    {
        static $configuration = null;
        if ($configuration !== null) { return $configuration; }
        $value = json_decode((string) file_get_contents(__DIR__ . '/scaffold.conf.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($value['protocol'] ?? null) !== 'peanut.scaffold-configuration.v1') {
            throw new RuntimeException('SCAFFOLD_CONFIGURATION_INVALID');
        }
        foreach (['source_snapshot', 'inventory_omissions', 'delivery', 'file_mappings', 'transforms', 'variables', 'text_replacements'] as $key) {
            if (!is_array($value[$key] ?? null)) { throw new RuntimeException('SCAFFOLD_CONFIGURATION_INVALID: ' . $key); }
        }
        foreach (['managed', 'excluded', 'application_owned', 'retained_paths', 'generated_managed_paths', 'owner_by_classification', 'default_profiles', 'profile_overrides', 'adapters'] as $key) {
            if (!is_array($value['delivery'][$key] ?? null)) { throw new RuntimeException('SCAFFOLD_CONFIGURATION_INVALID: delivery.' . $key); }
        }
        foreach (['managed', 'generated-managed', 'app-owned', 'excluded'] as $classification) {
            $owner = $value['delivery']['owner_by_classification'][$classification] ?? null;
            if (!is_string($owner) || trim($owner) === '') {
                throw new RuntimeException('SCAFFOLD_CONFIGURATION_OWNER_INVALID: ' . $classification);
            }
        }
        $selectors = [$value['inventory_omissions'], $value['delivery']['managed'], $value['delivery']['excluded'], $value['delivery']['application_owned'], ...$value['transforms']];
        foreach ($selectors as $selector) {
            if (!is_array($selector) || array_intersect(['paths', 'prefixes', 'patterns'], array_keys($selector)) === []) {
                throw new RuntimeException('SCAFFOLD_CONFIGURATION_SELECTOR_INVALID');
            }
            foreach (['paths', 'prefixes', 'patterns'] as $key) {
                if (!isset($selector[$key])) { continue; }
                if (!is_array($selector[$key]) || !array_is_list($selector[$key])) { throw new RuntimeException('SCAFFOLD_CONFIGURATION_SELECTOR_INVALID'); }
                foreach ($selector[$key] as $entry) {
                    if (!is_string($entry) || $entry === '' || ($key === 'patterns' && (str_contains($entry, '~') || @preg_match('~' . $entry . '~', '') === false))) {
                        throw new RuntimeException('SCAFFOLD_CONFIGURATION_SELECTOR_INVALID');
                    }
                }
            }
        }
        if (!array_is_list($value['variables']) || array_filter($value['variables'], static fn(mixed $variable): bool => !is_string($variable) || preg_match('/^[A-Z][A-Z0-9_]*$/D', $variable) !== 1) !== []) {
            throw new RuntimeException('SCAFFOLD_CONFIGURATION_VARIABLES_INVALID');
        }
        $replacements = $value['text_replacements'];
        if (!is_array($replacements['all'] ?? null) || !is_array($replacements['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_CONFIGURATION_REPLACEMENTS_INVALID');
        }
        foreach ([$replacements['all'], ...array_values($replacements['files'])] as $pairs) {
            if (!is_array($pairs)) { throw new RuntimeException('SCAFFOLD_CONFIGURATION_REPLACEMENTS_INVALID'); }
            foreach ($pairs as $search => $replacement) {
                if (!is_string($search) || $search === '') { throw new RuntimeException('SCAFFOLD_CONFIGURATION_REPLACEMENTS_INVALID'); }
                if (is_string($replacement)) { continue; }
                if (!is_array($replacement) || !in_array($replacement['variable'] ?? null, $value['variables'], true)
                    || array_diff(array_keys($replacement), ['variable', 'prefix', 'suffix']) !== []
                    || !is_string($replacement['prefix'] ?? '') || !is_string($replacement['suffix'] ?? '')) {
                    throw new RuntimeException('SCAFFOLD_CONFIGURATION_REPLACEMENTS_INVALID');
                }
            }
        }
        return $configuration = $value;
    }

    /** Match any declared exact path, directory prefix or existing path pattern. */
    public static function matches(string $path, array $selector): bool
    {
        if (in_array($path, $selector['paths'] ?? [], true)) { return true; }
        foreach ($selector['prefixes'] ?? [] as $prefix) {
            if (str_starts_with($path, $prefix)) { return true; }
        }
        foreach ($selector['patterns'] ?? [] as $pattern) {
            if (preg_match('~' . $pattern . '~', $path) === 1) { return true; }
        }
        return false;
    }
}
