"""Parent-owned registration proof: explicit same-owner relation, never a global exemption."""
from __future__ import annotations

import copy
import hashlib
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_parent', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


class ParentOwnershipTest(unittest.TestCase):
    def setUp(self):
        parent = ROOT / '.local/tmp/tpq-parent-ownership'
        parent.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=parent)
        self.root = Path(self.temp.name)
        self.old = checker.ROOT, checker.OWNERSHIP, checker.REGISTER
        checker.ROOT = self.root
        checker.OWNERSHIP = self.root / 'ownership.json'
        checker.REGISTER = self.root / 'history-must-not-exist.json'
        self.module = 'server/app/modules/fixture/example/'
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/'}}}))
        self.write(self.module + 'module.json', json.dumps({'key': 'fixture.example', 'database': {'owned_tables': ['pa_parent', 'pa_child']}}))
        self.parent_name, self.child_name = 'Fixture\\Model\\ParentRecord', 'Fixture\\Model\\ChildRecord'
        self.parent_path = self.module + 'src/Model/ParentRecord.php'
        self.child_path = self.module + 'src/Model/ChildRecord.php'
        self.write(self.parent_path, '<?php namespace Fixture\\Model; use PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel; final class ParentRecord extends TenantModel { protected $name = "parent"; }')
        self.write(self.child_path, '<?php namespace Fixture\\Model; use think\\Model; final class ChildRecord extends Model { protected $name = "child"; }')
        self.store_path = self.module + 'src/Store.php'
        self.write(self.store_path, '<?php namespace Fixture; final class Store { public function snapshot(int $tenantId): array { return []; } }')
        self.schema_path = self.module + 'database/migrations/create.sql'
        self.schema = 'CREATE TABLE `pa_parent` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n' + \
            'CREATE TABLE `pa_child` (`id` BIGINT UNSIGNED NOT NULL, `parent_id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`), CONSTRAINT `fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `pa_parent` (`id`) ON DELETE RESTRICT) ENGINE=InnoDB;'
        self.write(self.schema_path, self.schema)
        self.contract = dict(self.proof(self.store_path), scope='explicit-tenant-column', tenant_operations=['snapshot'], other_operations=[], pending_operations=[], reason='Fixture reviewed same-Tenant parent before child.', support_sources=[])
        self.parent = {'model': self.parent_name, 'table': 'pa_parent', 'owner': 'tenant-gateway', 'access_entry': 'Fixture\\Store'}
        self.child = {'model': self.child_name, 'table': 'pa_child', 'owner': 'tenant-gateway', 'access_entry': 'Fixture\\Store',
                      'parent_review': {'classification': 'tenant-through-parent', 'parent_model': self.parent_name,
                                        'column': 'parent_id', 'parent_column': 'id', 'operations': ['snapshot'],
                                        'reason': 'Child is reachable only through the already scoped parent; no ambient tenant inference.',
                                        'model_source': self.proof(self.child_path), 'parent_source': self.proof(self.parent_path),
                                        'schema_source': self.proof(self.schema_path)}}
        self.registry = {'model_owners': [self.parent, self.child], 'tenant_tables': [{'table': 'pa_parent', 'owner': 'tenant-gateway', 'access_entry': 'Fixture\\Store'}],
                         'access_contracts': {'Fixture\\Store': self.contract},
                         'counts': {'concrete_models': 2, 'tenant_models': 2, 'non_tenant_models': 0, 'tenant_tables': 1, 'tenant_tables_without_model': 0}}
        self.save()

    def tearDown(self):
        checker.ROOT, checker.OWNERSHIP, checker.REGISTER = self.old
        self.temp.cleanup()

    def write(self, path, text):
        value = self.root / path
        value.parent.mkdir(parents=True, exist_ok=True)
        value.write_text(text)
        return value

    def proof(self, path):
        return {'source': 'application', 'path': path, 'sha256': hashlib.sha256((self.root / path).read_bytes()).hexdigest()}

    def save(self):
        checker.OWNERSHIP.write_text(json.dumps(self.registry))

    def errors(self):
        self.save()
        return checker.ownership_errors()

    def schema_change(self, text):
        self.write(self.schema_path, text)
        self.child['parent_review']['schema_source'] = self.proof(self.schema_path)

    def test_exact_relation_is_tenant_owned_without_new_direct_tenant_table(self):
        self.assertEqual([], self.errors())
        report = checker.current_ownership_report(None)
        self.assertEqual('ownership_passed', report['status'])
        self.assertEqual(2, report['model_count'])
        self.assertEqual(1, report['tenant_table_count'])
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])
        self.assertFalse(report['historical_register_read'])

    def test_missing_parent_registration_or_native_source_cannot_pass(self):
        self.registry['model_owners'].remove(self.parent)
        self.assertTrue(any('PARENT_' in error for error in self.errors()))
        self.registry['model_owners'].append(self.parent)
        (self.root / self.parent_path).unlink()
        self.assertTrue(self.errors())

    def test_foreign_or_unscoped_parent_cannot_supply_tenant_authority(self):
        for mutation in [{'owner': 'instance'}, {'table': 'pa_foreign'}, {'access_entry': 'Other\\Store'}]:
            original = copy.deepcopy(self.parent)
            self.parent.update(mutation)
            self.assertTrue(any('PARENT_' in error for error in self.errors()))
            self.parent.clear(); self.parent.update(original)

    def test_self_reference_and_indirect_chains_are_not_implicitly_accepted(self):
        self.child['parent_review']['parent_model'] = self.child_name
        self.assertTrue(any('PARENT_' in error for error in self.errors()))
        self.child['parent_review']['parent_model'] = self.parent_name
        self.parent['parent_review'] = copy.deepcopy(self.child['parent_review'])
        self.assertTrue(any('PARENT_' in error for error in self.errors()))

    def test_removed_or_changed_relation_is_not_fixed_by_digest_refresh(self):
        for sql in [self.schema.replace('REFERENCES `pa_parent`', 'REFERENCES `pa_foreign`'),
                    self.schema.replace('FOREIGN KEY (`parent_id`)', 'FOREIGN KEY (`id`)'),
                    self.schema.replace('ON DELETE RESTRICT', 'ON DELETE CASCADE'),
                    self.schema.replace('`parent_id` BIGINT UNSIGNED NOT NULL', '`parent_id` BIGINT UNSIGNED NULL'),
                    self.schema.replace('PRIMARY KEY (`id`)', 'PRIMARY KEY (`tenant_id`)', 1)]:
            with self.subTest(sql=sql):
                self.schema_change(sql)
                self.assertTrue(any('PARENT_' in error for error in self.errors()))

    def test_comment_is_not_a_parent_foreign_key(self):
        sql = self.schema.replace(', CONSTRAINT `fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `pa_parent` (`id`) ON DELETE RESTRICT', '')
        sql = sql.replace('`parent_id` BIGINT UNSIGNED NOT NULL', "`parent_id` BIGINT UNSIGNED NOT NULL COMMENT 'CONSTRAINT `fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `pa_parent` (`id`) ON DELETE RESTRICT'")
        self.schema_change(sql)
        self.assertTrue(any('PARENT_' in error for error in self.errors()))

    def test_child_tenant_column_requires_its_own_direct_scope(self):
        self.schema_change(self.schema.replace('`parent_id` BIGINT', '`tenant_id` BIGINT NOT NULL, `parent_id` BIGINT'))
        self.assertTrue(any('PARENT_' in error for error in self.errors()))

    def test_parent_class_or_model_bytes_change_invalidates_evidence(self):
        self.write(self.parent_path, (self.root / self.parent_path).read_text() + '\n// changed')
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in self.errors()))

    def test_scope_caller_change_is_not_automatically_authorized(self):
        self.write(self.store_path, (self.root / self.store_path).read_text().replace('return [];', 'return unsafeQuery();'))
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in self.errors()))

    def test_pending_or_missing_operations_cannot_back_child_access(self):
        self.child['parent_review']['operations'] = ['unknown']
        self.assertTrue(any('PARENT_' in error for error in self.errors()))
        self.child['parent_review']['operations'] = []
        self.assertTrue(any('PARENT_' in error for error in self.errors()))

    def test_child_instance_or_shared_label_cannot_reuse_relation_proof(self):
        for owner in ['instance', 'shared']:
            self.child['owner'] = owner
            self.assertTrue(any('PARENT_REVIEW_NOT_APPLICABLE' in error for error in self.errors()))

    def test_child_model_cannot_be_exported_as_a_query_gateway(self):
        path = self.module + 'module.json'
        value = json.loads((self.root / path).read_text());value['contracts'] = {'exports': [self.child_name]}
        self.write(path, json.dumps(value))
        self.assertTrue(any('PARENT_' in error for error in self.errors()))

    def test_missing_review_and_wrong_case_fail(self):
        original = copy.deepcopy(self.child['parent_review'])
        self.child['parent_review'] = {}
        self.assertTrue(any('PARENT_' in error for error in self.errors()))
        self.child['parent_review'] = original
        self.child['parent_review']['parent_source']['path'] = self.parent_path.replace('/Model/', '/model/')
        self.assertTrue(self.errors())


if __name__ == '__main__':
    unittest.main()
