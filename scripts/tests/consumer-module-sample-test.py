#!/usr/bin/env python3
"""Run the generated reference-chain PHP sample against native ThinkPHP SQLite fixtures."""
from __future__ import annotations

import importlib.machinery
import importlib.util
import json
import shutil
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader(
    "consumer_module_reference_chain", str(ROOT / "scripts/consumer-module-reference-chain")
)
spec = importlib.util.spec_from_loader(loader.name, loader)
module = importlib.util.module_from_spec(spec)
loader.exec_module(module)


class ConsumerModuleSampleTest(unittest.TestCase):
    def setUp(self):
        owned = ROOT / ".local/tmp/m3-reference-implementation-20260928"
        owned.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=owned)
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.author = self.root / "author"
        self.backend = self.author / "server/app/modules/acme/reference_chain"
        self.frontend = self.author / "web/src/modules/acme-reference-chain"
        self.backend.mkdir(parents=True)
        self.frontend.mkdir(parents=True)
        (self.backend / "database/migrations").mkdir(parents=True)
        (self.backend / "resources").mkdir()
        identity_target = self.author / "server/app/modules/official/identity/module.json"
        identity_target.parent.mkdir(parents=True)
        shutil.copy2(ROOT / "server/app/modules/official/identity/module.json", identity_target)
        (self.backend / "module.json").write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "key": "acme.reference-chain",
                    "version": "1.0.0",
                    "dependencies": [],
                    "backend": {"provider": "Acme\\Modules\\ReferenceChain\\ModuleProvider"},
                    "database": {"owned_tables": []},
                    "contracts": {"exports": []},
                    "lifecycle": {"requires": []},
                }
            ),
            encoding="utf-8",
        )
        (self.backend / "composer.json").write_text('{"version":"1.0.0"}', encoding="utf-8")
        (self.frontend / "package.json").write_text('{"version":"1.0.0"}', encoding="utf-8")
        module.author_module_v1(self.author)
        self.identity_version = json.loads(identity_target.read_text(encoding="utf-8"))["version"]

    def run_harness(self) -> dict:
        harness = self.root / "sample-harness.php"
        harness.write_text(
            textwrap.dedent(
                f"""\
                <?php
                declare(strict_types=1);

                require {json.dumps(str(ROOT / "server/vendor/autoload.php"))};
                if (!class_exists('ThinkPhpTestConnection', false)) {{
                    require {json.dumps(str(ROOT / "server/tests/Support/ThinkPhpTestConnection.php"))};
                }}
                require {json.dumps(str(self.backend / "src/Contract/ReferenceChainCommands.php"))};
                require {json.dumps(str(self.backend / "src/Service/ReferenceChainService.php"))};

                use app\\common\\contract\\authorization\\AdminAuthorizationQuery;
                use app\\common\\contract\\module\\ModuleQualification;
                use app\\common\\contract\\module\\ModuleQualificationQuery;
                use app\\common\\dto\\authorization\\AdminAccessData;
                use app\\common\\dto\\authorization\\AdminPrincipal;
                use app\\common\\dto\\authorization\\PermissionDecision;
                use PeanutAdmin\\Kernel\\Auth\\TenantContext;
                use PeanutAdmin\\Kernel\\Auth\\ValidatedTenantSession;
                use think\\Container;
                use think\\DbManager;
                use think\\db\\builder\\Sqlite as SqliteBuilder;
                use think\\db\\connector\\Sqlite;
                use think\\facade\\Db;

                final class ReferenceChainAuthorizationFixture implements AdminAuthorizationQuery
                {{
                    public bool $allowed = true;

                    public function principal(TenantContext $tenantContext): AdminPrincipal
                    {{
                        return AdminPrincipal::fromArray([
                            'id' => $tenantContext->memberId,
                            'tenant_id' => $tenantContext->tenantId,
                            'account_id' => $tenantContext->accountId,
                            'tenant_name' => 'fixture',
                            'username' => 'fixture@example.test',
                            'nickname' => 'Fixture',
                            'name' => 'Fixture',
                            'root' => 0,
                            'authorization_revision' => $tenantContext->authorizationRevision,
                        ]);
                    }}

                    public function accessData(TenantContext $tenantContext, AdminPrincipal $admin): AdminAccessData
                    {{
                        return new AdminAccessData([], []);
                    }}

                    public function decide(?TenantContext $tenantContext, AdminPrincipal $admin, string $accessUri): PermissionDecision
                    {{
                        return $this->allowed ? PermissionDecision::allow($accessUri) : PermissionDecision::deny($accessUri, 'fixture');
                    }}

                    public function assignableMenuRecords(TenantContext $tenantContext): array
                    {{
                        return [];
                    }}
                }}

                final class ReferenceChainModuleFixture implements ModuleQualificationQuery
                {{
                    /** @var array<int,list<string>> */
                    public array $active = [];

                    public function installedModule(string $moduleKey): ModuleQualification
                    {{
                        return new ModuleQualification($moduleKey, 'fixture', '1.0.0', 1, str_repeat('a', 64), []);
                    }}

                    public function installedModules(): array
                    {{
                        return [$this->installedModule('acme.reference-chain')];
                    }}

                    public function tenantModuleStates(int $tenantId): array
                    {{
                        return [];
                    }}

                    public function activeTenantModuleKeys(int $tenantId): array
                    {{
                        return $this->active[$tenantId] ?? [];
                    }}
                }}

                final class ReferenceChainStringIdConnection extends Sqlite
                {{
                    public ?string $nextInsertId = null;

                    public function __construct(private readonly PDO $fixture)
                    {{
                        parent::__construct(['type' => 'sqlite', 'builder' => SqliteBuilder::class, 'prefix' => 'pa_']);
                    }}

                    protected function createPdo($dsn, $username, $password, $params): PDO
                    {{
                        return $this->fixture;
                    }}

                    public function getLastInsID(\\think\\db\\BaseQuery $query, ?string $sequence = null)
                    {{
                        if ($this->nextInsertId !== null) {{
                            $id = $this->nextInsertId;
                            $this->nextInsertId = null;
                            return $id;
                        }}
                        $id = parent::getLastInsID($query, $sequence);
                        return $query->getTable() === 'pa_acme_reference_chain_record' ? (string)$id : $id;
                    }}
                }}

                function fixtureTenantContext(int $tenantId): TenantContext
                {{
                    // Synthetic validated session for this SQLite fixture only; it is not a login proof.
                    return TenantContext::fromValidatedSession(
                        new ValidatedTenantSession(
                            $tenantId,
                            'fixture-session-' . $tenantId,
                            $tenantId,
                            1000 + $tenantId,
                            2000 + $tenantId,
                            'admin-web',
                            new DateTimeImmutable('2026-09-28T00:00:00+00:00'),
                            1,
                        ),
                        'fixture-request-' . $tenantId,
                    );
                }}

                $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2026-09-28 00:00:00.000', 1);
                $connection = new ReferenceChainStringIdConnection($pdo);
                $manager = new SharedPdoDbManager($connection);
                $connection->setDb($manager);
                Container::getInstance()->instance(DbManager::class, $manager);
                $pdo->exec('CREATE TABLE pa_acme_reference_chain_record (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, reference TEXT NOT NULL, created_at TEXT NOT NULL)');

                $auth = new ReferenceChainAuthorizationFixture();
                $modules = new ReferenceChainModuleFixture();
                $service = new Acme\\Modules\\ReferenceChain\\Service\\ReferenceChainService($auth, $modules);
                $alpha = fixtureTenantContext(1);
                $beta = fixtureTenantContext(2);
                $modules->active = [1 => ['acme.reference-chain'], 2 => ['acme.reference-chain']];

                $results = [];
                $results['alpha_first'] = $service->create($alpha, 'alpha-1');
                $service->create($alpha, 'alpha-2');
                $service->create($alpha, 'alpha-3');
                $results['beta_first'] = $service->create($beta, 'beta-1');
                $results['alpha_page_one'] = $service->list($alpha, 2);
                $cursor = $results['alpha_page_one']['next_cursor'];
                $results['alpha_page_two'] = $service->list($alpha, 2, $cursor);
                $results['alpha_all'] = $service->list($alpha, 100);
                $results['beta_all'] = $service->list($beta, 100);
                $results['page_keys'] = array_keys($results['alpha_page_one']);
                $results['item_keys'] = array_keys($results['alpha_page_one']['items'][0]);
                $results['id_types'] = array_map('gettype', [
                    $results['alpha_first']['id'],
                    $results['alpha_page_one']['items'][0]['id'],
                    $results['alpha_page_one']['next_cursor'],
                ]);

                $modules->active = [2 => ['acme.reference-chain']];
                $before = (int)$pdo->query('SELECT COUNT(*) FROM pa_acme_reference_chain_record')->fetchColumn();
                try {{
                    $service->create($alpha, 'not-enabled-write');
                    throw new RuntimeException('missing module rejection');
                }} catch (RuntimeException $exception) {{
                    $results['missing_module'] = $exception->getMessage();
                }}
                $results['zero_write_on_not_enabled'] = $before === (int)$pdo->query('SELECT COUNT(*) FROM pa_acme_reference_chain_record')->fetchColumn();

                $modules->active = [1 => ['acme.reference-chain'], 2 => ['acme.reference-chain']];
                $auth->allowed = false;
                $before = (int)$pdo->query('SELECT COUNT(*) FROM pa_acme_reference_chain_record')->fetchColumn();
                try {{
                    $service->create($beta, 'denied-write');
                    throw new RuntimeException('missing permission rejection');
                }} catch (RuntimeException $exception) {{
                    $results['permission_denied'] = $exception->getMessage();
                }}
                $results['zero_write_on_denied'] = $before === (int)$pdo->query('SELECT COUNT(*) FROM pa_acme_reference_chain_record')->fetchColumn();

                $auth->allowed = true;
                foreach ([[0, 0], [101, 0], [50, -1]] as [$limit, $afterId]) {{
                    try {{
                        $service->list($alpha, $limit, $afterId);
                        throw new RuntimeException('missing boundary rejection');
                    }} catch (RuntimeException $exception) {{
                        $results['boundary_' . $limit . '_' . $afterId] = $exception->getMessage();
                    }}
                }}

                $connection->nextInsertId = '9223372036854775808';
                $before = (int)$pdo->query('SELECT COUNT(*) FROM pa_acme_reference_chain_record')->fetchColumn();
                try {{
                    $service->create($alpha, 'overflow-id');
                    throw new RuntimeException('missing id rejection');
                }} catch (RuntimeException $exception) {{
                    $results['overflow_id'] = $exception->getMessage();
                }}
                $results['zero_write_on_overflow'] = $before === (int)$pdo->query('SELECT COUNT(*) FROM pa_acme_reference_chain_record')->fetchColumn();

                echo json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
                """
            ),
            encoding="utf-8",
        )
        result = subprocess.run(["php", str(harness)], cwd=ROOT, capture_output=True, text=True)
        if result.returncode != 0:
            self.fail(f"sample harness failed ({result.returncode})\nSTDOUT:\n{result.stdout}\nSTDERR:\n{result.stderr}")
        return json.loads(result.stdout)

    def test_generated_php_service_enforces_public_contracts(self):
        manifest = json.loads((self.backend / "module.json").read_text(encoding="utf-8"))
        self.assertIn(
            {"module_key": "official.identity", "version": self.identity_version},
            manifest["dependencies"],
        )
        service_source = (self.backend / "src/Service/ReferenceChainService.php").read_text(encoding="utf-8")
        self.assertIn("ModuleQualificationQuery", service_source)
        self.assertNotIn("Db::name('tenant_module')", service_source)
        self.assertIn("'items' => $items", service_source)
        self.assertIn("'has_more' => count($rows) > $limit", service_source)
        self.assertIn("'next_cursor'", service_source)
        self.assertIn("$this->intId(Db::name('acme_reference_chain_record')->insertGetId", service_source)

        output = self.run_harness()
        self.assertEqual(output["page_keys"], ["items", "has_more", "next_cursor"])
        self.assertEqual(output["item_keys"], ["id", "tenant_id", "reference"])
        self.assertEqual(output["id_types"], ["integer", "integer", "integer"])
        self.assertEqual(output["alpha_first"]["tenant_id"], 1)
        self.assertEqual(output["beta_first"]["tenant_id"], 2)
        self.assertEqual([row["reference"] for row in output["alpha_page_one"]["items"]], ["alpha-1", "alpha-2"])
        self.assertTrue(output["alpha_page_one"]["has_more"])
        self.assertEqual([row["reference"] for row in output["alpha_page_two"]["items"]], ["alpha-3"])
        self.assertFalse(output["alpha_page_two"]["has_more"])
        self.assertIsNone(output["alpha_page_two"]["next_cursor"])
        self.assertEqual([row["tenant_id"] for row in output["alpha_all"]["items"]], [1, 1, 1])
        self.assertEqual([row["tenant_id"] for row in output["beta_all"]["items"]], [2])
        self.assertEqual(output["missing_module"], "REFERENCE_CHAIN_MODULE_NOT_ENABLED")
        self.assertTrue(output["zero_write_on_not_enabled"])
        self.assertEqual(output["permission_denied"], "REFERENCE_CHAIN_PERMISSION_DENIED")
        self.assertTrue(output["zero_write_on_denied"])
        self.assertEqual(output["boundary_0_0"], "REFERENCE_CHAIN_LIST_LIMIT_INVALID")
        self.assertEqual(output["boundary_101_0"], "REFERENCE_CHAIN_LIST_LIMIT_INVALID")
        self.assertEqual(output["boundary_50_-1"], "REFERENCE_CHAIN_CURSOR_INVALID")
        self.assertEqual(output["overflow_id"], "REFERENCE_CHAIN_ID_OUT_OF_RANGE")
        self.assertTrue(output["zero_write_on_overflow"])

    def test_generated_migrations_keep_v1_create_and_v2_alter(self):
        create_migration = self.backend / "database/migrations/20260828010101_create_reference_chain.sql"
        create_sql = create_migration.read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE `pa_acme_reference_chain_record`", create_sql)
        self.assertIn("FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`)", create_sql)

        module.author_module_v2(self.backend, self.frontend)
        alter_migration = self.backend / "database/migrations/20260828010201_add_revision_note.sql"
        self.assertIn(
            "ALTER TABLE `pa_acme_reference_chain_record` ADD COLUMN `revision_note`",
            alter_migration.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(ConsumerModuleSampleTest)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if not result.wasSuccessful():
        raise SystemExit(1)
    print(f"CONSUMER-MODULE-SAMPLE-001 passed ({result.testsRun} cases)")
