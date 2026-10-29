"""Current TPQ ownership checks never consult historical exception decisions."""
from __future__ import annotations

from contextlib import redirect_stdout
import importlib.machinery
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_current', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


class CurrentOwnershipTest(unittest.TestCase):
    def setUp(self):
        parent = ROOT / '.local/tmp/tpq-current-ownership'
        parent.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=parent)
        self.root = Path(self.temp.name)
        self.old = checker.ROOT, checker.OWNERSHIP, checker.REGISTER
        checker.ROOT = self.root
        checker.OWNERSHIP = self.root / 'resources/architecture/tpq-data-ownership.json'
        # This path deliberately does not exist. A current audit cannot read it.
        checker.REGISTER = self.root / 'forbidden-history.json'
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/'}}}))
        self.model = 'Fixture\\Model\\Record'
        self.source = 'server/app/modules/fixture/example/src/Model/Record.php'
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nuse app\\common\\model\\TenantOwnedModel;\nclass Record extends TenantOwnedModel { protected $name = "record"; }\n')
        self.write('server/database/init.sql', 'CREATE TABLE `pa_record` (`id` INT, `tenant_id` BIGINT) ENGINE=InnoDB;\n')
        self.registry = {
            'model_owners': [{'model': self.model, 'table': 'pa_record', 'owner': 'tenant-orm'}],
            'tenant_tables': [{'table': 'pa_record', 'owner': 'tenant-orm', 'access_entry': self.model}],
            'counts': {'concrete_models': 1, 'tenant_models': 1, 'non_tenant_models': 0, 'tenant_tables': 1, 'tenant_tables_without_model': 0},
        }
        self.save_registry()

    def tearDown(self):
        checker.ROOT, checker.OWNERSHIP, checker.REGISTER = self.old
        self.temp.cleanup()

    def write(self, name, text):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding='utf-8')
        return path

    def save_registry(self):
        self.write('resources/architecture/tpq-data-ownership.json', json.dumps(self.registry))

    def invoke(self, *arguments):
        output = io.StringIO()
        with patch.object(checker.sys, 'argv', ['checker', *arguments]), redirect_stdout(output):
            code = checker.main()
        return code, json.loads(output.getvalue())

    def test_current_mode_never_reads_history_or_scans_business(self):
        with patch.object(checker, 'current_findings', side_effect=AssertionError('business scan')), \
             patch.object(checker, 'refresh_register', side_effect=AssertionError('history mutation')):
            code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(0, code)
        self.assertEqual('ownership_passed', report['status'])
        self.assertFalse(report['historical_register_read'])
        self.assertIsNone(report['finding_count'])
        self.assertEqual('not_run', report['business_scan'])

    def test_current_mode_rejects_refresh_and_strict(self):
        for flag in ('--refresh-register', '--strict'):
            code, report = self.invoke('--ownership-only', '--json', flag)
            self.assertEqual(2, code)
            self.assertEqual('configuration_failed', report['status'])

    def test_source_ref_requires_explicit_current_mode(self):
        code, report = self.invoke('--source-ref', 'a' * 40, '--json')
        self.assertEqual(2, code)
        self.assertEqual('configuration_failed', report['status'])

    def test_missing_models_are_errors_not_zero_business_findings(self):
        self.registry['model_owners'] = []
        self.save_registry()
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(2, code)
        self.assertEqual('registration_failed', report['status'])
        self.assertIsNone(report['finding_count'])
        self.assertIn(self.model, report['missing_models'])

    def test_malformed_registry_has_structured_failure(self):
        checker.OWNERSHIP.write_text('[]')
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(2, code)
        self.assertTrue(report['registration_errors'])

    def test_non_list_registry_field_does_not_crash(self):
        self.registry['model_owners'] = 'invalid'
        self.save_registry()
        self.assertTrue(checker.ownership_errors())

    def test_non_object_registry_row_does_not_crash(self):
        self.registry['model_owners'] = [None]
        self.save_registry()
        self.assertTrue(checker.ownership_errors())

    def test_comments_cannot_fake_the_expected_base(self):
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nclass Record extends Wrong {} // extends TenantOwnedModel\n')
        self.assertTrue(any('must extend' in e for e in checker.ownership_errors()))

    def test_longer_base_name_is_not_the_expected_base(self):
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nclass Record extends TenantOwnedModelUnscoped {}\n')
        self.assertTrue(any('must extend' in e for e in checker.ownership_errors()))

    def test_import_alias_resolves_to_the_exact_approved_base(self):
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nuse app\\common\\model\\TenantOwnedModel as Scoped;\nclass Record extends Scoped { protected $name = "record"; }\n')
        self.assertEqual([], checker.ownership_errors())

    def test_same_short_name_from_another_namespace_is_rejected(self):
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nuse Other\\TenantOwnedModel;\nclass Record extends TenantOwnedModel {}\n')
        self.assertTrue(any('must extend' in e for e in checker.ownership_errors()))

    def test_explicit_scope_core_base_is_not_global_scope(self):
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nuse PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel;\nclass Record extends TenantModel {}\n')
        self.assertTrue(any('must extend' in e for e in checker.ownership_errors()))

    def test_wrong_declared_class_does_not_match_a_composer_filename(self):
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nuse app\\common\\model\\TenantOwnedModel;\nclass Another extends TenantOwnedModel {}\n')
        self.assertTrue(any('declaration' in e for e in checker.ownership_errors()))

    def test_tenant_orm_entry_must_match_the_table_model(self):
        self.registry['tenant_tables'][0]['access_entry'] = 'Fixture\\Model\\Other'
        self.save_registry()
        self.assertTrue(any('access_entry' in e for e in checker.ownership_errors()))

    def test_empty_source_cannot_pass(self):
        (self.root / self.source).unlink()
        self.registry['model_owners'] = []
        self.registry['tenant_tables'] = []
        self.registry['counts'] = dict.fromkeys(self.registry['counts'], 0)
        self.write('server/database/init.sql', '')
        self.save_registry()
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(2, code)
        self.assertEqual('registration_failed', report['status'])

    def test_kernel_schema_nowdoc_is_included_without_executing_php(self):
        self.write('server/database/schema/KernelSchema.php', "<?php\nthrow new Exception('never run');\nclass KernelSchema { private const CREATE_SQL = [\n'pa_kernel' => <<<'SQL'\nCREATE TABLE `pa_kernel` (`id` INT, `tenant_id` BIGINT) ENGINE=InnoDB\nSQL,\n]; }\n")
        self.write('server/database/kernel-migrations/one.php', "<?php\nprotected const TABLE = 'pa_kernel';\n$this->execute(KernelSchema::createSql(self::TABLE));\n")
        self.assertEqual({'pa_record', 'pa_kernel'}, checker.schema_tenant_tables())

    def test_unconsumed_kernel_schema_entry_is_not_counted_as_migrated(self):
        self.write('server/database/schema/KernelSchema.php', "<?php\n'pa_unused' => <<<'SQL'\nCREATE TABLE `pa_unused` (`tenant_id` BIGINT) ENGINE=InnoDB\nSQL,\n")
        self.assertEqual({'pa_record'}, checker.schema_tenant_tables())

    def test_missing_referenced_kernel_table_fails_closed(self):
        self.write('server/database/schema/KernelSchema.php', '<?php')
        self.write('server/database/kernel-migrations/one.php', "<?php\nprotected const TABLE = 'pa_missing';\n$this->execute(KernelSchema::createSql(self::TABLE));\n")
        with self.assertRaisesRegex(ValueError, 'KERNEL_SCHEMA'):
            checker.schema_tenant_tables()

    def test_unparsed_module_php_migration_prevents_complete_coverage_claim(self):
        path = 'server/app/modules/fixture/example/database/migrations/adopt.php'
        self.write(path, '<?php\nSchema::createSql("pa_hidden");\n')
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(2, code)
        self.assertEqual('check_incomplete', report['status'])
        self.assertFalse(report['coverage_complete'])
        self.assertEqual([path], report['coverage_gaps'])
        self.assertNotIn('pa_hidden', report['missing_tenant_tables'])
        self.assertIsNone(report['finding_count'])

    def test_nested_module_php_migration_is_not_silently_skipped(self):
        path = 'server/app/modules/fixture/example/access/database/migrations/adopt.php'
        self.write(path, '<?php\nSchema::createSql("pa_hidden");\n')
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(2, code)
        self.assertIn(path, report['coverage_gaps'])

    def test_type_directory_case_is_not_mistaken_for_a_migration_directory(self):
        self.write('server/app/modules/fixture/example/src/Database/Migrations/OwnedMigration.php', '<?php\nclass OwnedMigration {}\n')
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(0, code)
        self.assertEqual([], report['coverage_gaps'])

    def test_supported_sql_fixture_retains_complete_source_coverage(self):
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(0, code)
        self.assertTrue(report['coverage_complete'])
        self.assertEqual([], report['coverage_gaps'])

    def test_registered_table_must_match_actual_model_table(self):
        self.registry['model_owners'][0]['table'] = 'pa_other'
        self.save_registry()
        self.assertTrue(any('table declaration' in e for e in checker.ownership_errors()))

    def test_model_cannot_be_registered_for_another_modules_private_table(self):
        self.write('server/app/modules/fixture/example/module.json', json.dumps({
            'key': 'fixture.example', 'database': {'owned_tables': []}
        }))
        self.write('server/app/modules/other/example/module.json', json.dumps({
            'key': 'other.example', 'database': {'owned_tables': ['pa_record']}
        }))
        self.assertTrue(any('module table owner mismatch' in e for e in checker.ownership_errors()))

    def test_missing_literal_table_is_unverified_not_implicitly_approved(self):
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nuse app\\common\\model\\TenantOwnedModel;\nclass Record extends TenantOwnedModel {}\n')
        self.assertTrue(any('table declaration' in e for e in checker.ownership_errors()))

    def test_heredoc_model_is_reported_unsupported_not_parsed_as_a_class(self):
        self.write(self.source, "<?php\n$x = <<<'CODE'\nnamespace Fixture\\Model;\nclass Record extends TenantOwnedModel {}\nCODE;\n")
        with self.assertRaisesRegex(ValueError, 'UNSUPPORTED'):
            checker.model_header(self.root / self.source)

    def test_gateway_entry_requires_a_real_declared_application_type(self):
        self.registry['tenant_tables'][0].update(owner='tenant-gateway', access_entry='Fixture\\Gateway')
        # A gateway entry does not make the existing concrete model disappear.
        self.registry['counts']['tenant_tables_without_model'] = 0
        self.save_registry()
        self.assertTrue(any('access_entry' in e for e in checker.ownership_errors()))
        path = 'server/app/modules/fixture/example/src/Gateway.php'
        self.write(path, '<?php\nnamespace Fixture;\nfinal class Gateway {}\n')
        self.assertEqual([], checker.ownership_errors())
        self.write(path, '<?php\nnamespace Fixture;\nfinal class Wrong {}\n')
        self.assertTrue(any('access_entry declaration' in e for e in checker.ownership_errors()))

    def test_duplicate_module_table_owners_are_not_silently_overwritten(self):
        for vendor in ('first', 'second'):
            self.write('server/app/modules/' + vendor + '/example/module.json', json.dumps({
                'key': vendor + '.example', 'database': {'owned_tables': ['pa_record']}
            }))
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(2, code)
        self.assertTrue(any('MODULE_TABLE_OWNER_DUPLICATE' in e for e in report['registration_errors']))

    def test_native_module_and_table_names_are_diagnostics_not_approval(self):
        self.write(self.source, '<?php\nnamespace Fixture\\Model;\nuse app\\common\\model\\TenantOwnedModel;\nclass Record extends TenantOwnedModel { protected $name = "record"; }\n')
        self.write('server/app/modules/fixture/example/module.json', json.dumps({
            'key': 'fixture.example', 'database': {'owned_tables': ['pa_record']}
        }))
        self.registry['model_owners'] = []
        self.save_registry()
        code, report = self.invoke('--ownership-only', '--json')
        self.assertEqual(2, code)
        candidate = report['model_candidates'][0]
        self.assertEqual('fixture.example', candidate['module'])
        self.assertEqual('fixture.example', candidate['table_owner'])
        self.assertEqual('pa_record', candidate['table'])
        self.assertEqual('not_registered_not_approved', candidate['classification'])

    def test_table_name_cannot_be_faked_by_comments_or_strings(self):
        self.write(self.source, '<?php\n// protected $name = "fake";\n$x = \'protected $name = "fake";\';\nclass Record { protected $name = "record"; }\n')
        self.assertEqual('pa_record', checker.source_model_table(self.root / self.source))
        self.write(self.source, '<?php\nclass Record { protected $name = PREFIX . "record"; }\n')
        self.assertIsNone(checker.source_model_table(self.root / self.source))

    def test_symlink_in_fixed_source_is_rejected_not_followed(self):
        self.git('init', '-q')
        (self.root / 'server/app/link.php').symlink_to('/outside/never-read.php')
        self.git('add', 'server')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'link fixture')
        code, report = self.invoke('--ownership-only', '--source-ref', self.git('rev-parse', 'HEAD'), '--json')
        self.assertEqual(2, code)
        self.assertEqual('configuration_failed', report['status'])
        self.assertFalse(report['historical_register_read'])
        self.assertEqual(self.root, checker.ROOT)

    def test_export_ignore_cannot_hide_unregistered_source(self):
        self.git('init', '-q')
        hidden = 'server/app/modules/fixture/example/src/Model/Hidden.php'
        self.write(hidden, '<?php\nnamespace Fixture\\Model;\nclass Hidden extends TenantOwnedModel {}\n')
        self.write('.gitattributes', hidden + ' export-ignore\n')
        self.git('add', 'server', '.gitattributes')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'export fixture')
        code, report = self.invoke('--ownership-only', '--source-ref', self.git('rev-parse', 'HEAD'), '--json')
        self.assertEqual(2, code)
        self.assertEqual('configuration_failed', report['status'])

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], stderr=subprocess.PIPE, text=True).strip()

    def test_source_snapshot_is_exact_and_does_not_checkout_or_read_history(self):
        self.git('init', '-q')
        self.git('add', 'server')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture')
        ref = self.git('rev-parse', 'HEAD')
        self.write(self.source, 'uncommitted invalid bytes')
        before = self.git('status', '--porcelain')
        code, report = self.invoke('--ownership-only', '--source-ref', ref, '--json')
        self.assertEqual(0, code)
        self.assertEqual(ref, report['source_commit'])
        self.assertEqual(before, self.git('status', '--porcelain'))
        self.assertEqual('uncommitted invalid bytes', (self.root / self.source).read_text())
        self.assertFalse(checker.REGISTER.exists())
        self.assertEqual(self.root, checker.ROOT)

    def test_invalid_source_ref_is_configuration_failure(self):
        for ref in ('dev', '--help', 'a' * 39, 'a' * 40):
            code, report = self.invoke('--ownership-only', '--source-ref=' + ref, '--json')
            self.assertEqual(2, code)
            self.assertEqual('configuration_failed', report['status'])


if __name__ == '__main__':
    unittest.main()
