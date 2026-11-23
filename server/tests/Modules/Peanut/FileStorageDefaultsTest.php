<?php

declare(strict_types=1);

use Phinx\Db\Adapter\SQLiteAdapter;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/modules/peanut/file/database/seeds/PeanutFileDefaults.php';

final class FileStorageDefaultsTest extends TestCase
{
    private PDO $db;
    private PeanutFileDefaults $seed;
    private string $time = '2026-11-23 10:00:00.000';

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->sqliteCreateFunction('UTC_TIMESTAMP', fn(int $precision): string => $this->time, 1);
        $this->db->exec(<<<'SQL'
            PRAGMA foreign_keys = ON;
            CREATE TABLE pa_storage_account (
                id INTEGER PRIMARY KEY, account_key TEXT NOT NULL UNIQUE, driver TEXT NOT NULL, name TEXT NOT NULL,
                status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );
            CREATE TABLE pa_storage_space (
                id INTEGER PRIMARY KEY, space_key TEXT NOT NULL UNIQUE, account_id INTEGER NOT NULL REFERENCES pa_storage_account(id),
                name TEXT NOT NULL, access_type TEXT NOT NULL, local_path TEXT, status TEXT NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                CONSTRAINT chk_storage_space_local_path CHECK (local_path IS NULL OR local_path IN ('storage/public', 'storage/private'))
            );
            CREATE TABLE pa_storage_route (
                route_key TEXT PRIMARY KEY, access_type TEXT NOT NULL,
                space_id INTEGER NOT NULL REFERENCES pa_storage_space(id), updated_at TEXT NOT NULL
            );
            SQL);
        $this->seed = new PeanutFileDefaults();
        $this->seed->setAdapter(new SQLiteAdapter(['name' => ':memory:', 'connection' => $this->db]));
    }

    public function testFirstActivationCreatesBothCanonicalSpacesAndRetryLeavesRowsUnchanged(): void
    {
        $this->seed->run();

        self::assertSame([
            ['route_key' => 'default.private', 'account_key' => 'local', 'access_type' => 'private', 'local_path' => 'storage/private'],
            ['route_key' => 'default.public', 'account_key' => 'local', 'access_type' => 'public', 'local_path' => 'storage/public'],
        ], $this->db->query(<<<'SQL'
            SELECT r.route_key, a.account_key, s.access_type, s.local_path
            FROM pa_storage_route r
            JOIN pa_storage_space s ON s.id = r.space_id AND s.access_type = r.access_type
            JOIN pa_storage_account a ON a.id = s.account_id
            ORDER BY r.route_key
            SQL)->fetchAll(PDO::FETCH_ASSOC));
        $before = $this->snapshot();
        self::assertSame([1, 2, 2], array_map('count', array_values($before)));

        $this->time = '2026-11-23 11:00:00.000';
        $this->seed->run();
        self::assertSame($before, $this->snapshot());
    }

    public function testResumeAddsMissingDefaultsWithoutReplacingReviewedAccountSpaceOrRoute(): void
    {
        $this->db->exec(<<<'SQL'
            INSERT INTO pa_storage_account VALUES (12, 'local', 'local', 'Reviewed local account', 'disabled', 'original-create', 'original-update');
            INSERT INTO pa_storage_space VALUES
                (31, 'local-private', 12, 'Reviewed private space', 'private', 'storage/private', 'read_only', 'original-create', 'original-update'),
                (32, 'custom-public', 12, 'Reviewed routing target', 'public', 'storage/public', 'active', 'original-create', 'original-update');
            INSERT INTO pa_storage_route VALUES ('default.public', 'public', 32, 'original-update');
            SQL);
        $before = $this->snapshot();

        $this->seed->run();

        $after = $this->snapshot();
        self::assertSame([1, 3, 2], array_map('count', array_values($after)));
        foreach ($before as $table => $rows) {
            self::assertSame($rows, array_intersect_key($after[$table], $rows));
        }
        self::assertSame('storage/public', $after['space']['local-public']['local_path']);
        self::assertSame(31, $after['route']['default.private']['space_id']);
    }

    /** Preserve complete rows, including IDs, status and timestamps, across a second call. */
    private function snapshot(): array
    {
        $result = [];
        foreach (['account' => 'account_key', 'space' => 'space_key', 'route' => 'route_key'] as $table => $key) {
            $rows = $this->db->query("SELECT * FROM pa_storage_{$table} ORDER BY {$key}")->fetchAll(PDO::FETCH_ASSOC);
            $result[$table] = array_column($rows, null, $key);
        }
        return $result;
    }
}
