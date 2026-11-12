<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeException;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeQuery;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence\ReferenceCodeStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\DbManager;
use think\facade\Db;

require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

/** Real owner queries and Identity projections on in-memory SQLite; never uses a configured database. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReferenceCodeSnapshotBoundaryTest extends TestCase
{
    private PDO $database;
    private ReferenceSnapshotConnection $connection;
    private App $app;
    private ReferenceCodeStore $store;
    private ReferenceCodeSetDefinition $definition;
    private TenantContext $context;
    private DateTimeImmutable $asOf;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($root . '/.local/tmp/reference-snapshot-tests');
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->connection = new ReferenceSnapshotConnection($this->database);
        $manager = new \SharedPdoDbManager($this->connection);
        $this->connection->setDb($manager);
        $this->app->instance(DbManager::class, $manager);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT, security_revision INTEGER, authorization_revision INTEGER DEFAULT 1);
            CREATE TABLE pa_account (id INTEGER PRIMARY KEY, status TEXT, display_name TEXT, security_revision INTEGER);
            CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, display_name TEXT, status TEXT, primary_department_id INTEGER, security_revision INTEGER, authorization_revision INTEGER);
            CREATE TABLE pa_reference_code_set (id INTEGER PRIMARY KEY, module_key TEXT, set_key TEXT, name TEXT, description TEXT, definition_digest TEXT, lifecycle TEXT, revision INTEGER, created_at TEXT, updated_at TEXT);
            CREATE TABLE pa_reference_code_entry (id INTEGER PRIMARY KEY, tenant_id INTEGER, set_id INTEGER, code TEXT, lifecycle TEXT, revision INTEGER, created_by_member_id, updated_by_member_id, retired_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE pa_reference_code_entry_version (id INTEGER PRIMARY KEY, entry_id INTEGER, revision INTEGER, label TEXT, metadata_json TEXT, status TEXT, sort_order INTEGER, effective_at TEXT, expires_at TEXT, changed_by_member_id, created_at TEXT);
            INSERT INTO pa_tenant (id,status,security_revision) VALUES (101,'active',1),(202,'active',1);
            INSERT INTO pa_account VALUES (501,'active','Current actor',1),(502,'disabled','Historical author',1),(503,'disabled','Historical editor',1),(901,'active','Other tenant',1);
            INSERT INTO pa_tenant_member VALUES (601,101,501,'Current actor','active',NULL,1,7),(602,101,502,'','disabled',NULL,1,1),(603,101,503,'Former editor','disabled',NULL,1,1),(902,202,901,'Other tenant','active',NULL,1,7);
            INSERT INTO pa_reference_code_set VALUES (1,'fixture.history','codes','History','Fixture definition','fixed-fixture-digest','active',1,'2031-01-01 00:00:00.000','2031-01-01 00:00:00.000');
            INSERT INTO pa_reference_code_entry VALUES (10,101,1,'sample-code','active',2,602,603,NULL,'2031-01-01 00:00:00.000','2031-01-02 00:00:00.000'),(20,202,1,'foreign-code','active',1,902,902,NULL,'2031-01-01 00:00:00.000','2031-01-01 00:00:00.000');
            INSERT INTO pa_reference_code_entry_version VALUES (11,10,1,'Earlier','{}','active',0,'2031-01-01 00:00:00.000','2031-01-02 00:00:00.000',602,'2031-01-01 00:00:00.000'),(12,10,2,'Later','{}','active',0,'2031-01-02 00:00:00.000',NULL,603,'2031-01-02 00:00:00.000'),(21,20,1,'Foreign','{}','active',0,'2031-01-01 00:00:00.000',NULL,902,'2031-01-01 00:00:00.000');
            SQL);
        $execution = new CurrentExecutionContext(new ExecutionContextStore());
        $this->app->instance(AdminDirectoryQuery::class, new AdminDirectoryQuery($execution));
        $this->app->instance(\PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory::class, new ThinkPhpTenantMemberDirectory());
        $this->store = $this->app->make(ReferenceCodeStore::class);
        $this->definition = new ReferenceCodeSetDefinition('fixture.history', 'codes', 'History', 'Fixture definition', 'fixed-fixture-digest');
        $this->asOf = new DateTimeImmutable('2031-01-01T12:00:00.000Z');
        $this->context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'reference-snapshot-session',
            101,
            501,
            601,
            'admin-web',
            new DateTimeImmutable('2031-01-01T00:00:00Z'),
            7,
            1,
        ), 'reference-snapshot-request');
    }

    public function testNonemptySnapshotUsesNativeFactoryAndKeepsInactiveHistoricalAttribution(): void
    {
        $before = $this->database->query('SELECT * FROM pa_reference_code_entry_version ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $snapshot = $this->store->snapshot($this->definition, $this->context, null, $this->asOf);
        self::assertSame($this->asOf, $snapshot['as_of']);
        self::assertCount(1, $snapshot['entries']);
        self::assertSame('sample-code', $snapshot['entries'][0]['entry']['code']);
        self::assertSame([1, 2], array_column($snapshot['entries'][0]['versions'], 'revision'));
        self::assertSame($before, $this->database->query('SELECT * FROM pa_reference_code_entry_version ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertStringContainsString('BINARY `code` ASC', implode("\n", $this->connection->sql));
        self::assertSame($this->database, Db::connect()->getPdo());
        self::assertFalse($this->database->inTransaction());
    }

    public function testEffectiveHistoryBoundaryAndRetirementSemanticsRemainIntact(): void
    {
        $query = new ReferenceCodeQuery($this->store);
        $before = $query->get($this->definition, $this->context, 'sample-code', $this->asOf);
        self::assertSame('Earlier', $before->effective['label']);
        self::assertSame(2, $before->revision);
        self::assertSame('"rev-2"', $before->etag);
        $after = $query->get($this->definition, $this->context, 'sample-code', new DateTimeImmutable('2031-01-02T00:00:00.000Z'));
        self::assertSame('Later', $after->effective['label']);
        self::assertSame(2, $after->effective['revision']);
        $this->database->exec("UPDATE pa_reference_code_entry SET lifecycle='retired',retired_at='2031-01-02 00:00:00.000' WHERE id=10; UPDATE pa_reference_code_entry_version SET status='inactive' WHERE id=12;");
        self::assertSame('active', $query->get($this->definition, $this->context, 'sample-code', $this->asOf)->lifecycle);
        $now = new DateTimeImmutable('2031-01-03T00:00:00.000Z');
        self::assertSame(0, $query->list($this->definition, $this->context, $now)['total']);
        self::assertSame(1, $query->list($this->definition, $this->context, $now, includeRetired: true)['total']);
    }

    public static function invalidAttribution(): array
    {
        $cases = [];
        foreach (['created_by_member_id', 'updated_by_member_id', 'changed_by_member_id'] as $field) {
            foreach (['foreign' => 902, 'missing' => 9999, 'null' => null, 'zero' => 0, 'negative' => -1, 'malformed' => '602x', 'overflow' => '9223372036854775808'] as $name => $value) {
                $cases[$field . '-' . $name] = [$field, $value];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidAttribution')]
    public function testEveryAttributionRejectsForeignMissingOrMalformedMembership(string $field, mixed $value): void
    {
        $table = $field === 'changed_by_member_id' ? 'pa_reference_code_entry_version' : 'pa_reference_code_entry';
        $id = $field === 'changed_by_member_id' ? 11 : 10;
        $this->database->prepare("UPDATE {$table} SET {$field}=? WHERE id=?")->execute([$value, $id]);
        $this->expectException(ReferenceCodeException::class);
        $this->expectExceptionMessage('The reference-code operation could not be completed.');
        $this->store->snapshot($this->definition, $this->context, null, $this->asOf);
    }

    public static function invalidActor(): array
    {
        return [
            ['UPDATE pa_tenant_member SET status=\'disabled\' WHERE id=601'],
            ['UPDATE pa_tenant_member SET tenant_id=202 WHERE id=601'],
            ['UPDATE pa_tenant_member SET authorization_revision=8 WHERE id=601'],
            ['UPDATE pa_tenant_member SET account_id=901 WHERE id=601'],
            ['UPDATE pa_account SET status=\'disabled\' WHERE id=501'],
            ['UPDATE pa_tenant SET status=\'closed\' WHERE id=101'],
        ];
    }

    #[DataProvider('invalidActor')]
    public function testCurrentActorIsStillValidatedBeforeReadingSnapshot(string $change): void
    {
        $this->database->exec($change);
        try {
            $this->store->snapshot($this->definition, $this->context, null, $this->asOf);
            self::fail('An invalid actor must not read the snapshot.');
        } catch (ReferenceCodeException $error) {
            self::assertSame('REFERENCE_CODE_NOT_FOUND', $error->errorCode);
        }
        self::assertStringNotContainsString('reference_code_entry', implode("\n", $this->connection->sql));
    }

    public function testMembershipResultDoesNotLeakAcrossSnapshotCalls(): void
    {
        self::assertCount(1, $this->store->snapshot($this->definition, $this->context, null, $this->asOf)['entries']);
        $this->database->exec('UPDATE pa_tenant_member SET tenant_id=202 WHERE id=602');
        $this->expectException(ReferenceCodeException::class);
        $this->store->snapshot($this->definition, $this->context, null, $this->asOf);
    }

    public function testMissingHistoricalDirectoryCannotAdmitNonemptyData(): void
    {
        $store = new ReferenceCodeStore(new ThinkPhpTenantMemberDirectory());
        $this->expectException(ReferenceCodeException::class);
        $store->snapshot($this->definition, $this->context, null, $this->asOf);
    }

    public function testEmptySnapshotAndForeignCodeReturnNoForeignRecords(): void
    {
        self::assertSame([], $this->store->snapshot($this->definition, $this->context, 'foreign-code', $this->asOf)['entries']);
        self::assertSame([], $this->store->snapshot($this->definition, $this->context, 'missing-code', $this->asOf)['entries']);
    }

    public function testMissingIdentityStorageRemainsAFailure(): void
    {
        $this->database->exec('DROP TABLE pa_tenant_member');
        $this->expectException(\Throwable::class);
        $this->store->snapshot($this->definition, $this->context, null, $this->asOf);
    }

    public function testRetainedIntegrationFactorySuppliesTheActualIdentityDependencies(): void
    {
        // Only its repository factory is invoked. The legacy MySQL setUp/tearDown is never run here.
        require_once dirname(__DIR__) . '/Modules/Official/ReferenceCodes/Versioned/Integration/Support/ReferenceCodesDatabaseTestCase.php';
        $fixture = new class ('fixtureFactory') extends \Tests\Modules\Official\ReferenceCodes\Versioned\Integration\Support\ReferenceCodesDatabaseTestCase {
            public function storeFor(ReferenceCodeSetDefinition $definition): ReferenceCodeStore
            {
                return $this->repository($definition);
            }
        };
        $store = $fixture->storeFor($this->definition);
        $result = (new ReferenceCodeQuery($store))->get($this->definition, $this->context, 'sample-code', $this->asOf);
        self::assertSame('Earlier', $result->effective['label']);
        self::assertSame(2, $result->revision);
    }

    private function directoryReads(): array
    {
        return array_values(array_filter($this->connection->sql, static fn(string $sql): bool =>
            str_starts_with(ltrim(strtoupper($sql)), 'SELECT ')
            && str_contains($sql, 'pa_tenant_member') && !str_contains(strtoupper($sql), ' JOIN ')));
    }

    public function testRepeatedAuthorsAreDeduplicatedOnceWithinEachSnapshot(): void
    {
        for ($id = 100; $id < 125; ++$id) {
            $this->database->prepare('INSERT INTO pa_reference_code_entry SELECT ?,tenant_id,set_id,?,lifecycle,revision,created_by_member_id,updated_by_member_id,retired_at,created_at,updated_at FROM pa_reference_code_entry WHERE id=10')->execute([$id, 'batch-code-' . $id]);
            $this->database->prepare('INSERT INTO pa_reference_code_entry_version SELECT ?,?,revision,label,metadata_json,status,sort_order,effective_at,expires_at,changed_by_member_id,created_at FROM pa_reference_code_entry_version WHERE id=?')->execute([$id * 10, $id, 11]);
            $this->database->prepare('INSERT INTO pa_reference_code_entry_version SELECT ?,?,revision,label,metadata_json,status,sort_order,effective_at,expires_at,changed_by_member_id,created_at FROM pa_reference_code_entry_version WHERE id=?')->execute([$id * 10 + 1, $id, 12]);
        }
        $snapshot = $this->store->snapshot($this->definition, $this->context, null, $this->asOf);
        self::assertCount(26, $snapshot['entries']);
        self::assertSame(52, array_sum(array_map(static fn(array $item): int => count($item['versions']), $snapshot['entries'])));
        self::assertCount(1, $this->directoryReads(), '104 attribution references to two authors must use one actual directory SELECT.');
        $this->connection->sql = [];
        $again = $this->store->snapshot($this->definition, $this->context, null, $this->asOf);
        self::assertSame($snapshot, $again);
        self::assertCount(1, $this->directoryReads(), 'Each new snapshot must query identity again, not reuse a cross-snapshot cache.');
    }

    public function testDistinctAuthorsUseThePublicDirectoryChunkBound(): void
    {
        for ($offset = 0; $offset < 501; ++$offset) {
            $id = 2000 + $offset;
            $this->database->prepare('INSERT INTO pa_tenant_member VALUES (?,101,502,?,\'disabled\',NULL,1,1)')->execute([$id, '']);
            $this->database->prepare('INSERT INTO pa_reference_code_entry_version VALUES (?,10,?,\'History\',\'{}\',\'active\',0,\'2031-01-01 00:00:00.000\',NULL,?,\'2031-01-01 00:00:00.000\')')->execute([$id, $offset + 3, $id]);
        }
        $this->database->exec('UPDATE pa_reference_code_entry SET revision=503 WHERE id=10');
        $snapshot = $this->store->snapshot($this->definition, $this->context, 'sample-code', $this->asOf);
        self::assertCount(503, $snapshot['entries'][0]['versions']);
        self::assertCount(2, $this->directoryReads(), '503 distinct authors must follow the existing 500-ID batch boundary.');
        $this->database->exec('UPDATE pa_tenant_member SET tenant_id=202 WHERE id=2500');
        $this->expectException(ReferenceCodeException::class);
        $this->store->snapshot($this->definition, $this->context, 'sample-code', $this->asOf);
    }

    public function testEmptySnapshotDoesNotIssueAHistoricalDirectoryQuery(): void
    {
        self::assertSame([], $this->store->snapshot($this->definition, $this->context, 'missing-code', $this->asOf)['entries']);
        self::assertSame([], $this->directoryReads());
    }

    public function testVersionQueriesDoNotGrowPerEntryAndRemainInsideTheSelectedSet(): void
    {
        $this->database->exec("INSERT INTO pa_reference_code_entry SELECT 30,tenant_id,set_id,'another-code',lifecycle,revision,created_by_member_id,updated_by_member_id,retired_at,created_at,updated_at FROM pa_reference_code_entry WHERE id=10;");
        $this->database->exec('INSERT INTO pa_reference_code_entry_version SELECT id+30,30,revision,label,metadata_json,status,sort_order,effective_at,expires_at,changed_by_member_id,created_at FROM pa_reference_code_entry_version WHERE entry_id=10;');
        $snapshot = $this->store->snapshot($this->definition, $this->context, null, $this->asOf);
        self::assertSame(['another-code', 'sample-code'], array_column(array_column($snapshot['entries'], 'entry'), 'code'));
        $queries = array_values(array_filter($this->connection->sql, static fn(string $sql): bool => str_starts_with(ltrim(strtoupper($sql)), 'SELECT ') && str_contains($sql, 'pa_reference_code_entry_version')));
        self::assertCount(1, $queries, 'One owner-scoped version SELECT must replace per-entry version reads.');
        self::assertSame([1, 2], array_column($snapshot['entries'][0]['versions'], 'revision'));
        self::assertSame([1, 2], array_column($snapshot['entries'][1]['versions'], 'revision'));
    }

    public function testPaginationStillRejectsCorruptHistoryOutsideRequestedPageOrFilter(): void
    {
        $this->database->exec("INSERT INTO pa_reference_code_entry SELECT 30,tenant_id,set_id,'z-hidden-code',lifecycle,revision,created_by_member_id,updated_by_member_id,retired_at,created_at,updated_at FROM pa_reference_code_entry WHERE id=10;");
        $this->database->exec("INSERT INTO pa_reference_code_entry_version SELECT id+30,30,revision,label,'not-json','inactive',sort_order,effective_at,expires_at,changed_by_member_id,created_at FROM pa_reference_code_entry_version WHERE entry_id=10;");
        $query = new ReferenceCodeQuery($this->store);
        $this->expectException(ReferenceCodeException::class);
        $query->list($this->definition, $this->context, $this->asOf, effectiveStatus: 'active', page: 1, pageSize: 1);
    }

    public function testFilteredPaginationUsesEffectiveWinnerTotalAndStableCodeOrder(): void
    {
        foreach (['z-code', 'a-code', 'b-code'] as $offset => $code) {
            $id = 100 + $offset;
            $this->database->prepare('INSERT INTO pa_reference_code_entry SELECT ?,tenant_id,set_id,?,lifecycle,revision,created_by_member_id,updated_by_member_id,retired_at,created_at,updated_at FROM pa_reference_code_entry WHERE id=10')->execute([$id, $code]);
            foreach ([11, 12] as $version) {
                $this->database->prepare('INSERT INTO pa_reference_code_entry_version SELECT ?,?,revision,label,metadata_json,status,?,effective_at,expires_at,changed_by_member_id,created_at FROM pa_reference_code_entry_version WHERE id=?')->execute([$id * 10 + $version, $id, $code === 'b-code' ? -1 : 0, $version]);
            }
        }
        $this->database->exec("UPDATE pa_reference_code_entry_version SET status='inactive' WHERE entry_id=10");
        $query = new ReferenceCodeQuery($this->store);
        $page = $query->list($this->definition, $this->context, $this->asOf, effectiveStatus: 'active', page: 2, pageSize: 2);
        self::assertSame(3, $page['total']);
        self::assertSame(['z-code'], array_map(static fn($item): string => $item->code, $page['items']));
        self::assertSame('2031-01-01T12:00:00.000Z', $page['as_of']);
        self::assertSame([], $query->list($this->definition, $this->context, $this->asOf, page: 100, pageSize: 1)['items']);
    }

    public function testListUsesDatabaseCountAndPageAfterBoundedIntegrityScan(): void
    {
        for ($offset = 0; $offset < 250; ++$offset) {
            $id = 100 + $offset;
            $this->database->prepare('INSERT INTO pa_reference_code_entry SELECT ?,tenant_id,set_id,?,lifecycle,revision,created_by_member_id,updated_by_member_id,retired_at,created_at,updated_at FROM pa_reference_code_entry WHERE id=10')->execute([$id, sprintf('batch-%03d', $offset)]);
            foreach ([11, 12] as $version) {
                $this->database->prepare('INSERT INTO pa_reference_code_entry_version SELECT ?,?,revision,label,metadata_json,status,sort_order,effective_at,expires_at,changed_by_member_id,created_at FROM pa_reference_code_entry_version WHERE id=?')->execute([$id * 10 + $version, $id, $version]);
            }
        }
        $page = (new ReferenceCodeQuery($this->store))->list($this->definition, $this->context, $this->asOf, page: 2, pageSize: 2);
        self::assertSame(251, $page['total']);
        self::assertSame(['batch-002', 'batch-003'], array_map(static fn($item): string => $item->code, $page['items']));
        $sql = implode("\n", $this->connection->sql);
        self::assertMatchesRegularExpression('/SELECT[^;\n]*COUNT\(/i', $sql, 'The total must come from the same database-side effective query.');
        self::assertMatchesRegularExpression('/LIMIT\s+2\s*(?:,\s*2|OFFSET\s+2)/i', $sql, 'Only the requested page is selected after validation.');
        foreach ($this->connection->sql as $statement) {
            if (preg_match('/^SELECT \* FROM [`"]?pa_reference_code_entry[`"]?\s/i', $statement)) {
                self::assertStringContainsString('LIMIT', strtoupper($statement), 'Integrity reads must be bounded rather than materializing all entries.');
            }
        }
        self::assertCount(1, $this->directoryReads(), 'Repeated authors are verified once in the same paging transaction.');
    }

    public function testTemporalPagingSelectsGreatestEffectiveRevisionNotLatestRevision(): void
    {
        $this->database->exec("UPDATE pa_reference_code_entry_version SET expires_at=NULL WHERE id=11; UPDATE pa_reference_code_entry_version SET effective_at='2031-01-01 06:00:00.000',expires_at='2031-01-01 18:00:00.000',status='inactive' WHERE id=12;");
        $query = new ReferenceCodeQuery($this->store);
        $during = $query->list($this->definition, $this->context, $this->asOf, effectiveStatus: 'inactive', pageSize: 1);
        self::assertSame(1, $during['total']);
        self::assertSame(2, $during['items'][0]->effective['revision']);
        self::assertSame(0, $query->list($this->definition, $this->context, $this->asOf, effectiveStatus: 'active')['total']);
        $after = $query->list($this->definition, $this->context, new DateTimeImmutable('2031-01-01T18:00:00.000Z'), effectiveStatus: 'active');
        self::assertSame(1, $after['total']);
        self::assertSame(1, $after['items'][0]->effective['revision']);
        self::assertSame(2, $after['items'][0]->revision);
        self::assertSame('"rev-2"', $after['items'][0]->etag);
    }

    public function testOwnerUsesOnlyPublicIdentityContractsAndKeepsBinding(): void
    {
        $source = file_get_contents((new \ReflectionClass(ReferenceCodeStore::class))->getFileName());
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/peanut/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(AdminDirectoryQuery::class, $manifest['contracts']['exports']);
        self::assertStringContainsString('memberDisplayNames', $source);
        self::assertStringNotContainsString("Db::name('tenant_member')", $source);
        self::assertStringNotContainsString('Identity\\Persistence', $source);
    }
}

/** Adapt only MySQL's binary-order token; keep original query text and every predicate observable. */
final class ReferenceSnapshotConnection extends \think\db\connector\Sqlite
{
    public array $sql = [];

    public function __construct(private readonly PDO $fixture)
    {
        parent::__construct(['type' => 'sqlite', 'builder' => \think\db\builder\Sqlite::class, 'prefix' => 'pa_']);
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->fixture;
    }

    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): PDOStatement
    {
        $this->sql[] = $sql;
        return parent::getPDOStatement(str_replace(
            ['BINARY `code` ASC', 'BINARY entry.code ASC'],
            ['`code` COLLATE BINARY ASC', 'entry.code COLLATE BINARY ASC'],
            $sql,
        ), $bind, $master, $procedure);
    }
}
