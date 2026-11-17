<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Application;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;

final readonly class MachineScopeCatalog
{
    /** @var array<string, true> */
    private array $known;

    /** @param list<string> $scopes */
    public function __construct(array $scopes)
    {
        $known = [];
        foreach ($scopes as $scope) {
            if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $scope) !== 1 || strlen($scope) > 96) {
                throw IntegrationSecurityException::invalid();
            }
            $known[$scope] = true;
        }
        // An unconfigured deployment may still inspect integration records.
        // Grant policy rejects every requested scope until scopes are configured.
        if (count($known) > 128) {
            throw IntegrationSecurityException::invalid();
        }
        $this->known = $known;
    }

    public function contains(string $scope): bool
    {
        return isset($this->known[$scope]);
    }
}
