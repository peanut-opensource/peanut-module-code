"""Bounded native ALTER provenance. Neither migrations nor SQL are executed."""
from __future__ import annotations

import argparse
import hashlib
import importlib.machinery
import importlib.util
from pathlib import Path
import tempfile
import unittest
import json

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_alter', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)
OPTIONS = None


class AlterCoverageTest(unittest.TestCase):
    def setUp(self):
        parent = ROOT / '.local/tmp/tpq-alter-coverage'
        parent.mkdir(parents=True, exist_ok=True)
        self.tmp = tempfile.TemporaryDirectory(dir=parent)
        self.root = Path(self.tmp.name)
        self.old = checker.ROOT
        checker.ROOT = self.root
        self.path = 'server/database/kernel-migrations/alter.php'
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/'}}}))
        self.write(self.path, self.program('ALTER TABLE `pa_event` ADD KEY `idx_ip` (`ip_address`, `occurred_at`)',
                                           'ALTER TABLE `pa_event` DROP INDEX `idx_ip`'))

    def tearDown(self):
        checker.ROOT = self.old
        self.tmp.cleanup()

    def write(self, path, text):
        dest = self.root / path
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(text, encoding='utf-8')
        return dest

    def program(self, up, down):
        return "<?php\nuse think\\migration\\Migrator;\nfinal class Change extends Migrator {\n" + \
            "public function up(): void { $this->execute(<<<'SQL'\n" + up + "\nSQL); }\n" + \
            "public function down(): void { $this->execute(<<<'SQL'\n" + down + "\nSQL); }\n}\n"

    def inventory(self):
        return checker.host_php_schema_inventory()

    def test_index_is_an_alter_not_an_extra_table(self):
        report = self.inventory()
        self.assertEqual({}, report['gaps'])
        self.assertEqual([], report['records'])
        rows = report['alterations']
        self.assertEqual(['up', 'down'], [row['direction'] for row in rows])
        self.assertEqual(['add_index', 'drop_index'], [row['effects'][0]['kind'] for row in rows])
        self.assertEqual(['ip_address', 'occurred_at'], rows[0]['effects'][0]['columns'])
        self.assertEqual('pa_event', rows[0]['table'])
        self.assertEqual(hashlib.sha256((self.root / self.path).read_bytes()).hexdigest(), rows[0]['migration_sha256'])

    def test_native_noarg_helper_is_traced_without_running_it(self):
        schema = "<?php\nnamespace Fixture;\nfinal class Schema {\npublic static function add(): string { return 'ALTER TABLE `pa_member` ADD CONSTRAINT `fk_dept` FOREIGN KEY (`tenant_id`, `department_id`) REFERENCES `pa_department` (`tenant_id`, `id`) ON DELETE RESTRICT'; }\npublic static function remove(): string { return 'ALTER TABLE `pa_member` DROP FOREIGN KEY `fk_dept`'; }\n}"
        self.write('server/app/modules/fixture/example/src/Schema.php', schema)
        self.write(self.path, '<?php\nuse think\\migration\\Migrator;\nuse Fixture\\Schema;\nfinal class Change extends Migrator { public function up(): void { $this->execute(Schema::add()); } public function down(): void { $this->execute(Schema::remove()); } }')
        report = self.inventory()
        self.assertEqual({}, report['gaps'])
        effect = report['alterations'][0]['effects'][0]
        self.assertEqual('add_foreign_key', effect['kind'])
        self.assertEqual('pa_department', effect['references_table'])
        self.assertEqual(['tenant_id', 'id'], effect['references_columns'])
        self.assertEqual('Fixture\\Schema', report['alterations'][0]['schema_class'])
        self.assertEqual('add', report['alterations'][0]['schema_method'])
        self.write('server/app/modules/fixture/example/src/Schema.php', schema.replace("return 'ALTER TABLE", "unsafe(); return 'ALTER TABLE", 1))
        self.assertIn(self.path, self.inventory()['gaps'])

    def test_missing_or_case_wrong_helper_source_is_not_covered(self):
        self.write(self.path, '<?php\nuse think\\migration\\Migrator;\nuse Fixture\\Missing;\nfinal class Change extends Migrator { public function up(): void { $this->execute(Missing::add()); } public function down(): void {} }')
        self.write('server/app/modules/fixture/example/src/missing.php', '<?php namespace Fixture; final class Missing { public static function add(): string { return "ALTER TABLE `pa_event` DROP INDEX `idx_ip`"; } }')
        self.assertIn(self.path, self.inventory()['gaps'])

    def test_check_literal_list_and_regex_are_preserved(self):
        sql = "ALTER TABLE `pa_operation` DROP CHECK `chk_kind`, ADD CONSTRAINT `chk_kind` CHECK (`kind` IN ('one', 'zero_or_one'))"
        self.write(self.path, self.program(sql, sql))
        report = self.inventory()
        self.assertEqual({}, report['gaps'])
        self.assertEqual(['drop_check', 'add_check'], [item['kind'] for item in report['alterations'][0]['effects']])
        regex = "ALTER TABLE `pa_operation` ADD CONSTRAINT `chk_client` CHECK (REGEXP_LIKE(`client_key`, '^[a-z][a-z0-9-]{0,63}$', 'c'))"
        self.write(self.path, self.program(regex, 'ALTER TABLE `pa_operation` DROP CHECK `chk_client`'))
        report = self.inventory()
        self.assertEqual({}, report['gaps'])
        self.assertIn("{0,63}", report['alterations'][0]['sql'])

    def test_native_rollback_check_equality_is_not_ignored(self):
        sql = "ALTER TABLE `pa_session` ADD CONSTRAINT `chk_client` CHECK (`client_key` = 'admin-web')"
        self.write(self.path, self.program(sql, sql))
        report = self.inventory()
        self.assertEqual({}, report['gaps'])
        self.assertEqual("`client_key` = 'admin-web'", report['alterations'][1]['effects'][0]['expression'])

    def test_every_truncated_foreign_key_reports_a_gap_not_an_exception(self):
        sql = 'ALTER TABLE `pa_member` ADD CONSTRAINT `fk` FOREIGN KEY (`tenant_id`, `department_id`) REFERENCES `pa_department` (`tenant_id`, `id`) ON DELETE RESTRICT'
        for length in range(1, len(sql)):
            with self.subTest(length=length):
                with self.assertRaises(ValueError):
                    checker._sql_alter_structure(sql[:length])

    def test_column_default_and_position_are_recorded(self):
        sql = "ALTER TABLE `pa_challenge` ADD COLUMN `client_key` VARCHAR(64) NOT NULL DEFAULT 'admin-web' AFTER `purpose`"
        self.write(self.path, self.program(sql, 'ALTER TABLE `pa_challenge` DROP COLUMN `client_key`'))
        rows = self.inventory()['alterations']
        self.assertEqual({'kind': 'add_column', 'column': 'client_key', 'type': 'VARCHAR', 'length': 64,
                          'nullable': False, 'default': 'admin-web', 'after': 'purpose'}, rows[0]['effects'][0])
        self.write(self.path, self.program('ALTER TABLE `pa_challenge` ALTER COLUMN `client_key` DROP DEFAULT',
                                          'ALTER TABLE `pa_challenge` DROP COLUMN `client_key`'))
        self.assertEqual('drop_default', self.inventory()['alterations'][0]['effects'][0]['kind'])

    def test_unknown_or_incomplete_statement_does_not_leave_partial_coverage(self):
        for sql in ['ALTER TABLE `pa_event` ADD KEY `idx` (`id`), UNKNOWN OPTION',
                    'ALTER TABLE `pa_event` ADD KEY `idx` (`id`',
                    'ALTER TABLE `pa_event` ADD KEY `idx` (`id`); DELETE FROM `pa_event`',
                    'ALTER TABLE `pa_event` /* hidden */ DROP INDEX `idx`',
                    'ALTER TABLE `pa_event` ADD CONSTRAINT `c` CHECK (untrusted(`id`))',
                    'ALTER TABLE `pa_event` ADD COLUMN `tenant_id` BIGINT',
                    'ALTER TABLE `pa_event` DROP COLUMN `tenant_id`']:
            with self.subTest(sql=sql):
                self.write(self.path, self.program(sql, 'ALTER TABLE `pa_event` DROP INDEX `idx`'))
                report = self.inventory()
                self.assertIn(self.path, report['gaps'])
                self.assertEqual([], report.get('alterations', []))

    def test_unknown_down_and_extra_up_statement_are_not_ignored(self):
        original = (self.root / self.path).read_text()
        for text in [original.replace('SQL); }', 'SQL); doSomethingElse(); }', 1),
                     original.replace('DROP INDEX `idx_ip`', 'DO UNKNOWN'),
                     original.replace('extends Migrator', 'extends Unrelated')]:
            self.write(self.path, text)
            report = self.inventory()
            self.assertIn(self.path, report['gaps'])
            self.assertEqual([], report.get('alterations', []))

    def test_nested_native_module_path_and_capitalized_nonmigration_are_distinct(self):
        source = (self.root / self.path).read_text()
        (self.root / self.path).unlink()
        native = 'server/app/modules/fixture/example/access/database/migrations/change.php'
        self.write(native, source)
        self.write('server/app/modules/fixture/example/src/Database/Migrations/Change.php', source)
        report = checker.module_php_schema_inventory()
        self.assertEqual({}, report['gaps'])
        self.assertEqual(1, report['migration_count'])
        self.assertEqual({native}, {row['migration'] for row in report['alterations']})


class ActualAlterCoverageTest(unittest.TestCase):
    def test_four_actual_migrations_have_six_forward_structural_statements(self):
        with checker.ownership_source_snapshot(OPTIONS.source_ref):
            report = checker.host_php_schema_inventory()
            self.assertEqual(35, report['migration_count'])
            self.assertEqual(31, len(report['records']))
            self.assertEqual({}, report['gaps'])
            rows = report['alterations']
            forward = [row for row in rows if row['direction'] == 'up']
            self.assertEqual(6, len(forward))
            self.assertEqual(5, len([row for row in rows if row['direction'] == 'down']))
            self.assertEqual(4, len({row['migration'] for row in rows}))
            self.assertEqual({'pa_tenant_member', 'pa_auth_security_event', 'pa_resource_operation',
                             'pa_login_challenge', 'pa_tenant_session'}, {row['table'] for row in forward})
            self.assertEqual(9, sum(len(row['effects']) for row in forward))
            self.assertTrue(all(row['sql_sha256'] == hashlib.sha256(row['sql'].encode()).hexdigest() for row in rows))
            self.assertEqual(89, len(checker.schema_tenant_tables()))
            self.assertEqual(6, checker.module_php_schema_inventory()['migration_count'])


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--source-ref', required=True)
    OPTIONS, rest = parser.parse_known_args()
    unittest.main(argv=[__file__, *rest])
