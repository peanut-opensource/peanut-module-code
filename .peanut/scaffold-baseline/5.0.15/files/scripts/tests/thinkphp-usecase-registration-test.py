"""Explicit public use-case identity is not a public persistence or auth exemption."""
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
loader = importlib.machinery.SourceFileLoader('tpq_usecase', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


class PublicUseCaseRegistrationTest(unittest.TestCase):
    def setUp(self):
        tmp = ROOT / '.local/tmp/tpq-usecase'
        tmp.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=tmp)
        self.root = Path(self.temp.name)
        self.old = checker.ROOT
        checker.ROOT = self.root
        self.entry = 'Fixture\\Application\\MemberService'
        self.path = 'server/app/modules/fixture/example/src/Application/MemberService.php'
        self.manifest = 'server/app/modules/fixture/example/module.json'
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/'}}}))
        self.write(self.manifest, json.dumps({'key': 'fixture.example', 'database': {'owned_tables': ['pa_member']}, 'contracts': {'exports': [self.entry]}}))
        self.source = '<?php\nnamespace Fixture\\Application;\nfinal class MemberService { public function read(int $tenantId): array { return []; } public function change(): void {} }\n'
        self.write(self.path, self.source)
        self.contract = {**self.proof(self.path), 'scope': 'explicit-tenant-column',
                         'tenant_operations': ['read', 'change'], 'other_operations': [], 'pending_operations': [],
                         'reason': 'Source-specific business result and scope review; does not authorize calls.', 'support_sources': [],
                         'public_use_case': {'manifest_source': self.proof(self.manifest),
                                             'method_results': {'read': 'array', 'change': 'void'},
                                             'reason': 'Public use case; no raw persistence results.'}}

    def tearDown(self):
        checker.ROOT = self.old
        self.temp.cleanup()

    def write(self, name, source):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(source, encoding='utf-8')

    def proof(self, name):
        return {'source': 'application', 'path': name, 'sha256': hashlib.sha256((self.root / name).read_bytes()).hexdigest()}

    def errors(self):
        return checker.access_contract_errors({self.entry: self.contract})

    def replace_source(self, source):
        self.write(self.path, source)
        self.contract.update(self.proof(self.path))

    def test_exact_exported_usecase_is_accepted_as_data_access_not_authority(self):
        self.assertEqual([], self.errors())

    def test_default_exported_store_remains_rejected(self):
        del self.contract['public_use_case']
        self.assertTrue(any('ACCESS_PERSISTENCE_EXPORTED' in e for e in self.errors()))

    def test_missing_or_forged_manifest_cannot_authorize_public_entry(self):
        self.contract['public_use_case']['manifest_source']['sha256'] = '0' * 64
        self.assertTrue(any('SOURCE_REVIEW_STALE' in e for e in self.errors()))

    def test_removed_export_is_not_silently_a_private_contract(self):
        self.write(self.manifest, json.dumps({'key': 'fixture.example', 'contracts': {'exports': []}}))
        self.contract['public_use_case']['manifest_source'] = self.proof(self.manifest)
        self.assertTrue(self.errors())

    def test_another_modules_manifest_is_not_the_entry_owner(self):
        name = 'server/app/modules/another/example/module.json'
        self.write(name, json.dumps({'key': 'another.example', 'contracts': {'exports': [self.entry]}}))
        self.contract['public_use_case']['manifest_source'] = self.proof(name)
        self.assertTrue(self.errors())

    def test_missing_or_extra_result_roles_are_not_accepted(self):
        self.contract['public_use_case']['method_results'].pop('change')
        self.assertTrue(self.errors())
        self.contract['public_use_case']['method_results']['invented'] = 'array'
        self.assertTrue(self.errors())

    def test_orm_and_mixed_or_untyped_results_cannot_borrow_usecase_proof(self):
        for result in ['\\think\\Model', '\\think\\db\\Query', 'mixed', 'object', '?array', 'array|bool', '']:
            with self.subTest(result=result):
                self.replace_source(self.source.replace(': array', ': ' + result if result else ''))
                self.contract['public_use_case']['method_results']['read'] = result
                self.assertTrue(self.errors())

    def test_model_inheritance_cannot_be_registered_as_usecase(self):
        self.replace_source(self.source.replace('class MemberService', 'class MemberService extends \\think\\Model'))
        self.assertTrue(self.errors())

    def test_trait_or_dynamic_magic_public_surface_is_not_ignored(self):
        self.replace_source(self.source.replace('{ public', '{ use \\ExtraMethods; public', 1))
        self.assertTrue(self.errors())
        self.replace_source(self.source.replace('function read', 'function __call'))
        self.contract['tenant_operations'] = ['__call', 'change']
        self.contract['public_use_case']['method_results'] = {'__call': 'array', 'change': 'void'}
        self.assertTrue(self.errors())

    def test_public_role_does_not_hide_pending_operations(self):
        self.contract['tenant_operations'] = ['read']
        self.contract['pending_operations'] = ['change']
        self.assertEqual([], self.errors())
        self.contract['pending_operations'] = []
        self.assertTrue(any('ACCESS_METHOD_COVERAGE' in e for e in self.errors()))

    def test_unchanged_hash_cannot_cover_changed_body(self):
        self.write(self.path, self.source.replace('return [];', 'return allTenants();'))
        self.assertTrue(any('SOURCE_REVIEW_STALE' in e for e in self.errors()))

    def test_individually_bound_technical_interface_keeps_result_surface(self):
        interface = 'server/app/modules/fixture/example/src/Writer.php'
        self.write(interface, '<?php namespace Fixture; interface Writer { public function change(): void; }')
        self.replace_source(self.source.replace('class MemberService {', 'class MemberService implements \\Fixture\\Writer {'))
        self.contract['public_use_case']['interfaces'] = [{**self.proof(interface), 'entry': 'Fixture\\Writer'}]
        self.assertEqual([], self.errors())
        self.contract['public_use_case']['interfaces'][0]['sha256'] = '0' * 64
        self.assertTrue(self.errors())

    def test_unreviewed_interface_cannot_bypass_default_public_store_rule(self):
        self.replace_source(self.source.replace('class MemberService {', 'class MemberService implements \\Fixture\\Writer {'))
        self.assertTrue(self.errors())

    def test_reason_and_exact_source_case_are_required(self):
        self.contract['public_use_case']['reason'] = ''
        self.assertTrue(self.errors())
        self.contract['public_use_case']['reason'] = 'Reviewed'
        self.contract['public_use_case']['manifest_source']['path'] = self.manifest.replace('module.json', 'Module.json')
        self.assertTrue(self.errors())


if __name__ == '__main__':
    unittest.main()
