<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Engine\Contract;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;

final class DataProviderRegistry
{
    public const RESOURCE_KEY = ImportExportCommands::RESOURCE_KEY;

    /** @var array<string, DataProvider> */
    private array $providers = [];

    /** @var array<class-string<DataProvider>, true> */
    private static array $contributedClasses = [];

    public static function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(self::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw ImportExportException::denied();
        }
    }

    /**
     * Allow business modules to contribute DataProvider classes without reversing module dependencies.
     * @param class-string<DataProvider> $providerClass
     */
    public static function contribute(string $providerClass): void
    {
        self::$contributedClasses[$providerClass] = true;
    }

    /** @return list<class-string<DataProvider>> */
    public static function contributedClasses(): array
    {
        return array_keys(self::$contributedClasses);
    }

    /** @param iterable<DataProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(DataProvider $provider): void
    {
        $key = $provider->key();
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $key) !== 1 || isset($this->providers[$key])) {
            throw ImportExportException::invalid();
        }
        $this->providers[$key] = $provider;
    }

    public function require(string $key): DataProvider
    {
        return $this->providers[$key] ?? throw ImportExportException::providerUnavailable();
    }
}
