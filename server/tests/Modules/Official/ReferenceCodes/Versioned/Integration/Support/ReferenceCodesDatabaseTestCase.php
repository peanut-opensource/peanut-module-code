<?php

declare(strict_types=1);

namespace Tests\Modules\Official\ReferenceCodes\Versioned\Integration\Support;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use DateTimeImmutable;
use PDO;
use PDOException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeAdminService;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeException;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeQuery;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetLoader;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence\ReferenceCodeStore;
use Tests\Modules\Official\ReferenceCodes\Versioned\Integration\Schema\ReferenceCodesMigrationRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\db\PDOConnection;

require_once dirname(__DIR__, 6) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 6) . '/Support/IdentityMysqlFixture.php';
require_once dirname(__DIR__, 6) . '/Support/ThinkPhpTestConnection.php';

require_once dirname(__DIR__) . '/Schema/ReferenceCodesMigrationRunner.php';

abstract class ReferenceCodesDatabaseTestCase extends TestCase
{
    protected const NOW = '2026-07-20T08:00:00.000Z';

    private string $databaseName;
    private bool $databaseCreated;
    protected PDO $database;
    protected PDOConnection $connection;
    protected ReferenceCodesMigrationRunner $runner;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseName = \RegisteredMysqlTestResource::configuredDatabaseName();
        [$this->database, $this->databaseCreated] = \RegisteredMysqlTestResource::openEmptyDatabase($this->databaseName);
        $this->runner = new ReferenceCodesMigrationRunner($this->database);
        $this->runner->migrate();
        $this->connection = \ThinkPhpTestConnection::fromPdo($this->database);
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

    protected function definition(
        string $moduleKey = 'example.reference-owner',
        string $setKey = 'generic-codes',
        string $name = 'Generic codes',
        string $description = 'Neutral reusable reference values.',
    ): ReferenceCodeSetDefinition {
        $file = tempnam(sys_get_temp_dir(), 'peanut-reference-codes-');
        if (!is_string($file)) {
            throw new RuntimeException('Cannot allocate a reference-code fixture.');
        }
        file_put_contents($file, json_encode([[
            'key' => $setKey,
            'name' => $name,
            'description' => $description,
        ]], JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $file;

        return (new ReferenceCodeSetLoader())->load($moduleKey, $file)[0];
    }

    protected function registry(ReferenceCodeSetDefinition ...$definitions): ReferenceCodeSetRegistry
    {
        $registry = new ReferenceCodeSetRegistry();
        $grouped = [];
        foreach ($definitions as $definition) {
            $grouped[$definition->moduleKey][] = $definition;
        }
        foreach ($grouped as $moduleKey => $owned) {
            $registry->registerModule($moduleKey, $owned);
        }

        return $registry;
    }

    protected function repository(ReferenceCodeSetDefinition $definition): ReferenceCodeStore
    {
        $store = new ReferenceCodeStore(
            new ThinkPhpTenantMemberDirectory(),
            new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore())),
        );
        $store->synchronize($this->registry($definition), new DateTimeImmutable(self::NOW));

        return $store;
    }

    protected function adminService(ReferenceCodeStore $store): ReferenceCodeAdminService
    {
        return new ReferenceCodeAdminService($store);
    }

    protected function query(ReferenceCodeStore $store): ReferenceCodeQuery
    {
        return new ReferenceCodeQuery($store);
    }

    /** @return array{tenant_id: int, member_id: int, context: TenantContext} */
    protected function tenant(string $code): array
    {
        $now = (new DateTimeImmutable(self::NOW))->format('Y-m-d H:i:s.v');
        $statement = $this->database->prepare("INSERT INTO pa_tenant (code, name, display_name, status, created_at, updated_at) VALUES (:code, 'Reference tenant', 'Reference tenant', 'active', :created_at, :updated_at)");
        $statement->execute(['code' => $code, 'created_at' => $now, 'updated_at' => $now]);
        $tenantId = (int) $this->database->lastInsertId();
        $this->database->exec("INSERT IGNORE INTO pa_account (id, display_name, created_at, updated_at) VALUES (1, 'Reference actor', '" . $now . "', '" . $now . "')");
        $statement = $this->database->prepare(
            "INSERT INTO pa_tenant_member (tenant_id, account_id, display_name, status, created_at, updated_at) VALUES (:tenant_id, 1, 'Reference member', 'active', :created_at, :updated_at)",
        );
        $statement->execute(['tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now]);
        $memberId = (int) $this->database->lastInsertId();

        return [
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'context' => $this->context($tenantId, $memberId),
        ];
    }

    protected function context(int $tenantId, int $memberId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            $tenantId,
            1,
            $memberId,
            'admin-web',
            new DateTimeImmutable(self::NOW),
            1,
            (int) $this->scalar('SELECT authorization_revision FROM pa_tenant WHERE id = ' . $tenantId),
        ), 'req_reference_codes_' . $tenantId . '_' . $memberId);
    }

    /** @param array<string, mixed> $override */
    protected function create(
        ReferenceCodeAdminService $service,
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code = 'sample-code',
        array $override = [],
    ): \PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\EffectiveReferenceCode {
        $input = array_merge([
            'label' => 'Sample label',
            'metadata' => [],
            'status' => 'active',
            'sort_order' => 0,
            'effective_at' => new DateTimeImmutable('2020-01-01T00:00:00.000Z'),
            'expires_at' => null,
        ], $override);

        return $service->create(
            $definition,
            $context,
            $code,
            $input['label'],
            $input['metadata'],
            $input['status'],
            $input['sort_order'],
            $input['effective_at'],
            $input['expires_at'],
            '*',
        );
    }

    protected function expectReferenceCodeError(string $code, int $status, callable $operation): void
    {
        try {
            $operation();
        } catch (ReferenceCodeException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->httpStatus);

            return;
        }
        self::fail("Expected reference-code error {$code}.");
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

}
