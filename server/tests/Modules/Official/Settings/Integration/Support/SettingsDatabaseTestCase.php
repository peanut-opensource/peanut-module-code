<?php

declare(strict_types=1);

namespace Tests\Modules\Official\Settings\Integration\Support;

use DateTimeImmutable;
use PDO;
use PDOException;
use ThinkPhpTestConnection;
use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\DataPermissionAdapter;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Kernel\Host\ExternalHostConfiguration;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PeanutAdmin\Kernel\Host\ExternalOperationHost;
use PeanutAdmin\Kernel\Host\ExternalOperationRequest;
use PeanutAdmin\Kernel\Host\ModuleAvailabilityAdapter;
use PeanutAdmin\Kernel\Host\PermissionAdapter;
use PeanutAdmin\Kernel\Host\ProblemDetailsAdapter;
use PeanutAdmin\Kernel\Host\TrustedContextAdapter;
use PeanutAdmin\Kernel\Host\TypedTargetAdapter;
use PeanutAdmin\Kernel\Http\PermissionMiddleware;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Identity\Module\ModuleAvailabilityService;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinition;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionLoader;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionSynchronizer;
use PeanutAdmin\Modules\Settings\Application\SettingAdminService;
use PeanutAdmin\Modules\Settings\Application\SettingResolver;
use PeanutAdmin\Modules\Settings\Cache\ArrayRevisionedSettingCache;
use PeanutAdmin\Modules\Identity\Tenancy\Application\TenantWorkspaceQueryService;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Identity\Query\ThinkPhpPlatformOperatorIdentityQuery;
use PeanutAdmin\Settings\Secret\SecretProtector;
use Tests\Modules\Official\Settings\Integration\Schema\SettingsMigrationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;

require_once dirname(__DIR__, 5) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 5) . '/Support/IdentityMysqlFixture.php';
require_once dirname(__DIR__, 5) . '/Support/ThinkPhpTestConnection.php';

require_once dirname(__DIR__) . '/Schema/SettingsMigrationRunner.php';

abstract class SettingsDatabaseTestCase extends TestCase
{
    protected const NOW = '2026-07-19 08:00:00.000';

    private string $databaseName;
    private bool $databaseCreated;
    protected PDO $database;
    protected SettingsMigrationRunner $runner;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseName = \RegisteredMysqlTestResource::configuredDatabaseName();
        [$this->database, $this->databaseCreated] = \RegisteredMysqlTestResource::openEmptyDatabase($this->databaseName);
        $this->runner = new SettingsMigrationRunner($this->database);
        $this->runner->migrate();
        $root = dirname(__DIR__, 6);
        $app = App::getInstance();
        $cache = require $root . '/config/cache.php';
        if (!is_array($cache)) {
            throw new RuntimeException('The backend cache configuration is invalid.');
        }
        $app->config->set($cache, 'cache');
        $app->cache->clear();
        $connection = ThinkPhpTestConnection::fromPdo($this->database);
        $this->database = $connection->connect();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        if (isset($this->database, $this->databaseCreated)) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            \RegisteredMysqlTestResource::cleanup($this->database, $this->databaseName, $this->databaseCreated);
        }
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $definitions
     * @param list<array{module_key: string, resource_key: string, operation: string, target_cardinality: string}> $targets
     */
    protected function registry(
        array $definitions,
        string $moduleKey = 'example.module',
        array $targets = [],
    ): SettingDefinitionRegistry {
        $file = tempnam(sys_get_temp_dir(), 'peanut-settings-integration-');
        if (!is_string($file)) {
            throw new RuntimeException('Cannot allocate settings fixture file.');
        }
        file_put_contents($file, json_encode($definitions, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $file;
        $registry = new SettingDefinitionRegistry();
        $registry->registerModule(
            $moduleKey,
            (new SettingDefinitionLoader())->load($moduleKey, $file, $targets),
        );

        return $registry;
    }

    /** @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function definition(array $override = []): array
    {
        return array_merge([
            'key' => 'display-mode',
            'name' => 'Display mode',
            'description' => 'Controls a generic display mode.',
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'enum' => ['compact', 'comfortable'],
            ],
            'required' => false,
            'secret' => false,
            'allowed_scopes' => ['deployment', 'tenant', 'target'],
            'target_resource_key' => 'example.project',
            'target_operation' => 'updateProjectSetting',
            'default' => 'comfortable',
        ], $override);
    }

    protected function synchronize(SettingDefinitionRegistry $registry): SettingDefinitionSynchronizer
    {
        $synchronizer = new SettingDefinitionSynchronizer();
        $synchronizer->synchronize($registry, new \DateTimeImmutable(self::NOW . ' UTC'));

        return $synchronizer;
    }

    /** @return array{tenant_id: int, member_id: int} */
    protected function tenant(string $code): array
    {
        $statement = $this->database->prepare(
            "INSERT INTO pa_tenant (code, name, display_name, status, created_at, updated_at) VALUES (:code, 'Settings tenant', 'Settings tenant', 'active', :created_at, :updated_at)",
        );
        $statement->execute(['code' => $code, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $tenantId = (int) $this->database->lastInsertId();
        $this->database->exec("INSERT INTO pa_account (display_name, created_at, updated_at) VALUES ('Settings actor', '" . self::NOW . "', '" . self::NOW . "')");
        $accountId = (int) $this->database->lastInsertId();
        $statement = $this->database->prepare(
            "INSERT INTO pa_tenant_member (tenant_id, account_id, display_name, status, created_at, updated_at) VALUES (:tenant_id, :account_id, 'Settings member', 'active', :created_at, :updated_at)",
        );
        $statement->execute(['tenant_id' => $tenantId, 'account_id' => $accountId, 'created_at' => self::NOW, 'updated_at' => self::NOW]);

        return ['tenant_id' => $tenantId, 'member_id' => (int) $this->database->lastInsertId()];
    }

    protected function operator(): int
    {
        $this->database->exec("INSERT INTO pa_account (display_name, created_at, updated_at) VALUES ('Settings operator', '" . self::NOW . "', '" . self::NOW . "')");
        $accountId = (int) $this->database->lastInsertId();
        $this->database->exec("INSERT INTO pa_platform_operator (account_id, created_at, updated_at) VALUES (" . $accountId . ", '" . self::NOW . "', '" . self::NOW . "')");

        return (int) $this->database->lastInsertId();
    }

    protected function settingResolver(
        SecretProtector $protector,
        ArrayRevisionedSettingCache $cache,
    ): SettingResolver {
        return new SettingResolver(
            $protector,
            $cache,
            new TenantWorkspaceQueryService(new TenantEntitlementService(
                new CompiledModuleRegistry([], [], [], [], 'settings-test'),
            )),
        );
    }

    protected function settingAdmin(SecretProtector $protector): SettingAdminService
    {
        return new SettingAdminService(
            $protector,
            $this->settingResolver($protector, new ArrayRevisionedSettingCache()),
            new ThinkPhpTenantMemberDirectory(),
            new ThinkPhpPlatformOperatorIdentityQuery(),
        );
    }

    protected function additionalDatabaseConnection(): PDO
    {
        return $this->connect();
    }

    /** @param array{tenant_id: int, member_id: int} $tenant
     * @param non-empty-list<string> $targetIds
     */
    protected function authorizeTarget(
        array $tenant,
        string $targetResourceKey,
        array $targetIds,
        ExternalOperationDefinition $operation,
    ): AuthorizedExternalOperation {
        $requestId = 'req_settings_' . substr(hash('sha256', implode(':', [
            $operation->moduleKey,
            $operation->operationId,
            $targetResourceKey,
            ...$targetIds,
        ])), 0, 24);
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            $tenant['tenant_id'],
            1,
            $tenant['member_id'],
            'admin-web',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            1,
            (int) $this->scalar('SELECT authorization_revision FROM pa_tenant WHERE id = ' . $tenant['tenant_id']),
        ), $requestId);
        $typedTargets = array_map(
            static fn(string $targetId): array => [
                'target_resource_key' => $targetResourceKey,
                'target_id' => $targetId,
            ],
            $targetIds,
        );
        $actualPath = preg_replace(
            '/\{[a-z][a-z0-9_]*\}/',
            rawurlencode($targetIds[0]),
            $operation->path,
        );
        if (!is_string($actualPath)) {
            throw new RuntimeException('Cannot build the settings Host request path.');
        }
        $request = new ExternalOperationRequest(
            RequestId::fromHeader($requestId),
            $context,
            $operation->method,
            $actualPath,
            [],
            $typedTargets,
            '01KPEANUT-B03-TARGET-0001',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            new DateTimeImmutable('2026-07-19T09:00:00Z'),
        );
        $targetChecks = 0;
        $dataPermission = new DataPermissionAdapter(
            static fn(): never => throw new RuntimeException('Settings write authorization must not query targets.'),
            static function (
                TenantContext $authorizedContext,
                string $resourceKey,
                string $operationId,
                array $requestedTargets,
            ) use (
                $tenant,
                $operation,
                $targetResourceKey,
                $targetIds,
                &$targetChecks,
            ): void {
                ++$targetChecks;
                self::assertSame($tenant['tenant_id'], $authorizedContext->tenantId);
                self::assertSame($operation->resourceKey, $resourceKey);
                self::assertSame($operation->operationId, $operationId);
                self::assertSame([[
                    'target_resource_key' => $targetResourceKey,
                    'target_role' => 'primary',
                    'target_ids' => $targetIds,
                ]], array_map(
                    static fn($target): array => $target->toArray(),
                    $requestedTargets,
                ));
            },
        );
        $host = $this->authorizationHost($tenant['tenant_id'], $operation, $dataPermission);
        $authorize = new ReflectionMethod(ExternalOperationHost::class, 'authorize');
        $authorize->setAccessible(true);
        $authorized = $authorize->invoke($host, $operation, $request);

        self::assertSame(1, $targetChecks);
        if (!$authorized instanceof AuthorizedExternalOperation) {
            throw new RuntimeException('ExternalOperationHost did not issue a settings authorization.');
        }

        return $authorized;
    }

    protected function scalar(string $sql): int|string|false
    {
        $statement = $this->database->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Database query failed.');
        }

        $value = $statement->fetchColumn();
        if ($value === null) {
            throw new RuntimeException('Database query returned a null scalar.');
        }

        return $value;
    }

    protected function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
        } catch (PDOException $exception) {
            self::assertNotSame('00000', $exception->getCode());

            return;
        }
        self::fail('Expected MySQL to reject the invalid row.');
    }

    private function authorizationHost(
        int $tenantId,
        ExternalOperationDefinition $operation,
        DataPermissionAdapter $dataPermission,
    ): ExternalOperationHost {
        $configuration = new ExternalHostConfiguration(
            new ModuleHostLayout('backend/app/Modules', 'FixtureHost\\App\\Modules', 'frontend/src/modules'),
            ['backend/app/Modules/Example/Module', 'backend/app/Modules/Other/Module'],
            '/api/example/v1',
            '/api/platform/v1',
            'docs/api/openapi.yaml',
            'backend/route/openapi-generated.php',
            'packages/web/generated/api.d.ts',
            ['admin-web'],
            'X-Request-ID',
        );
        $registry = new CompiledModuleRegistry([
            ManifestDocument::fromObject('backend/app/Modules/Example/Module', (object) [
                'key' => 'example.module', 'version' => '1.0.0', 'schema_version' => 1,
                'tenant' => (object) ['enableable' => false],
            ]),
            ManifestDocument::fromObject('backend/app/Modules/Other/Module', (object) [
                'key' => 'other.module', 'version' => '1.0.0', 'schema_version' => 1,
                'tenant' => (object) ['enableable' => false],
            ]),
        ], [], [], [], 'settings-host-revision');
        $installation = $this->database->prepare(<<<'SQL'
INSERT IGNORE INTO pa_module_installation (
    module_key, installed_version, manifest_schema_version, manifest_digest, status, created_at, updated_at
) VALUES (:module_key, '1.0.0', 1, :manifest_digest, 'active', :created_at, :updated_at)
SQL);
        foreach (['example.module', 'other.module'] as $moduleKey) {
            $installation->execute([
                'module_key' => $moduleKey,
                'manifest_digest' => $registry->requireManifest($moduleKey)->digest,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
        $tenantRepository = new class ($operation->permission->permissionKeys) implements TenantAuthorizationRepository {
            /** @param list<string> $permissions */
            public function __construct(private array $permissions) {}

            public function member(int $tenantId, int $memberId): ?array
            {
                return null;
            }

            public function activeRoles(int $tenantId, int $memberId): array
            {
                return [];
            }

            public function revision(int $tenantId, int $memberId): string
            {
                return '1';
            }

            public function permissions(int $tenantId, int $memberId): EffectivePermissionSet
            {
                return new EffectivePermissionSet($this->permissions);
            }

            public function permissionsForKeys(TenantContext $context, array $permissionKeys): EffectivePermissionSet
            {
                return new EffectivePermissionSet(array_values(array_intersect($permissionKeys, $this->permissions)));
            }
        };
        $platformRepository = new class implements PlatformAuthorizationRepository {
            public function revision(int $operatorId): string
            {
                return '1';
            }

            public function permissions(int $operatorId): EffectivePermissionSet
            {
                return new EffectivePermissionSet([]);
            }
        };
        $permissions = new PermissionMiddleware(
            new TenantAuthorizationEvaluator($tenantRepository),
            new PlatformAuthorizationEvaluator($platformRepository, new RevisionPermissionCache()),
        );

        return new ExternalOperationHost(
            $configuration,
            new TrustedContextAdapter($configuration),
            new ModuleAvailabilityAdapter($registry, new ModuleAvailabilityService(
                $registry,
                new TenantEntitlementService($registry),
            )),
            new PermissionAdapter($permissions),
            new TypedTargetAdapter($dataPermission),
            new IdempotencyService(),
            new AuditService(),
            new ProblemDetailsAdapter(),
        );
    }

    private function connect(): PDO
    {
        \RegisteredMysqlTestResource::assertSelectedDatabase($this->database, $this->databaseName);
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST'), env('DB_PORT'), $this->databaseName),
            env('DB_USER'),
            env('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false],
        );
        \RegisteredMysqlTestResource::assertSelectedDatabase($pdo, $this->databaseName);

        return $pdo;
    }
}
