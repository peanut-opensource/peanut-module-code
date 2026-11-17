<?php

declare(strict_types=1);

namespace app\common\value\runtime;

use think\facade\Db;
use think\facade\Config;

/** Stable non-secret namespace for identical database endpoint settings; aliases remain distinct. */
final readonly class RuntimeNamespace
{
    private const PROJECT_ID = 'peanut-admin';

    private function __construct(
        private string $identity,
        private string $tablePrefix,
    ) {}

    public static function fromConfiguration(): self
    {
        return self::fromDatabaseConfiguration(
            Config::get('database', []),
            (string) Config::get('peanut.environment', 'production'),
        );
    }

    /** Config files call this before ThinkPHP's configuration repository is available. */
    public static function fromDatabaseConfiguration(array $configuration, string $environment): self
    {
        $connections = $configuration['connections'] ?? [];
        if ($connections === []) {
            // Before setup there is no database identity. Native file stores are
            // local to this application; its root also isolates the session name.
            return new self(self::PROJECT_ID . "\0bootstrap\0" . $environment . "\0" . app()->getRootPath(), '');
        }
        $connection = $connections[$configuration['default'] ?? ''] ?? null;
        if (!is_array($connection) || ($connection['database'] ?? '') === '') {
            throw new \RuntimeException('RUNTIME_NAMESPACE_DATABASE_INVALID');
        }
        $prefix = (string) ($connection['prefix'] ?? '');
        return new self(implode("\0", [
            self::PROJECT_ID,
            $environment,
            (string) ($connection['type'] ?? ''),
            (string) ($connection['hostname'] ?? ''),
            (string) ($connection['hostport'] ?? ''),
            (string) $connection['database'],
            $prefix,
        ]), $prefix);
    }

    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->identity), 0, 24);
    }

    public function cachePrefix(): string
    {
        return 'pa:i:' . $this->fingerprint() . ':';
    }

    public function cacheTagPrefix(): string
    {
        return $this->cachePrefix() . 'tag:';
    }

    public function sessionName(): string
    {
        return 'PA_SESSION_' . strtoupper(substr($this->fingerprint(), 0, 16));
    }

    public function sessionPrefix(): string
    {
        return $this->cachePrefix() . 'session:';
    }

    public function advisoryLockName(string $logicalName): string
    {
        $database = Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? null;
        if (!is_string($database) || trim($database) === '' || trim($logicalName) === '') {
            throw new \RuntimeException('RUNTIME_NAMESPACE_DATABASE_INVALID');
        }

        // MySQL already scopes advisory locks to its server. All callers using
        // the same schema/tables must contend even through different endpoints.
        $databaseFingerprint = substr(hash('sha256', self::PROJECT_ID . "\0" . $database . "\0" . $this->tablePrefix), 0, 24);
        $logicalFingerprint = substr(hash('sha256', $logicalName), 0, 32);
        return "pa:l:{$databaseFingerprint}:{$logicalFingerprint}";
    }
}
