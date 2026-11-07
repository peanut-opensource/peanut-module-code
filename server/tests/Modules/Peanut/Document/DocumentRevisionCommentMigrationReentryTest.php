<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the real migration against captured MySQL DDL, without opening a connection. */
final class DocumentRevisionCommentMigrationReentryTest extends TestCase
{
    private const TABLES = [
        'pa_document_comment_thread',
        'pa_document_comment_message',
        'pa_document_comment_operation',
    ];

    private array $events = [];
    private array $createdSql = [];

    public static function setUpBeforeClass(): void
    {
        $server = dirname(__DIR__, 4);
        require_once $server . '/vendor/autoload.php';
        require_once $server . '/app/modules/peanut/document/database/migrations/20261107100007_document_revision_comments.php';
    }

    public function testExistingMysqlTablesAreAcceptedWithoutCreate(): void
    {
        $migration = $this->migration($this->schemas(), self::TABLES);
        $migration->up();

        self::assertSame([
            ['hasTable', self::TABLES[0]],
            ['show', self::TABLES[0]],
            ['hasTable', self::TABLES[1]],
            ['show', self::TABLES[1]],
            ['hasTable', self::TABLES[2]],
            ['show', self::TABLES[2]],
        ], $this->events);
        self::assertSame([], $this->createdSql);
    }

    #[DataProvider('incompatibleSchemaCases')]
    public function testIncompatibleSchemaIsStillRejected(string $table, string $from, string $to): void
    {
        $schemas = $this->schemas();
        $schemas[$table] = str_replace($from, $to, $schemas[$table], $replacements);
        self::assertSame(1, $replacements, 'The mutation must change exactly one captured declaration.');
        $visited = array_slice(self::TABLES, 0, array_search($table, self::TABLES, true) + 1);
        $migration = $this->migration($schemas, $visited);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INITIAL_SCHEMA_MISMATCH: ' . $table);
        $migration->up();
    }

    public static function incompatibleSchemaCases(): array
    {
        return [
            'missing foreign-key index' => [
                self::TABLES[0],
                "  KEY `fk_document_comment_revision` (`revision_id`),\n",
                '',
            ],
            'weaker range constraint' => [
                self::TABLES[0],
                'CHECK ((`end_offset` > `start_offset`))',
                'CHECK ((`end_offset` >= `start_offset`))',
            ],
            'changed composite index order' => [
                self::TABLES[0],
                'KEY `idx_document_comment_revision` (`tenant_id`,`document_id`,`revision_id`,`id`)',
                'KEY `idx_document_comment_revision` (`document_id`,`tenant_id`,`revision_id`,`id`)',
            ],
            'changed nullable body' => [
                self::TABLES[1],
                '  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,',
                '  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,',
            ],
            'changed status collation' => [
                self::TABLES[0],
                '  `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,',
                '  `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,',
            ],
        ];
    }

    public function testLaterMismatchPreventsCreatingEarlierMissingTable(): void
    {
        $schemas = $this->schemas();
        $schemas[self::TABLES[0]] = null;
        $schemas[self::TABLES[1]] = str_replace(
            '  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,',
            '  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,',
            $schemas[self::TABLES[1]],
            $replacements,
        );
        self::assertSame(1, $replacements);
        $migration = $this->migration($schemas, array_slice(self::TABLES, 0, 2));

        try {
            $migration->up();
            self::fail('The existing message table must be checked before creating the missing thread table.');
        } catch (RuntimeException $error) {
            self::assertSame('INITIAL_SCHEMA_MISMATCH: ' . self::TABLES[1], $error->getMessage());
        }

        self::assertSame([
            ['hasTable', self::TABLES[0]],
            ['hasTable', self::TABLES[1]],
            ['show', self::TABLES[1]],
        ], $this->events);
        self::assertSame([], $this->createdSql);
    }

    public function testAllMissingTablesAreCreatedInForeignKeyOrderAfterInspection(): void
    {
        $migration = $this->migration(array_fill_keys(self::TABLES, null), self::TABLES, self::TABLES);
        $migration->up();

        self::assertSame([
            ['hasTable', self::TABLES[0]],
            ['hasTable', self::TABLES[1]],
            ['hasTable', self::TABLES[2]],
            ['create', self::TABLES[0]],
            ['create', self::TABLES[1]],
            ['create', self::TABLES[2]],
        ], $this->events);
        self::assertSame(self::TABLES, array_keys($this->createdSql));
        self::assertStringContainsString('REFERENCES `pa_document` (`tenant_id`, `id`)', $this->createdSql[self::TABLES[0]]);
        self::assertStringContainsString('REFERENCES `pa_document_revision` (`id`)', $this->createdSql[self::TABLES[0]]);
        self::assertStringContainsString('REFERENCES `pa_document_comment_thread` (`tenant_id`, `id`)', $this->createdSql[self::TABLES[1]]);
        self::assertStringContainsString('REFERENCES `pa_document_comment_thread` (`tenant_id`, `id`)', $this->createdSql[self::TABLES[2]]);
        self::assertStringContainsString('REFERENCES `pa_document_comment_message` (`tenant_id`, `id`)', $this->createdSql[self::TABLES[2]]);
    }

    private function schemas(): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__ . '/fixtures/document-comment-show-create-mysql-8.4.10.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(self::TABLES, array_keys($fixture['tables']));
        foreach ($fixture['tables'] as $definition) {
            self::assertIsString($definition);
        }
        return $fixture['tables'];
    }

    private function migration(array $schemas, array $visited, array $creates = []): DocumentRevisionComments
    {
        // All I/O methods are PHPUnit doubles; PDO's constructor is never invoked.
        $adapter = $this->createMock(PdoAdapter::class);
        $pdo = $this->createMock(PDO::class);
        $adapter->expects(self::once())->method('getConnection')->willReturn($pdo);
        $visitIndex = 0;
        $adapter->expects(self::exactly(count($visited)))->method('hasTable')->willReturnCallback(
            function (string $table) use ($schemas, $visited, &$visitIndex): bool {
                self::assertSame($visited[$visitIndex++], $table);
                $this->events[] = ['hasTable', $table];
                return is_string($schemas[$table]);
            },
        );

        $queries = array_values(array_filter($visited, static fn(string $table): bool => is_string($schemas[$table])));
        $queryIndex = 0;
        $pdo->expects(self::exactly(count($queries)))->method('query')->willReturnCallback(
            function (string $query) use ($schemas, $queries, &$queryIndex): PDOStatement {
                $table = $queries[$queryIndex++];
                self::assertSame('SHOW CREATE TABLE `' . $table . '`', $query);
                $this->events[] = ['show', $table];
                $statement = $this->createMock(PDOStatement::class);
                $statement->expects(self::once())->method('fetch')->with(PDO::FETCH_NUM)->willReturn([$table, $schemas[$table]]);
                $statement->expects(self::once())->method('closeCursor')->willReturn(true);
                return $statement;
            },
        );

        $createIndex = 0;
        $adapter->expects(self::exactly(count($creates)))->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use ($creates, &$createIndex): int {
                $table = $creates[$createIndex++];
                self::assertStringStartsWith('CREATE TABLE `' . $table . "` (\n", $sql);
                self::assertSame([], $params);
                $this->events[] = ['create', $table];
                $this->createdSql[$table] = $sql;
                return 0;
            },
        );

        $migration = new DocumentRevisionComments('test', 20261107100007);
        $migration->setAdapter($adapter);
        return $migration;
    }
}
