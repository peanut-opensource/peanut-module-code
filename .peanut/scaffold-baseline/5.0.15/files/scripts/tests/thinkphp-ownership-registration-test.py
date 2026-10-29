"""Retained TPQ ownership checks: source discovery, self-access, and fail-closed JSON.

Fixtures are confined to this checkout's .local/tmp. PHP bytes are only inspected;
no business source, database, historical issue status, or runtime resource is run.
"""
from __future__ import annotations

from contextlib import redirect_stdout
import importlib.machinery
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


SOURCE_ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader(
    'tpq_ownership_registration', str(SOURCE_ROOT / 'scripts/check-thinkphp-architecture')
)
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


class OwnershipRegistrationTest(unittest.TestCase):
    def setUp(self):
        parent = SOURCE_ROOT / '.local/tmp/tpq-ownership-registration'
        parent.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=parent)
        self.root = Path(self.temporary.name)
        self.previous = (checker.ROOT, checker.OWNERSHIP, checker.REGISTER)
        checker.ROOT = self.root
        checker.OWNERSHIP = self.root / 'resources/architecture/tpq-data-ownership.json'
        checker.REGISTER = self.root / 'resources/architecture/tpq-issue-register.json'
        self.mapping({'Fixture\\Module\\': 'app/modules/fixture/example/src/'})
        self.model = 'Fixture\\Module\\Model\\Storage\\Record'
        self.owner_path = 'server/app/modules/fixture/example/src/Model/Storage/Record.php'
        self.php = (
            '<?php\nnamespace Fixture\\Module\\Model\\Storage;\n'
            'class Record extends TenantOwnedModel {\n'
            '  public function rows() { return Db::name("pa_record")->select(); }\n'
            '}\n'
        )
        self.write(self.owner_path, self.php)
        self.ownership({'model_owners': [
            {'model': self.model, 'table': 'pa_record', 'owner': 'tenant-orm'}
        ]})
        self.write('resources/architecture/tpq-issue-register.json', json.dumps({
            'rules': [], 'issues': [], 'known_tenant_models': []
        }))

    def tearDown(self):
        checker.ROOT, checker.OWNERSHIP, checker.REGISTER = self.previous
        self.temporary.cleanup()

    def write(self, relative, content):
        target = self.root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding='utf-8')
        return target

    def mapping(self, mappings):
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': mappings}}))

    def ownership(self, value):
        self.write('resources/architecture/tpq-data-ownership.json', json.dumps(value))

    def test_nested_model_directories_are_not_omitted(self):
        self.write('server/app/modules/fixture/example/src/Persistence/Model/Flat.php',
                   '<?php\nnamespace Fixture\\Module\\Persistence\\Model;\n'
                   'class Flat extends TenantOwnedModel {}\n')
        self.assertEqual({self.model, 'Fixture\\Module\\Persistence\\Model\\Flat'},
                         checker.concrete_models())

    def test_discovery_does_not_execute_php(self):
        self.write(self.owner_path, self.php + 'throw new Exception("never execute");\n')
        self.assertIn(self.model, checker.concrete_models())

    def test_owner_path_uses_current_composer_mapping(self):
        self.assertEqual([], checker.model_owner_bypass_hits(
            self.root / self.owner_path, self.php))

    def test_unrelated_consumer_cannot_use_the_owner_exemption(self):
        path = self.root / 'server/app/adminapi/services/Consumer.php'
        hits = checker.model_owner_bypass_hits(path, self.php)
        self.assertEqual([('model_owner_bypass', 4, 'pa_record bypasses ' + self.model)], hits)

    def test_guessed_namespace_path_is_not_the_registered_owner(self):
        guessed = self.root / ('server/' + self.model.replace('\\', '/') + '.php')
        hits = checker.model_owner_bypass_hits(guessed, self.php)
        self.assertEqual(1, len(hits))
        self.assertEqual('model_owner_bypass', hits[0][0])

    def test_missing_mapping_does_not_grant_a_self_access_exemption(self):
        self.mapping({})
        hits = checker.model_owner_bypass_hits(self.root / self.owner_path, self.php)
        self.assertEqual(1, len(hits))

    def test_ambiguous_owner_mapping_is_rejected(self):
        self.mapping({'Fixture\\Module\\': ['first/', 'second/']})
        self.write('server/first/Model/Storage/Record.php', self.php)
        self.write('server/second/Model/Storage/Record.php', self.php)
        with self.assertRaisesRegex(ValueError, 'AMBIGUOUS'):
            checker.model_owner_bypass_hits(self.root / self.owner_path, self.php)

    def test_registration_errors_remain_json_and_do_not_scan_or_refresh(self):
        before = checker.REGISTER.read_bytes()
        output = io.StringIO()
        with patch.object(checker.sys, 'argv', ['checker', '--json', '--refresh-register']), \
             patch.object(checker, 'ownership_errors', return_value=['fixture mismatch']), \
             patch.object(checker, 'current_findings') as scan, \
             patch.object(checker, 'refresh_register') as refresh, redirect_stdout(output):
            self.assertEqual(2, checker.main())
        result = json.loads(output.getvalue())
        self.assertEqual('registration_failed', result['status'])
        self.assertEqual(['fixture mismatch'], result['registration_errors'])
        self.assertEqual(1, result['registration_error_count'])
        self.assertIsNone(result['finding_count'])
        scan.assert_not_called()
        refresh.assert_not_called()
        self.assertEqual(before, checker.REGISTER.read_bytes())

    def test_default_registration_error_output_stays_text(self):
        output = io.StringIO()
        with patch.object(checker.sys, 'argv', ['checker']), \
             patch.object(checker, 'ownership_errors', return_value=['fixture mismatch']), \
             redirect_stdout(output):
            self.assertEqual(2, checker.main())
        self.assertEqual('REGISTRATION_ERROR fixture mismatch\n', output.getvalue())

    def test_missing_model_and_tenant_table_are_still_blocking(self):
        self.ownership({'model_owners': [], 'tenant_tables': [], 'counts': {
            'concrete_models': 0, 'tenant_models': 0, 'non_tenant_models': 0,
            'tenant_tables': 0, 'tenant_tables_without_model': 0
        }})
        with patch.object(checker, 'schema_tenant_tables', return_value={'pa_record'}):
            errors = checker.ownership_errors()
        self.assertTrue(any('model ownership mismatch' in item for item in errors))
        self.assertTrue(any('tenant table ownership mismatch' in item for item in errors))

    def test_wrong_tenant_base_is_not_accepted(self):
        self.write(self.owner_path, self.php.replace('extends TenantOwnedModel', 'extends Model'))
        errors = checker.ownership_errors()
        self.assertTrue(any('must extend TenantOwnedModel' in item for item in errors))

    def test_missing_declared_model_source_is_not_accepted(self):
        (self.root / self.owner_path).unlink()
        errors = checker.ownership_errors()
        self.assertTrue(any('has no exact current Composer source' in item for item in errors))


if __name__ == '__main__':
    unittest.main()
