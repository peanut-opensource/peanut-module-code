<?php

declare(strict_types=1);

namespace app\platform\validation\module;

use Composer\Semver\Semver;
use PeanutAdmin\Kernel\Module\VersionConstraintMatcher;
use UnexpectedValueException;

/** Uses the same constraint semantics as the native Composer dependency solver. */
final class StrictVersionConstraintMatcher implements VersionConstraintMatcher
{
    public function matches(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (UnexpectedValueException) {
            return false;
        }
    }
}
