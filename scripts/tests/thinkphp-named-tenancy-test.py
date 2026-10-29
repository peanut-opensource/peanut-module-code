"""Source/recipient Tenant columns are explicit ownership, not shared fallback."""
from __future__ import annotations
import hashlib
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_named', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


class NamedTenancyTest(unittest.TestCase):
    def setUp(self):
        parent = ROOT / '.local/tmp/tpq-named-tenancy'
        parent.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=parent)
        self.old = checker.ROOT
        self.root = checker.ROOT = Path(self.temp.name)
        self.base = 'server/app/modules/fixture/example/'
        self.model = 'Fixture\\Model\\Grant'
        self.path = self.base + 'src/Model/Grant.php'
        self.schema = self.base + 'database/migrations/grant.sql'
        self.entry = 'Fixture\\GrantService'
        self.reader = 'Fixture\\ReadAuthority'
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/'}}}))
        self.write(self.base+'module.json', json.dumps({'key':'fixture.example','database':{'owned_tables':['pa_grant']},'contracts':{'exports':[]}}))
        self.write(self.path, '<?php namespace Fixture\\Model; class Grant extends \\think\\Model { protected $name = "grant"; }')
        self.write(self.base+'src/GrantService.php', '<?php namespace Fixture; class GrantService { public function put(): void {} public function revoke(): void {} }')
        self.write(self.base+'src/ReadAuthority.php', '<?php namespace Fixture; class ReadAuthority { public function authorize(): void {} }')
        self.sql = '''CREATE TABLE `pa_grant` (`id` BIGINT UNSIGNED NOT NULL,
`source_tenant_id` BIGINT UNSIGNED NOT NULL, `recipient_tenant_id` BIGINT UNSIGNED NOT NULL,
`granted_by_member_id` BIGINT UNSIGNED NOT NULL,
CONSTRAINT `source` FOREIGN KEY (`source_tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
CONSTRAINT `recipient` FOREIGN KEY (`recipient_tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
CONSTRAINT `grantor` FOREIGN KEY (`source_tenant_id`, `granted_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
CONSTRAINT `different` CHECK (`source_tenant_id` <> `recipient_tenant_id`)
) ENGINE=InnoDB;'''
        self.write(self.schema,self.sql)
        self.contract = {**self.proof(self.base+'src/GrantService.php'), 'scope':'explicit-source-recipient-tenant',
                         'tenant_operations':['put','revoke'], 'other_operations':[], 'pending_operations':[],
                         'support_sources':[self.proof(self.base+'src/ReadAuthority.php')], 'reason':'Source writer and recipient query inspected.'}
        self.row = {'model':self.model,'table':'pa_grant','owner':'tenant-gateway','access_entry':self.entry,
                    'named_tenant_review':{'classification':'source-recipient-tenant','source_column':'source_tenant_id',
                    'recipient_column':'recipient_tenant_id','grantor_column':'granted_by_member_id',
                    'reason':'Source-owned grant; recipient read authority is independent.',
                    'model_source':self.proof(self.path),'schema_source':self.proof(self.schema),
                    'reader_source':{**self.proof(self.base+'src/ReadAuthority.php'),'entry':self.reader}}}
        self.ownership = {'access_contracts':{self.entry:self.contract}}

    def tearDown(self):
        checker.ROOT = self.old
        self.temp.cleanup()

    def write(self,path,text):
        target=self.root/path; target.parent.mkdir(parents=True,exist_ok=True); target.write_text(text)

    def proof(self,path):
        return {'source':'application','path':path,'sha256':hashlib.sha256((self.root/path).read_bytes()).hexdigest()}

    def errors(self):
        return checker.named_tenant_review_errors(self.row,self.root/self.path,self.ownership,{self.base.rstrip('/'):'fixture.example'})

    def replace_sql(self,sql):
        self.write(self.schema,sql);self.row['named_tenant_review']['schema_source']=self.proof(self.schema)

    def test_explicit_two_tenant_declaration_is_not_instance_or_shared(self):
        self.assertEqual([],self.errors())
        for label in ['instance','shared','tenant-orm']:
            self.row['owner']=label;self.assertTrue(self.errors())

    def test_same_or_renamed_columns_cannot_be_approved(self):
        self.row['named_tenant_review']['recipient_column']='source_tenant_id';self.assertTrue(self.errors())

    def test_nullable_source_or_removed_recipient_fk_is_rejected(self):
        self.replace_sql(self.sql.replace('`source_tenant_id` BIGINT UNSIGNED NOT NULL','`source_tenant_id` BIGINT UNSIGNED NULL'));self.assertTrue(self.errors())
        self.replace_sql(self.sql.replace('REFERENCES `pa_tenant`','REFERENCES `pa_account`'));self.assertTrue(self.errors())

    def test_cross_tenant_grantor_relation_cannot_be_rebound_by_hash(self):
        self.replace_sql(self.sql.replace('(`source_tenant_id`, `granted_by_member_id`)','(`recipient_tenant_id`, `granted_by_member_id`)'));self.assertTrue(self.errors())

    def test_missing_source_recipient_inequality_is_rejected(self):
        self.replace_sql(self.sql.replace('<>','='));self.assertTrue(self.errors())

    def test_comment_does_not_prove_a_foreign_key(self):
        self.replace_sql(self.sql.replace('CONSTRAINT `grantor`','-- CONSTRAINT `grantor`'));self.assertTrue(self.errors())

    def test_missing_or_unbound_reader_is_rejected(self):
        self.contract['support_sources']=[];self.assertTrue(self.errors())

    def test_changed_reader_and_wrong_case_require_review(self):
        self.row['named_tenant_review']['reader_source']['sha256']='0'*64;self.assertTrue(self.errors())

    def test_default_tenant_contract_does_not_approve_named_ownership(self):
        self.contract['scope']='explicit-tenant-column';self.assertTrue(self.errors())

    def test_missing_model_and_schema_identity_fail(self):
        self.row['named_tenant_review']['model_source']['sha256']='0'*64;self.assertTrue(self.errors())


if __name__=='__main__':
    unittest.main()
