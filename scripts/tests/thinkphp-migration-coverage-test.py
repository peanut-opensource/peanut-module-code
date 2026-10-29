"""Native PHP AST schema coverage, never migration execution or historical reads.

Requires the existing quality PhpParser install. Select non-default installations
with TPQ_PHP_BINARY and TPQ_PHP_PARSER_AUTOLOAD. No tests are silently skipped.
"""
from __future__ import annotations

import argparse
from contextlib import ExitStack
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_migration', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)
OPTIONS = None


class MigrationCoverageTest(unittest.TestCase):
    def setUp(self):
        parent = ROOT / '.local/tmp/tpq-migration-coverage'
        parent.mkdir(parents=True, exist_ok=True)
        self.tmp = tempfile.TemporaryDirectory(dir=parent)
        self.root = Path(self.tmp.name)
        self.old = checker.ROOT
        checker.ROOT = self.root
        self.migration = 'server/app/modules/fixture/example/access/database/migrations/create.php'
        self.schema = 'server/app/modules/fixture/example/src/Schema.php'
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/'}}}))
        self.write('server/database/init.sql', '')
        self.write(self.schema, self.map_schema())
        self.write(self.migration, self.literal_migration())

    def tearDown(self):
        checker.ROOT = self.old
        self.tmp.cleanup()

    def write(self, name, value):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(value, encoding='utf-8')
        return path

    def map_schema(self):
        return '''<?php
namespace Fixture;
use InvalidArgumentException;
final class Schema {
 private const CREATE_SQL = [
 'pa_one' => <<<'SQL'
CREATE TABLE `pa_one` (`id` INT, `tenant_id` BIGINT) ENGINE=InnoDB
SQL,
 'pa_two' => <<<'SQL'
CREATE TABLE `pa_two` (`id` INT) ENGINE=InnoDB
SQL,
 ];
 private function __construct() {}
 public static function tableNames(): array { return array_keys(self::CREATE_SQL); }
 public static function createSql(string $table): string {
   return self::CREATE_SQL[$table] ?? throw new InvalidArgumentException("Unknown: {$table}");
 }
 public static function dropSql(string $table): string { return "DROP TABLE `{$table}`"; }
}
'''

    def match_schema(self):
        return '''<?php
namespace Fixture;
use InvalidArgumentException;
final class Schema {
 public static function tableNames(): array { return ['pa_one', 'pa_two']; }
 public static function createSql(string $table): string {
   return match ($table) {
     'pa_one' => <<<'SQL'
CREATE TABLE `pa_one` (`id` INT, `tenant_id` BIGINT) ENGINE=InnoDB
SQL,
     'pa_two' => <<<'SQL'
CREATE TABLE `pa_two` (`id` INT) ENGINE=InnoDB
SQL,
     default => throw new InvalidArgumentException('Unknown table'),
   };
 }
}
'''

    def literal_migration(self):
        return '''<?php
declare(strict_types=1);
use Fixture\\Schema as NativeSchema;
use think\\migration\\Migrator;
final class CreateOne extends Migrator {
 public function up(): void { $this->execute(NativeSchema::createSql('pa_one')); }
 public function down(): void { $this->execute(NativeSchema::dropSql('pa_one')); }
}
'''

    def adoption_migration(self):
        return '''<?php
use Fixture\\Schema;
use think\\migration\\Migrator;
use think\\facade\\Db;
final class Adopt extends Migrator {
 public function up(): void {
  foreach (Schema::tableNames() as $table) {
   if (!$this->hasTable($table)) { Db::execute(Schema::createSql($table)); }
  }
 }
 public function down(): void { /* intentionally forward-only */ }
}
'''

    def inventory(self):
        return checker.module_php_schema_inventory()

    def assert_gap(self):
        result = self.inventory()
        self.assertIn(self.migration, result['gaps'])
        self.assertEqual([], result['records'])
        self.assertTrue(result['gaps'][self.migration])
        self.assertEqual([self.migration], checker.schema_coverage_gaps())
        self.assertEqual(set(), checker.schema_tenant_tables())
        return result

    def test_nested_literal_schema_reference_is_resolved_and_traced(self):
        result = self.inventory()
        self.assertEqual({}, result['gaps'])
        self.assertEqual(1, result['migration_count'])
        row = result['records'][0]
        self.assertEqual(self.migration, row['migration'])
        self.assertEqual(self.schema, row['schema_path'])
        self.assertEqual('Fixture\\Schema', row['schema_class'])
        self.assertEqual('pa_one', row['table'])
        self.assertGreater(row['migration_line'], 0)
        self.assertGreater(row['schema_line'], 0)
        self.assertEqual({'pa_one'}, checker.schema_tenant_tables())

    def test_native_adoption_loop_resolves_all_declarations_not_just_tenant_tables(self):
        self.write(self.schema, self.match_schema())
        self.write(self.migration, self.adoption_migration())
        result = self.inventory()
        self.assertEqual({}, result['gaps'])
        self.assertEqual({'pa_one', 'pa_two'}, {row['table'] for row in result['records']})
        self.assertEqual({'pa_one'}, checker.schema_tenant_tables())

    def test_array_keys_schema_is_supported_in_adoption(self):
        self.write(self.migration, self.adoption_migration())
        self.assertEqual({}, self.inventory()['gaps'])
        self.assertEqual(2, len(self.inventory()['records']))

    def test_nonmigration_capitalized_type_directory_is_not_a_migration(self):
        (self.root / self.migration).unlink()
        self.write('server/app/modules/fixture/example/src/Database/Migrations/OwnedMigration.php', self.literal_migration())
        self.assertEqual(0, self.inventory()['migration_count'])
        self.assertEqual({}, self.inventory()['gaps'])

    def test_schema_class_case_must_match_native_composer_path(self):
        self.write(self.migration, self.literal_migration().replace('Fixture\\Schema', 'Fixture\\schema'))
        self.assert_gap()

    def test_missing_schema_stays_a_coverage_gap(self):
        (self.root / self.schema).unlink()
        self.assert_gap()

    def test_wrong_declared_schema_class_is_not_accepted(self):
        self.write(self.schema, self.map_schema().replace('class Schema', 'class Different'))
        self.assert_gap()

    def test_unresolved_literal_table_is_not_guessed(self):
        self.write(self.migration, self.literal_migration().replace("'pa_one'", "'pa_missing'"))
        self.assert_gap()

    def test_variable_reference_outside_supported_loop_is_unresolved(self):
        self.write(self.migration, self.literal_migration().replace("createSql('pa_one')", 'createSql($table)'))
        self.assert_gap()

    def test_extra_dynamic_statement_invalidates_the_whole_up_body(self):
        self.write(self.migration, self.literal_migration().replace("createSql('pa_one'));", "createSql('pa_one')); dynamicSchemaChange();"))
        self.assert_gap()

    def test_top_level_code_is_never_executed_or_ignored(self):
        self.write(self.migration, self.literal_migration() + "throw new \\RuntimeException('never execute');")
        self.assert_gap()

    def test_schema_dynamic_dispatch_is_not_evaluated(self):
        self.write(self.schema, self.map_schema().replace('return self::CREATE_SQL[$table] ??', 'return otherSchema($table) ??'))
        self.assert_gap()

    def test_additional_schema_method_statement_is_not_ignored(self):
        self.write(self.schema, self.map_schema().replace('return self::CREATE_SQL', 'sideEffect(); return self::CREATE_SQL'))
        self.assert_gap()

    def test_table_list_and_match_arms_must_agree(self):
        self.write(self.migration, self.adoption_migration())
        self.write(self.schema, self.match_schema().replace("return ['pa_one', 'pa_two'];", "return ['pa_one', 'pa_missing'];"))
        self.assert_gap()

    def test_duplicate_schema_keys_are_rejected(self):
        self.write(self.schema, self.map_schema().replace("'pa_two' =>", "'pa_one' =>"))
        self.assert_gap()

    def test_incomplete_php_declaration_is_not_a_partial_success(self):
        self.write(self.schema, self.map_schema()[:-3])
        self.assert_gap()

    def test_incomplete_sql_is_not_counted_as_a_table(self):
        self.write(self.schema, self.map_schema().replace('CREATE TABLE `pa_one` (`id` INT, `tenant_id` BIGINT) ENGINE=InnoDB', 'CREATE TABLE `pa_one` (`tenant_id` BIGINT'))
        self.assert_gap()

    def test_schema_table_name_must_match_the_selected_key(self):
        self.write(self.schema, self.map_schema().replace('CREATE TABLE `pa_one`', 'CREATE TABLE `pa_unrelated`'))
        self.assert_gap()

    def test_extra_dynamic_loop_branch_is_not_ignored(self):
        self.write(self.migration, self.adoption_migration().replace('if (!$this->hasTable($table))', 'if (runtimeFlag() && !$this->hasTable($table))'))
        self.assert_gap()

    def test_source_text_in_comment_does_not_become_a_migration(self):
        self.write(self.migration, '<?php\n/* ' + self.literal_migration()[5:] + ' */\n')
        self.assert_gap()

    def test_absent_native_parser_is_incomplete_not_zero_tables(self):
        with patch.dict(os.environ, {'TPQ_PHP_PARSER_AUTOLOAD': str(self.root / 'missing/autoload.php')}):
            self.assert_gap()

    def test_unknown_structure_change_remains_a_gap(self):
        self.write(self.migration, self.literal_migration().replace("$this->execute(NativeSchema::createSql('pa_one'));", "$this->table('pa_one')->addColumn('tenant_id', 'integer')->update();"))
        self.assert_gap()


class HostMigrationCoverageTest(MigrationCoverageTest):
    # Host cases share the same source-only fixture, not a second PHP parser.
    def setUp(self):
        super().setUp()
        (self.root / self.migration).unlink()
        self.migration = 'server/database/kernel-migrations/create.php'
        self.write(self.migration, self.literal_migration())

    def inventory(self):
        return checker.host_php_schema_inventory()

    def test_host_class_constant_reference_is_exactly_resolved(self):
        text = self.literal_migration().replace('extends Migrator {', "extends Migrator { protected const TABLE = 'pa_one';")
        text = text.replace("createSql('pa_one')", 'createSql(self::TABLE)').replace("dropSql('pa_one')", 'dropSql(self::TABLE)')
        self.write(self.migration, text)
        self.assertEqual({}, self.inventory()['gaps'])
        self.assertEqual(['pa_one'], [row['table'] for row in self.inventory()['records']])

    def test_host_dynamic_constant_cannot_be_evaluated(self):
        text = self.literal_migration().replace('extends Migrator {', "extends Migrator { protected const TABLE = OTHER_TABLE;")
        self.write(self.migration, text.replace("createSql('pa_one')", 'createSql(self::TABLE)'))
        self.assert_gap()

    def test_host_alter_program_remains_an_explicit_gap(self):
        self.write(self.migration, self.literal_migration().replace("NativeSchema::createSql('pa_one')", '"ALTER TABLE `pa_one` ADD COLUMN `tenant_id` BIGINT"'))
        self.assert_gap()

    def test_native_schema_map_guard_return_shape_is_recognized(self):
        text = self.map_schema().replace('return self::CREATE_SQL[$table] ?? throw new InvalidArgumentException("Unknown: {$table}");', '$sql = self::CREATE_SQL[$table] ?? null; if ($sql === null) { throw new InvalidArgumentException("Unknown: {$table}"); } return $sql;')
        self.write(self.schema, text)
        self.assertEqual({}, self.inventory()['gaps'])

    def test_guarded_map_wrong_return_or_extra_statement_is_incomplete(self):
        source = self.map_schema().replace('return self::CREATE_SQL[$table] ?? throw new InvalidArgumentException("Unknown: {$table}");', '$sql = self::CREATE_SQL[$table] ?? null; if ($sql === null) { throw new InvalidArgumentException("Unknown: {$table}"); } return $other;')
        self.write(self.schema, source)
        self.assert_gap()
        self.write(self.schema, source.replace('return $other;', 'dynamicChange(); return $sql;'))
        self.assert_gap()

    def test_host_canonical_schema_is_not_required_or_guessed_from_vendor(self):
        (self.root / self.schema).unlink()
        self.schema = 'server/database/schema/Schema.php'
        self.write(self.schema, self.map_schema())
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {}}}))
        self.assertEqual({}, self.inventory()['gaps'])
        self.assertEqual(self.schema, self.inventory()['records'][0]['schema_path'])

    def test_host_canonical_filename_case_is_exact(self):
        (self.root / self.schema).unlink()
        self.write('server/database/schema/schema.php', self.map_schema())
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {}}}))
        self.assert_gap()

    def test_application_migration_root_is_also_inspected(self):
        (self.root / self.migration).unlink()
        self.migration = 'server/database/migrations/create.php'
        self.write(self.migration, self.literal_migration())
        self.assertEqual({}, self.inventory()['gaps'])
        self.assertEqual(1, self.inventory()['migration_count'])


class ActualMigrationCoverageTest(unittest.TestCase):
    def test_selected_source_resolves_the_six_native_migrations(self):
        with checker.ownership_source_snapshot(OPTIONS.source_ref):
            result = checker.module_php_schema_inventory()
            self.assertEqual({}, result['gaps'])
            self.assertEqual(6, result['migration_count'])
            self.assertEqual(10, len(result['records']))
            expected = {'pa_data_permission_' + name for name in ('policy', 'group', 'target_set', 'condition', 'target')}
            expected |= {'pa_integration_' + name for name in ('machine_identity', 'webhook_endpoint', 'webhook_delivery', 'webhook_attempt', 'security_event')}
            self.assertEqual(expected, {row['table'] for row in result['records']})
            self.assertTrue(all(row['tenant_column'] for row in result['records']))
            self.assertTrue(all(row['schema_sha256'] and row['migration_sha256'] for row in result['records']))


class ActualHostMigrationCoverageTest(unittest.TestCase):
    def test_host_sources_are_not_silently_omitted_from_the_module_count(self):
        with checker.ownership_source_snapshot(OPTIONS.source_ref):
            host = checker.host_php_schema_inventory()
            self.assertEqual(35, host['migration_count'])
            self.assertEqual(31, len(host['records']))
            expected = {
                '20260716010111_add_pa_tenant_member_department_foreign_key.php',
                '20260716010403_add_auth_event_ip_index.php',
                '20260716010404_add_zero_or_one_cardinality.php',
                '20260718010101_generalize_pa_tenant_clients.php',
            }
            self.assertEqual({}, host['gaps'])
            self.assertEqual(expected, {Path(row['migration']).name for row in host['alterations']})
            self.assertTrue({'pa_protected_resource', 'pa_target_type', 'pa_resource_operation',
                             'pa_resource_operation_target_type', 'pa_resource_operation_permission',
                             'pa_data_condition_definition', 'pa_resource_operation_condition'}
                            .issubset({row['table'] for row in host['records']}))
            self.assertEqual(6, checker.module_php_schema_inventory()['migration_count'])
            self.assertTrue(set(host['gaps']).issubset(checker.schema_coverage_gaps()))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--source-ref', required=True)
    OPTIONS, remaining = parser.parse_known_args()
    unittest.main(argv=[__file__, *remaining])
