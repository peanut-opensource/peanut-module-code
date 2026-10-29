"""Reviewed explicit-scope registration is not a global ORM or system exemption."""
from __future__ import annotations

from contextlib import redirect_stdout
import hashlib
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
loader = importlib.machinery.SourceFileLoader('tpq_scoped', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


class ScopedAccessTest(unittest.TestCase):
    def setUp(self):
        parent = ROOT / '.local/tmp/tpq-scoped-access'
        parent.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=parent)
        self.root = Path(self.temp.name)
        self.old = checker.ROOT, checker.OWNERSHIP, checker.REGISTER
        checker.ROOT = self.root
        checker.OWNERSHIP = self.root / 'resources/architecture/tpq-data-ownership.json'
        checker.REGISTER = self.root / 'history-must-not-be-read.json'
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/'}}}))
        self.write('server/app/modules/fixture/example/module.json', json.dumps({'key': 'fixture.example', 'database': {'owned_tables': ['pa_record']}}))
        self.model = 'Fixture\\Model\\Record'
        self.entry = 'Fixture\\Store'
        self.model_path = 'server/app/modules/fixture/example/src/Model/Record.php'
        self.store_path = 'server/app/modules/fixture/example/src/Store.php'
        self.write(self.model_path, '<?php\nnamespace Fixture\\Model;\nuse PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel;\nfinal class Record extends TenantModel { protected $name = "record"; }\n')
        self.store = '<?php\nnamespace Fixture;\nfinal class Store { public function read(int $tenantId): array { return []; } public function expireDue(): void {} }\n'
        self.write(self.store_path, self.store)
        self.write('server/database/init.sql', 'CREATE TABLE `pa_record` (`id` INT, `tenant_id` BIGINT) ENGINE=InnoDB;\n')
        self.contract = {'source': 'application', 'path': self.store_path,
                         'sha256': hashlib.sha256(self.store.encode()).hexdigest(),
                         'scope': 'explicit-tenant-column', 'tenant_operations': ['read'],
                         'other_operations': [], 'pending_operations': ['expireDue'],
                         'reason': 'Tenant read inspected; cross-tenant expiry has no authorized caller.',
                         'support_sources': []}
        self.registry = {'model_owners': [{'model': self.model, 'table': 'pa_record', 'owner': 'tenant-gateway', 'access_entry': self.entry}],
                         'tenant_tables': [{'table': 'pa_record', 'owner': 'tenant-gateway', 'access_entry': self.entry}],
                         'access_contracts': {self.entry: self.contract},
                         'counts': {'concrete_models': 1, 'tenant_models': 1, 'non_tenant_models': 0, 'tenant_tables': 1, 'tenant_tables_without_model': 0}}
        self.save()

    def tearDown(self):
        checker.ROOT, checker.OWNERSHIP, checker.REGISTER = self.old
        self.temp.cleanup()

    def write(self, name, text):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding='utf-8')
        return path

    def save(self):
        self.write('resources/architecture/tpq-data-ownership.json', json.dumps(self.registry))

    def invoke(self, *args):
        out = io.StringIO()
        with patch.object(checker.sys, 'argv', ['checker', '--ownership-only', '--json', *args]), redirect_stdout(out):
            code = checker.main()
        return code, json.loads(out.getvalue())

    def test_explicit_model_can_be_registered_without_claiming_global_scope(self):
        self.assertEqual([], checker.ownership_errors())
        code, report = self.invoke()
        self.assertEqual(2, code)
        self.assertEqual('decision_required', report['status'])
        self.assertEqual(['expireDue'], report['needs_decision'][0]['operations'])
        self.assertIsNone(report['finding_count'])
        self.assertFalse(report['historical_register_read'])

    def test_missing_access_review_is_not_approval(self):
        del self.registry['access_contracts']
        self.save()
        self.assertTrue(checker.ownership_errors())

    def test_registered_tenant_model_still_rejects_wrong_base(self):
        self.write(self.model_path, '<?php\nnamespace Fixture\\Model;\nclass Record extends \\think\\Model { protected $name = "record"; }\n')
        self.assertTrue(any('must extend' in e for e in checker.ownership_errors()))

    def test_removed_scope_or_new_method_invalidates_review(self):
        self.write(self.store_path, self.store.replace('return [];', 'return arbitraryQuery();'))
        self.assertTrue(any('SOURCE_REVIEW_STALE' in e for e in checker.ownership_errors()))

    def test_unclassified_method_cannot_be_silently_authorized(self):
        self.contract['pending_operations'] = []
        self.save()
        self.assertTrue(any('ACCESS_METHOD_COVERAGE' in e for e in checker.ownership_errors()))

    def test_duplicate_method_role_is_rejected(self):
        self.contract['other_operations'] = ['read']
        self.save()
        self.assertTrue(any('ACCESS_METHOD' in e for e in checker.ownership_errors()))

    def test_gateway_model_and_table_must_use_same_entry(self):
        self.registry['model_owners'][0]['access_entry'] = 'Fixture\\Unreviewed'
        self.save()
        self.assertTrue(checker.ownership_errors())

    def test_another_module_cannot_be_approved_as_private_gateway(self):
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/', 'Other\\': 'app/modules/other/example/src/'}}}))
        self.write('server/app/modules/other/example/module.json', json.dumps({'key': 'other.example', 'database': {'owned_tables': []}}))
        source = self.store.replace('namespace Fixture;', 'namespace Other;')
        path = 'server/app/modules/other/example/src/Store.php'
        self.write(path, source)
        self.contract.update(path=path, sha256=hashlib.sha256(source.encode()).hexdigest())
        self.registry['access_contracts'] = {'Other\\Store': self.contract}
        self.registry['model_owners'][0]['access_entry'] = 'Other\\Store'
        self.registry['tenant_tables'][0]['access_entry'] = 'Other\\Store'
        self.save()
        self.assertTrue(any('ACCESS_MODULE_OWNER' in e for e in checker.ownership_errors()))

    def test_public_persistence_entry_cannot_be_approved_by_registration(self):
        self.write('server/app/modules/fixture/example/module.json', json.dumps({
            'key': 'fixture.example', 'database': {'owned_tables': ['pa_record']},
            'contracts': {'exports': [self.entry]}
        }))
        self.assertTrue(any('ACCESS_PERSISTENCE_EXPORTED' in e for e in checker.ownership_errors()))

    def test_unknown_scope_and_malformed_contract_are_rejected(self):
        self.contract['scope'] = 'global-exemption'
        self.save()
        self.assertTrue(any('ACCESS_SCOPE_INVALID' in e for e in checker.ownership_errors()))
        self.registry['access_contracts'][self.entry] = None
        self.save()
        self.assertTrue(any('ACCESS_CONTRACT_INVALID' in e for e in checker.ownership_errors()))

    def test_symlink_reviewed_source_is_not_followed(self):
        target = self.root / self.store_path
        target.unlink()
        target.symlink_to('/outside/never-read.php')
        self.assertTrue(any('REVIEW_PATH_SYMLINK' in e for e in checker.ownership_errors()))

    def test_external_entry_requires_explicit_core(self):
        self.contract['source'] = 'php-core'
        self.save()
        self.assertTrue(any('CORE_SOURCE_REQUIRED' in e for e in checker.ownership_errors()))

    def test_external_arguments_are_paired(self):
        for args in [('--php-core-root', str(self.root)), ('--php-core-ref', 'a' * 40)]:
            code, report = self.invoke(*args)
            self.assertEqual(2, code)
            self.assertEqual('configuration_failed', report['status'])

    def test_support_source_change_is_not_implicitly_reapproved(self):
        self.write('server/app/guard.php', '<?php // inspected guard')
        self.contract['support_sources'] = [{'source': 'application', 'path': 'server/app/guard.php', 'sha256': '0' * 64}]
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in e for e in checker.ownership_errors()))

    def test_read_dependencies_cannot_escape_source_root(self):
        self.contract['support_sources'] = [{'source': 'application', 'path': '../outside.php', 'sha256': '0' * 64}]
        self.save()
        self.assertTrue(any('REVIEW_PATH' in e for e in checker.ownership_errors()))

    def test_gateway_count_means_actual_registered_model_presence(self):
        self.registry['counts']['tenant_tables_without_model'] = 1
        self.save()
        self.assertTrue(any('counts mismatch' in e for e in checker.ownership_errors()))

    def test_core_snapshot_uses_selected_commit_not_dirty_checkout(self):
        core = self.root / 'core'
        core.mkdir()
        def git(*args):
            return subprocess.check_output(['git', '-C', str(core), *args], text=True, stderr=subprocess.PIPE).strip()
        self.write('core/composer.json', json.dumps({'name': 'peanut-admin/core', 'autoload': {'psr-4': {'PeanutAdmin\\Kernel\\': 'kernel/src/'}}}))
        self.write('core/kernel/src/Fixture.php', '<?php\nnamespace PeanutAdmin\\Kernel;\nfinal class Fixture {}\n')
        git('init', '-q'); git('add', 'composer.json', 'kernel')
        git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'core fixture')
        ref = git('rev-parse', 'HEAD')
        self.write('core/kernel/src/Fixture.php', 'dirty invalid file')
        before = git('status', '--porcelain')
        with checker.php_core_snapshot(str(core), ref):
            self.assertEqual('PeanutAdmin\\Kernel\\Fixture', checker.declared_type(checker.composer_model_path('PeanutAdmin\\Kernel\\Fixture', checker.PHP_CORE_ROOT, checker.PHP_CORE_ROOT), checker.PHP_CORE_ROOT))
        self.assertEqual(before, git('status', '--porcelain'))
        self.assertIsNone(checker.PHP_CORE_ROOT)


if __name__ == '__main__':
    unittest.main()
