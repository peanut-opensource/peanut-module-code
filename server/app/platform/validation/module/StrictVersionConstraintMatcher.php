<?php

declare(strict_types=1);

namespace app\platform\validation\module;

use PeanutAdmin\Kernel\Module\VersionConstraintMatcher;

/** Supports only exact SemVer and the caret constraints used by Peanut Modules. */
final class StrictVersionConstraintMatcher implements VersionConstraintMatcher
{
    private const VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D';

    public function matches(string $version, string $constraint): bool
    {
        if (preg_match(self::VERSION, $version) !== 1) {
            return false;
        }
        if (preg_match(self::VERSION, $constraint) === 1) {
            return $version === $constraint;
        }
        if (preg_match('/^\^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:\.(0|[1-9][0-9]*))?$/D', $constraint, $match) !== 1) {
            return false;
        }

        $major = (int) $match[1];
        $minor = (int) $match[2];
        $patch = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 0;
        $lower = "{$major}.{$minor}.{$patch}";
        $upper = $major > 0
            ? ($major + 1) . '.0.0'
            : ($minor > 0 ? '0.' . ($minor + 1) . '.0' : '0.0.' . ($patch + 1));

        return version_compare($version, $lower, '>=')
            && version_compare($version, $upper, '<');
    }
}
