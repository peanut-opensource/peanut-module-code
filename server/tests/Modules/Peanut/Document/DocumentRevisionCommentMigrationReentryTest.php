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
        $migration = $this->migration(array_fill_keys(self::TABLES, ''), self::TABLES);
        $migration->up();

        self::assertSame([
            ['hasTable', self::TABLES[0]],
            ['hasTable', self::TABLES[1]],
            ['hasTable', self::TABLES[2]],
        ], $this->events);
        self::assertSame([], $this->createdSql);
    }

    public function testAllMissingTablesAreCreatedInForeignKeyOrder(): void
    {
        $migration = $this->migration(array_fill_keys(self::TABLES, null), self::TABLES, self::TABLES);
        $migration->up();

        self::assertSame([
            ['hasTable', self::TABLES[0]],
            ['create', self::TABLES[0]],
            ['hasTable', self::TABLES[1]],
            ['create', self::TABLES[1]],
            ['hasTable', self::TABLES[2]],
            ['create', self::TABLES[2]],
        ], $this->events);
        self::assertSame(self::TABLES, array_keys($this->createdSql));
        self::assertStringContainsString('REFERENCES `pa_document` (`tenant_id`, `id`)', $this->createdSql[self::TABLES[0]]);
        self::assertStringContainsString('REFERENCES `pa_document_revision` (`id`)', $this->createdSql[self::TABLES[0]]);
        self::assertStringContainsString('REFERENCES `pa_document_comment_thread` (`tenant_id`, `id`)', $this->createdSql[self::TABLES[1]]);
        self::assertStringContainsString('REFERENCES `pa_document_comment_thread` (`tenant_id`, `id`)', $this->createdSql[self::TABLES[2]]);
        self::assertStringContainsString('REFERENCES `pa_document_comment_message` (`tenant_id`, `id`)', $this->createdSql[self::TABLES[2]]);
    }

    private function migration(array $schemas, array $visited, array $creates = []): DocumentRevisionComments
    {
        $adapter = $this->createMock(PdoAdapter::class);
        $visitIndex = 0;
        $adapter->expects(self::exactly(count($visited)))->method('hasTable')->willReturnCallback(
            function (string $table) use ($schemas, $visited, &$visitIndex): bool {
                self::assertSame($visited[$visitIndex++], $table);
                $this->events[] = ['hasTable', $table];
                return is_string($schemas[$table]);
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
