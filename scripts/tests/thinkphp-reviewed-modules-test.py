"""Exercise reviewed module registrations against explicit immutable sources.

This inspects real source declarations and mutates only isolated source/registration fixtures.
It neither executes application PHP nor reads historical exception records.
"""
from __future__ import annotations

import argparse
from contextlib import ExitStack
import copy
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_reviewed_modules', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)

# Independent expectations, deliberately not generated from the registry or scanner.
EXPECTED = {
    'ArtifactRevision': ('artifact_revision', 'ThinkPhpArtifactRevisionRepository', {
        'ArtifactRecord': 'pa_artifact', 'ArtifactRevisionRecord': 'pa_artifact_revision',
    }),
    'EntitlementQuota': ('entitlement_quota', 'ThinkPhpEntitlementQuotaRepository', {
        'EntitlementGrantRecord': 'pa_entitlement_grant',
        'EntitlementPolicyRevisionRecord': 'pa_entitlement_policy_revision',
        'EntitlementReservationRecord': 'pa_entitlement_reservation',
        'EntitlementUsageLedgerRecord': 'pa_entitlement_usage_ledger',
        'EntitlementUsageWindowRecord': 'pa_entitlement_usage_window',
    }),
    'Workflow': ('workflow', 'ThinkPhpWorkflowRepository', {
        'WorkflowDefinitionRecord': 'pa_workflow_definition',
        'WorkflowDefinitionVersionRecord': 'pa_workflow_definition_version',
        'WorkflowInstanceRecord': 'pa_workflow_instance',
        'WorkflowWorkItemRecord': 'pa_workflow_work_item',
        'WorkflowEventRecord': 'pa_workflow_event',
    }),
}
OPTIONS = None


class ReviewedModulesTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.stack = ExitStack()
        cls.addClassCleanup(cls.stack.close)
        cls.original = checker.read_ownership()
        cls.stack.enter_context(checker.php_core_snapshot(OPTIONS.php_core_root, OPTIONS.php_core_ref))
        cls.stack.enter_context(checker.ownership_source_snapshot(OPTIONS.source_ref))
        cls.original_ownership = checker.OWNERSHIP
        cls.original_history = checker.REGISTER
        parent = ROOT / '.local/tmp/tpq-reviewed-modules'
        parent.mkdir(parents=True, exist_ok=True)
        cls.temp = cls.stack.enter_context(tempfile.TemporaryDirectory(dir=parent))
        checker.OWNERSHIP = Path(cls.temp) / 'ownership.json'
        checker.REGISTER = Path(cls.temp) / 'history-not-present.json'
        cls.addClassCleanup(cls.restore)

    @classmethod
    def restore(cls):
        checker.OWNERSHIP = cls.original_ownership
        checker.REGISTER = cls.original_history

    def setUp(self):
        self.registry = copy.deepcopy(self.original)
        self.save()

    def save(self):
        checker.OWNERSHIP.write_text(json.dumps(self.registry), encoding='utf-8')

    def report(self):
        return checker.current_ownership_report(OPTIONS.source_ref)

    def test_all_twelve_records_match_native_model_table_and_module(self):
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        owners, _ = checker.module_table_inventory()
        for name, (slug, repository, expected) in EXPECTED.items():
            entry = f'PeanutAdmin\\Modules\\{name}\\Persistence\\{repository}'
            for model, table in expected.items():
                with self.subTest(model=model):
                    fqcn = f'PeanutAdmin\\Modules\\{name}\\Persistence\\Model\\{model}'
                    self.assertIn(fqcn, models)
                    self.assertEqual('tenant-gateway', models[fqcn]['owner'])
                    self.assertEqual(table, models[fqcn]['table'])
                    self.assertEqual(entry, models[fqcn]['access_entry'])
                    self.assertEqual(entry, tables[table]['access_entry'])
                    self.assertEqual('peanut.' + slug.replace('_', '-'), owners[table])
                    path = checker.composer_model_path(fqcn)
                    self.assertEqual((fqcn, 'PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'), checker.model_header(path))
                    self.assertEqual(table, checker.source_model_table(path))

    def test_gateways_and_scoped_dependencies_match_reviewed_bytes(self):
        contracts = {f'PeanutAdmin\\Modules\\{name}\\Persistence\\{values[1]}': self.registry['access_contracts'][f'PeanutAdmin\\Modules\\{name}\\Persistence\\{values[1]}'] for name, values in EXPECTED.items()}
        self.assertEqual([], checker.access_contract_errors(contracts))
        for contract in contracts.values():
            self.assertEqual('explicit-tenant-column', contract['scope'])
            self.assertFalse(contract['pending_operations'])
            self.assertFalse(contract['other_operations'])
            self.assertTrue(any(x['path'].endswith('/Tenancy/TenantScope.php') for x in contract['support_sources']))

    def test_registered_modules_do_not_turn_pending_scope_into_a_pass(self):
        # A synthetic pending classification must remain visible even after all
        # unrelated real backlog items have eventually been registered.
        entry = 'PeanutAdmin\\Modules\\Workflow\\Persistence\\ThinkPhpWorkflowRepository'
        contract = self.registry['access_contracts'][entry]
        contract['tenant_operations'].remove('events')
        contract['pending_operations'].append('events')
        self.save()
        report = self.report()
        self.assertIn(report['status'], {'registration_failed', 'decision_required', 'check_incomplete'})
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])
        self.assertFalse(report['historical_register_read'])
        self.assertTrue(any(item['entry'] == entry and item['operations'] == ['events'] for item in report['needs_decision']))
        selected = {f'PeanutAdmin\\Modules\\{name}\\Persistence\\Model\\{model}' for name, (_, _, models) in EXPECTED.items() for model in models}
        self.assertFalse(selected.intersection(report['missing_models']))

    def test_deleting_one_model_is_a_missing_registration_not_a_pass(self):
        model = 'PeanutAdmin\\Modules\\Workflow\\Persistence\\Model\\WorkflowEventRecord'
        self.registry['model_owners'] = [r for r in self.registry['model_owners'] if r['model'] != model]
        self.save()
        self.assertIn(model, self.report()['missing_models'])

    def test_foreign_table_assignment_is_rejected(self):
        row = next(r for r in self.registry['model_owners'] if r['table'] == 'pa_artifact')
        row['table'] = 'pa_workflow_instance'
        self.save()
        self.assertTrue(any('table declaration mismatch' in e for e in checker.ownership_errors()))
        self.assertTrue(any('module table owner mismatch' in e for e in checker.ownership_errors()))

    def test_foreign_gateway_is_not_a_same_module_access_entry(self):
        row = next(r for r in self.registry['model_owners'] if r['table'] == 'pa_artifact')
        row['access_entry'] = 'PeanutAdmin\\Modules\\Workflow\\Persistence\\ThinkPhpWorkflowRepository'
        self.save()
        self.assertTrue(any('ACCESS_MODULE_OWNER' in e for e in checker.ownership_errors()))

    def test_scope_review_digest_tampering_invalidates_registration(self):
        entry = 'PeanutAdmin\\Modules\\ArtifactRevision\\Persistence\\ThinkPhpArtifactRevisionRepository'
        self.registry['access_contracts'][entry]['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in e for e in checker.ownership_errors()))

    def test_notification_six_records_have_exact_private_owner_and_table(self):
        expected = {
            'NotificationTemplateRecord': 'pa_notification_template',
            'NotificationMessageRecord': 'pa_notification_message',
            'NotificationAttachmentRecord': 'pa_notification_attachment',
            'NotificationOutboxRecord': 'pa_notification_outbox',
            'NotificationEventRecord': 'pa_notification_event',
            'SmsRateBucketRecord': 'pa_sms_rate_bucket',
        }
        entry = 'PeanutAdmin\\Modules\\Notification\\Delivery\\Persistence\\NotificationStore'
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        native, _ = checker.module_table_inventory()
        self.assertNotIn(entry, checker.declared_module_exports())
        for name, table in expected.items():
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Notification\\Delivery\\Persistence\\Model\\' + name
                self.assertEqual(table, models[model]['table'])
                self.assertEqual('tenant-gateway', models[model]['owner'])
                self.assertEqual(entry, models[model]['access_entry'])
                self.assertEqual(entry, tables[table]['access_entry'])
                self.assertEqual('official.notification', native[table])
                self.assertEqual((model, 'PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'), checker.model_header(checker.composer_model_path(model)))

    def test_notification_review_includes_recipient_and_leased_worker_callers(self):
        entry = 'PeanutAdmin\\Modules\\Notification\\Delivery\\Persistence\\NotificationStore'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual([], checker.access_contract_errors({entry: contract}))
        paths = {Path(item['path']).name for item in contract['support_sources']}
        self.assertTrue({'NotificationService.php', 'NotificationInboxService.php', 'SmsTaskHandler.php', 'InboxTaskHandler.php'}.issubset(paths))
        self.assertFalse(contract['other_operations'])
        self.assertFalse(contract['pending_operations'])

    def test_notification_explicit_predicates_are_not_automatic_orm_scope(self):
        row = next(item for item in self.registry['model_owners'] if item['table'] == 'pa_notification_message')
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and 'NotificationMessageRecord' in error for error in checker.ownership_errors()))

    def test_worker_review_drift_requires_revalidation(self):
        entry = 'PeanutAdmin\\Modules\\Notification\\Delivery\\Persistence\\NotificationStore'
        dependency = next(item for item in self.registry['access_contracts'][entry]['support_sources'] if item['path'].endswith('/SmsTaskHandler.php'))
        dependency['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_integration_five_models_match_native_schema_and_private_owner(self):
        expected = {
            'IntegrationMachineIdentityRecord': 'pa_integration_machine_identity',
            'IntegrationWebhookEndpointRecord': 'pa_integration_webhook_endpoint',
            'IntegrationWebhookDeliveryRecord': 'pa_integration_webhook_delivery',
            'IntegrationWebhookAttemptRecord': 'pa_integration_webhook_attempt',
            'IntegrationSecurityEventRecord': 'pa_integration_security_event',
        }
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        native, _ = checker.module_table_inventory()
        declarations = checker.module_php_schema_inventory()
        self.assertFalse(declarations['gaps'])
        parsed_tables = {row['table'] for row in declarations['records']}
        self.assertNotIn(entry, checker.declared_module_exports())
        self.assertNotIn('PeanutAdmin\\Modules\\Integration\\Contract\\IntegrationSecurityRepository', checker.declared_module_exports())
        for name, table in expected.items():
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Integration\\Model\\' + name
                self.assertEqual(table, models[model]['table'])
                self.assertEqual('tenant-gateway', models[model]['owner'])
                self.assertEqual(entry, models[model]['access_entry'])
                self.assertEqual(entry, tables[table]['access_entry'])
                self.assertEqual('official.integration', native[table])
                self.assertIn(table, parsed_tables)
                self.assertEqual((model, 'PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'), checker.model_header(checker.composer_model_path(model)))

    def test_integration_authentication_and_system_methods_are_not_tenant_authority(self):
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual([], checker.access_contract_errors({entry: contract}))
        self.assertEqual({'machineByDigest', 'touchMachine', 'purgeExpiredDeliveryData'}, set(contract['other_operations']))
        self.assertEqual([], contract['pending_operations'])
        self.assertTrue({'createMachine', 'rotateMachine', 'enqueueDelivery', 'claimDelivery', 'deliveryRecords'}.issubset(contract['tenant_operations']))
        report = self.report()
        self.assertFalse(any(item['entry'] == entry for item in report['needs_decision']))
        self.assertFalse(report['needs_decision'])
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_integration_scope_review_includes_actual_identity_and_transaction_callers(self):
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        paths = {Path(item['path']).name for item in self.registry['access_contracts'][entry]['support_sources']}
        self.assertTrue({'MachineIdentityService.php', 'MachineScopeGrantPolicy.php', 'IntegrationAdminApplicationService.php',
                         'WebhookService.php', 'WebhookDeliveryLogService.php', 'TrustedWebhookPublisher.php',
                         'WebhookDispatcher.php', 'Schema.php', 'ModuleProvider.php', 'TenantModel.php'}.issubset(paths))

    def test_integration_authentication_caller_drift_invalidates_scope_review(self):
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        caller = next(item for item in self.registry['access_contracts'][entry]['support_sources']
                      if item['path'].endswith('/Application/MachineIdentityService.php'))
        caller['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_integration_explicit_tenant_data_is_not_global_orm_scope(self):
        row = next(item for item in self.registry['model_owners'] if item['table'] == 'pa_integration_machine_identity')
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and 'IntegrationMachineIdentityRecord' in error
                            for error in checker.ownership_errors()))

    def test_settings_edition_models_match_owner_access_instead_of_catalog_mutation(self):
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        native, _ = checker.module_table_inventory()
        gateway = 'PeanutAdmin\\Modules\\Settings\\Application\\SettingAdminService'
        for name, table in [('TenantSettingValue', 'pa_setting_tenant_value'), ('TargetSettingValue', 'pa_setting_target_value')]:
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Settings\\Model\\' + name
                self.assertEqual(table, models[model]['table'])
                self.assertEqual('tenant-gateway', models[model]['owner'])
                self.assertEqual(gateway, models[model]['access_entry'])
                self.assertEqual(gateway, tables[table]['access_entry'])
                self.assertEqual('official.settings', native[table])
                self.assertEqual((model, 'PeanutAdmin\\Kernel\\Persistence\\Model\\EditionTenantModel'), checker.model_header(checker.composer_model_path(model)))
                self.assertIn(table, checker.schema_tenant_tables())
        self.assertNotIn(gateway, checker.declared_module_exports())

    def test_settings_deployment_and_helper_methods_are_not_tenant_authority(self):
        entry = 'PeanutAdmin\\Modules\\Settings\\Application\\SettingAdminService'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual([], checker.access_contract_errors({entry: contract}))
        self.assertEqual({'replaceTenant', 'unsetTenant', 'replaceTarget', 'unsetTarget'}, set(contract['tenant_operations']))
        self.assertEqual({'replaceDeployment', 'unsetDeployment', 'prepareStorage', 'assertValidInterval', 'emptyStorage'}, set(contract['other_operations']))
        self.assertFalse(contract['pending_operations'])
        resolver = 'PeanutAdmin\\Modules\\Settings\\Application\\SettingResolver'
        self.assertEqual([], checker.access_contract_errors({resolver: self.registry['access_contracts'][resolver]}))
        self.assertEqual({'resolveTenant', 'resolveTarget'}, set(self.registry['access_contracts'][resolver]['tenant_operations']))
        self.assertEqual(['resolveDeployment'], self.registry['access_contracts'][resolver]['other_operations'])

    def test_settings_scope_review_pins_effective_mode_target_and_http_authority(self):
        entry = 'PeanutAdmin\\Modules\\Settings\\Application\\SettingAdminService'
        support = self.registry['access_contracts'][entry]['support_sources']
        self.assertTrue({'EditionTenantModel.php', 'TenantColumnScope.php', 'SettingResolver.php',
                         'TargetSettingWriter.php', 'SettingsHttpApplicationService.php', 'ModuleProvider.php'}
                        .issubset({Path(item['path']).name for item in support}))
        caller = next(item for item in support if item['path'].endswith('/SettingsHttpApplicationService.php'))
        caller['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_settings_explicit_edition_scope_is_not_automatic_global_scope(self):
        model = 'PeanutAdmin\\Modules\\Settings\\Model\\TenantSettingValue'
        row = next(item for item in self.registry['model_owners'] if item['model'] == model)
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and model in error for error in checker.ownership_errors()))

    def test_settings_native_instance_models_have_bound_evidence(self):
        models = {row['model']: row for row in self.registry['model_owners']}
        tenant_tables = checker.schema_tenant_tables()
        _, roots = checker.module_table_inventory()
        for name, table in [('DeploymentSettingValue', 'pa_setting_deployment_value'), ('SettingDefinitionRecord', 'pa_setting_definition')]:
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Settings\\Model\\' + name
                row = models[model]
                path = checker.composer_model_path(model)
                self.assertEqual('instance', row['owner'])
                self.assertEqual(table, row['table'])
                self.assertEqual((model, 'think\\Model'), checker.model_header(path))
                self.assertNotIn(table, tenant_tables)
                self.assertEqual([], checker.instance_model_review_errors(row, path, tenant_tables, roots))
                self.assertNotIn(model, checker.declared_module_exports())
        report = self.report()
        self.assertFalse(any(model in report['missing_models'] for model in models if model.endswith(('DeploymentSettingValue', 'SettingDefinitionRecord'))))
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_removing_native_instance_review_does_not_grant_a_base_class_exemption(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_setting_deployment_value')
        del row['instance_review']
        self.save()
        self.assertTrue(any('INSTANCE_REVIEW_REQUIRED' in error for error in checker.ownership_errors()))

    def test_changed_instance_catalog_caller_requires_review(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_setting_definition')
        evidence = next(item for item in row['instance_review']['access_sources'] if item['entry'].endswith('SettingCatalogService'))
        evidence['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_reference_code_set_is_a_deployment_catalog_not_tenant_values(self):
        model = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeSetRecord'
        row = next(row for row in self.registry['model_owners'] if row['model'] == model)
        path = checker.composer_model_path(model)
        tables = checker.schema_tenant_tables()
        owners, roots = checker.module_table_inventory()
        self.assertEqual('instance', row['owner'])
        self.assertEqual('pa_reference_code_set', row['table'])
        self.assertEqual('official.reference-codes', owners[row['table']])
        self.assertNotIn(row['table'], tables)
        self.assertNotIn(model, checker.declared_module_exports())
        self.assertEqual([], checker.instance_model_review_errors(row, path, tables, roots))
        report = self.report()
        self.assertNotIn(model, report['missing_models'])
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_reference_version_without_tenant_column_cannot_borrow_catalog_review(self):
        catalog = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_set')
        item = copy.deepcopy(catalog)
        item['model'] = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeEntryVersionRecord'
        item['table'] = 'pa_reference_code_entry_version'
        path = checker.composer_model_path(item['model'])
        import hashlib
        item['instance_review']['model_source'] = {
            'source': 'application', 'path': checker.relative(path), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
        }
        _, roots = checker.module_table_inventory()
        self.assertTrue(any('INSTANCE_SCHEMA_TENANT_PARENT' in error for error in
                            checker.instance_model_review_errors(item, path, checker.schema_tenant_tables(), roots)))

    def test_changed_reference_catalog_source_does_not_keep_instance_review_valid(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_set')
        source = next(source for source in row['instance_review']['access_sources'] if source['entry'].endswith('ReferenceCodeCatalogService'))
        source['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_identity_authorization_catalogs_have_native_instance_provenance(self):
        expected = {
            'ProtectedResourceRecord': 'pa_protected_resource', 'TargetTypeRecord': 'pa_target_type',
            'ResourceOperationRecord': 'pa_resource_operation',
            'ResourceOperationTargetTypeRecord': 'pa_resource_operation_target_type',
            'ResourceOperationPermissionRecord': 'pa_resource_operation_permission',
            'DataConditionDefinitionRecord': 'pa_data_condition_definition',
            'ResourceOperationConditionRecord': 'pa_resource_operation_condition',
        }
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = checker.schema_tenant_tables()
        owners, roots = checker.module_table_inventory()
        for name, table in expected.items():
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Identity\\Authorization\\Model\\' + name
                item = models[model]
                self.assertEqual((table, 'instance'), (item['table'], item['owner']))
                self.assertEqual('official.identity', owners[table])
                self.assertNotIn(model, checker.declared_module_exports())
                self.assertEqual([], checker.instance_model_review_errors(item, checker.composer_model_path(model), tables, roots))
                self.assertEqual('PeanutAdmin\\Modules\\Identity\\Authorization\\Persistence\\Schema\\AuthorizationSchema', item['instance_review']['schema_source']['entry'])
                self.assertTrue(item['instance_review']['schema_source']['migration_sources'])

    def test_changed_native_catalog_migration_requires_reverification(self):
        item = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_protected_resource')
        item['instance_review']['schema_source']['migration_sources'][0]['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('INSTANCE_SCHEMA_TRACE' in error for error in checker.ownership_errors()))

    def test_authorization_catalog_metadata_does_not_imply_tenant_grants(self):
        item = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_data_condition_definition')
        item['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error for error in checker.ownership_errors()))
        report = self.report()
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_reference_entry_uses_its_private_scoped_store(self):
        model = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeEntryRecord'
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        row = next(item for item in self.registry['model_owners'] if item['model'] == model)
        table = next(item for item in self.registry['tenant_tables'] if item['table'] == 'pa_reference_code_entry')
        self.assertEqual(('pa_reference_code_entry', 'tenant-gateway', entry), (row['table'], row['owner'], row['access_entry']))
        self.assertEqual(entry, table['access_entry'])
        self.assertEqual((model, 'PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'), checker.model_header(checker.composer_model_path(model)))
        self.assertNotIn(entry, checker.declared_module_exports())
        self.assertEqual([], checker.access_contract_errors({entry: self.registry['access_contracts'][entry]}))
        self.assertNotIn(model, self.report()['missing_models'])

    def test_reference_revalidated_snapshot_pins_both_membership_boundaries(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual({'create', 'replace', 'retire', 'snapshot', 'pageSnapshot'}, set(contract['tenant_operations']))
        self.assertEqual({'atomically', 'synchronize', 'assertCurrentDefinition', 'definitionSummaries'}, set(contract['other_operations']))
        self.assertFalse(contract['pending_operations'])
        paths = {Path(item['path']).name for item in contract['support_sources']}
        self.assertTrue({'AdminDirectoryQuery.php', 'TenantMemberDirectory.php', 'ThinkPhpTenantMemberDirectory.php',
                         'ModuleProvider.php', 'ReferenceCodesHttpApplicationService.php', 'ReferenceCodeAdminService.php',
                         'ReferenceCodeQuery.php'}.issubset(paths))
        source, _ = checker.reviewed_source(contract)
        self.assertIn('function assertMemberReferences(', checker.php_structure(source.read_text()))
        self.assertIn('function referenceMemberId(', checker.php_structure(source.read_text()))
        self.assertIn('PeanutAdmin\\Modules\\Identity\\Contract\\AdminDirectoryQuery', checker.declared_module_exports())

    def test_reference_membership_dependency_drift_is_not_auto_approved(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        proof = next(item for item in contract['support_sources'] if item['path'].endswith('/AdminDirectoryQuery.php'))
        proof['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_reference_tenant_entry_is_not_automatic_global_scope(self):
        row = next(item for item in self.registry['model_owners'] if item['table'] == 'pa_reference_code_entry')
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and 'ReferenceCodeEntryRecord' in error
                            for error in checker.ownership_errors()))

    def test_reference_snapshot_cannot_disappear_from_method_roles(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        contract['tenant_operations'].remove('snapshot')
        self.assertTrue(any('ACCESS_METHOD_COVERAGE' in error for error in checker.access_contract_errors({entry: contract})))

    def test_reference_version_belongs_through_exact_scoped_parent(self):
        child_name = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeEntryVersionRecord'
        child = next(row for row in self.registry['model_owners'] if row['model'] == child_name)
        parent_name = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeEntryRecord'
        self.assertEqual('tenant-gateway', child['owner'])
        self.assertEqual(parent_name, child['parent_review']['parent_model'])
        self.assertEqual(('entry_id', 'id'), (child['parent_review']['column'], child['parent_review']['parent_column']))
        tables = checker.schema_tenant_tables()
        self.assertNotIn(child['table'], tables)
        self.assertFalse(any(row['table'] == child['table'] for row in self.registry['tenant_tables']))
        _, roots = checker.module_table_inventory()
        self.assertEqual([], checker.parent_model_review_errors(child, checker.composer_model_path(child_name), self.registry, tables, roots))
        self.assertNotIn(child_name, self.report()['missing_models'])

    def test_reference_version_relation_cannot_be_repointed_to_deployment_catalog(self):
        child = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_entry_version')
        child['parent_review']['parent_model'] = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeSetRecord'
        self.save()
        self.assertTrue(any('PARENT_SCOPED_REGISTRATION_REQUIRED' in error for error in checker.ownership_errors()))

    def test_reference_version_cannot_be_counted_as_an_additional_direct_tenant_table(self):
        child = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_entry_version')
        self.registry['tenant_tables'].append({'table': child['table'], 'owner': 'tenant-gateway', 'access_entry': child['access_entry']})
        self.save()
        self.assertTrue(any('PARENT_CHILD_IS_NOT_A_DIRECT_TENANT_TABLE' in error for error in checker.ownership_errors()))

    def test_reference_version_changed_parent_source_invalidates_ownership(self):
        child = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_entry_version')
        child['parent_review']['parent_source']['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_reference_page_role_is_required_for_parent_and_gateway(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        child = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_entry_version')
        self.assertIn('pageSnapshot', contract['tenant_operations'])
        self.assertIn('pageSnapshot', child['parent_review']['operations'])
        child['parent_review']['operations'].remove('pageSnapshot')
        self.save()
        self.assertTrue(any('PARENT_OPERATION_REVIEW_REQUIRED' in error for error in checker.ownership_errors()))

    def test_reference_page_validation_caller_drift_requires_new_review(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        caller = next(row for row in contract['support_sources'] if row['path'].endswith('/ReferenceCodeQuery.php'))
        source, _ = checker.reviewed_source(caller)
        self.assertIn('->pageSnapshot(', source.read_text())
        self.assertIn('$this->hydrate($definition, $raw, $instant)', source.read_text())
        caller['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_identity_scoped_business_entries_keep_models_private(self):
        prefix = 'PeanutAdmin\\Modules\\Identity\\'
        expected = {
            'Persistence\\Model\\Department': ('pa_department', 'Organization\\Application\\DepartmentAdminService'),
            'Persistence\\Model\\Role': ('pa_role', 'Authorization\\Application\\RoleAdminService'),
            'Persistence\\Model\\RolePermission': ('pa_role_permission', 'Authorization\\Application\\RoleAdminService'),
            'Persistence\\Model\\TenantMember': ('pa_tenant_member', 'Membership\\Application\\MemberAdminService'),
            'Persistence\\Model\\MemberRole': ('pa_member_role', 'Membership\\Application\\MemberAdminService'),
            'DataPermission\\Model\\DataPermissionPolicyRecord': ('pa_data_permission_policy', 'DataPermission\\Application\\DataPolicyAdminService'),
            'DataPermission\\Model\\DataPermissionGroupRecord': ('pa_data_permission_group', 'DataPermission\\Application\\DataPolicyAdminService'),
            'DataPermission\\Model\\DataPermissionConditionRecord': ('pa_data_permission_condition', 'DataPermission\\Application\\DataPolicyAdminService'),
            'DataPermission\\Model\\DataPermissionTargetSetRecord': ('pa_data_permission_target_set', 'DataPermission\\Application\\DataPolicyAdminService'),
            'DataPermission\\Model\\DataPermissionTargetRecord': ('pa_data_permission_target', 'DataPermission\\Application\\DataPolicyAdminService'),
        }
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        exports = checker.declared_module_exports()
        for suffix, (table, service) in expected.items():
            with self.subTest(model=suffix):
                model, entry = prefix + suffix, prefix + service
                self.assertEqual(('tenant-gateway', table, entry), (models[model]['owner'], models[model]['table'], models[model]['access_entry']))
                self.assertEqual(entry, tables[table]['access_entry'])
                self.assertNotIn(model, exports)
                self.assertIn(entry, exports)
                self.assertEqual([], checker.access_contract_errors({entry: self.registry['access_contracts'][entry]}))

    def test_identity_exported_entry_needs_individual_surface_not_directory_exemption(self):
        entry = 'PeanutAdmin\\Modules\\Identity\\Authorization\\Application\\RoleAdminService'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual({'list', 'get', 'create', 'update', 'archive', 'replacePermissions'}, set(contract['public_use_case']['method_results']))
        del contract['public_use_case']
        self.assertTrue(any('ACCESS_PERSISTENCE_EXPORTED' in error for error in checker.access_contract_errors({entry: contract})))

    def test_identity_public_entry_and_its_assembly_drift_require_review(self):
        entry = 'PeanutAdmin\\Modules\\Identity\\Membership\\Application\\MemberAdminService'
        contract = self.registry['access_contracts'][entry]
        source = next(row for row in contract['support_sources'] if row['path'] == 'server/app/AppService.php')
        source['sha256'] = '0' * 64
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.access_contract_errors({entry: contract})))

    def test_identity_data_policy_scope_cannot_be_inferred_from_tenant_base_alone(self):
        model = 'PeanutAdmin\\Modules\\Identity\\DataPermission\\Model\\DataPermissionPolicyRecord'
        row = next(row for row in self.registry['model_owners'] if row['model'] == model)
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and model in error for error in checker.ownership_errors()))

    def test_identity_instance_records_bind_real_schema_and_private_models(self):
        prefix = 'PeanutAdmin\\Modules\\Identity\\'
        selected = {
            'Account': 'pa_account', 'Credential': 'pa_credential', 'AuthSecurityEvent': 'pa_auth_security_event',
            'LoginChallenge': 'pa_login_challenge', 'Tenant': 'pa_tenant', 'Permission': 'pa_permission',
            'MenuDefinition': 'pa_menu_definition', 'PlatformOperator': 'pa_platform_operator',
            'PlatformOperatorRole': 'pa_platform_operator_role', 'PlatformRole': 'pa_platform_role',
            'PlatformRolePermission': 'pa_platform_role_permission', 'PlatformSession': 'pa_platform_session',
            'PlatformSessionToken': 'pa_platform_session_token',
        }
        models = {row['model']: row for row in self.registry['model_owners']}
        native = {prefix + 'Persistence\\Model\\' + name: table for name, table in selected.items()}
        native[prefix + 'Module\\Model\\ModuleInstallation'] = 'pa_module_installation'
        native[prefix + 'Audit\\Model\\PlatformAuditEventRecord'] = 'pa_platform_audit_event'
        tables = checker.schema_tenant_tables()
        _, roots = checker.module_table_inventory()
        for model, table in native.items():
            with self.subTest(model=model):
                row = models[model]
                self.assertEqual(('instance', table), (row['owner'], row['table']))
                self.assertNotIn(table, tables)
                self.assertNotIn(model, checker.declared_module_exports())
                self.assertEqual([], checker.instance_model_review_errors(row, checker.composer_model_path(model), tables, roots))
        missing = self.report()['missing_models']
        self.assertTrue(set(native).isdisjoint(missing))

    def test_platform_session_token_is_not_a_tenant_session_token(self):
        prefix = 'PeanutAdmin\\Modules\\Identity\\Persistence\\Model\\'
        row = copy.deepcopy(next(row for row in self.registry['model_owners'] if row['model'] == prefix + 'PlatformSessionToken'))
        row['model'] = prefix + 'TenantSessionToken'
        row['table'] = 'pa_tenant_session_token'
        path = checker.composer_model_path(row['model'])
        import hashlib
        row['instance_review']['model_source'] = {'source': 'application', 'path': checker.relative(path), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
        row['instance_review']['schema_source']['migration_sources'] = [
            {'path': entry['migration'], 'sha256': entry['migration_sha256']}
            for entry in checker.host_php_schema_inventory()['records'] if entry['table'] == row['table']
        ]
        _, roots = checker.module_table_inventory()
        self.assertTrue(any('INSTANCE_SCHEMA_TENANT_PARENT' in error for error in checker.instance_model_review_errors(row, path, checker.schema_tenant_tables(), roots)))

    def test_identity_initial_catalog_declaration_cannot_be_replaced_by_seed_identity(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_menu_definition')
        row['instance_review']['schema_source']['declaration_sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('INITIAL_SCHEMA_DECLARATION_MISSING_OR_CHANGED' in error for error in checker.ownership_errors()))

    def test_identity_undeclared_table_owners_remain_real_registration_errors(self):
        owners, _ = checker.module_table_inventory()
        errors = checker.ownership_errors()
        for table, model in [('pa_module_installation', 'ModuleInstallation'), ('pa_menu_definition', 'MenuDefinition')]:
            if owners.get(table) is None:
                self.assertTrue(any(model in error and 'module table owner mismatch' in error for error in errors))
            else:
                self.assertEqual('official.identity', owners[table])
        report = self.report()
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_identity_remaining_records_keep_distinct_scope_sources(self):
        prefix = 'PeanutAdmin\\Modules\\Identity\\'
        mapping = {
            'Audit\\Model\\TenantAuditEventRecord': ('pa_tenant_audit_event', 'Audit\\AuditService'),
            'Module\\Model\\TenantModule': ('pa_tenant_module', 'Module\\Persistence\\ThinkPhpModuleRuntimeRepository'),
            'Persistence\\Model\\TenantEntryBinding': ('pa_tenant_entry_binding', 'Platform\\Application\\TenantEntryBindingAdminService'),
            'Persistence\\Model\\TenantSession': ('pa_tenant_session', 'Auth\\Persistence\\ThinkPhpTenantAuthRepository'),
            'Persistence\\Model\\TenantSessionToken': ('pa_tenant_session_token', 'Auth\\Persistence\\ThinkPhpTenantAuthRepository'),
            'access\\SourceRead\\Model\\SourceReadGrantRecord': ('pa_source_read_grant', 'access\\SourceRead\\SourceReadGrantAdministrationService'),
        }
        rows = {row['model']: row for row in self.registry['model_owners']}
        for suffix, (table, service) in mapping.items():
            row = rows[prefix + suffix]
            self.assertEqual(('tenant-gateway', table, prefix + service), (row['owner'], row['table'], row['access_entry']))
            self.assertEqual([], checker.access_contract_errors({row['access_entry']: self.registry['access_contracts'][row['access_entry']]}))
        report = self.report()
        self.assertEqual([], report['missing_models'])
        self.assertEqual([], report['missing_tenant_tables'])
        self.assertFalse(report['needs_decision'])
        self.assertEqual('not_run', report['business_scan'])

    def test_identity_source_grant_is_not_an_ordinary_global_scope(self):
        model = 'PeanutAdmin\\Modules\\Identity\\access\\SourceRead\\Model\\SourceReadGrantRecord'
        row = next(row for row in self.registry['model_owners'] if row['model'] == model)
        self.assertEqual('source_tenant_id', row['named_tenant_review']['source_column'])
        self.assertEqual('recipient_tenant_id', row['named_tenant_review']['recipient_column'])
        del row['named_tenant_review']
        self.save()
        self.assertTrue(any(model in error and 'must extend' in error for error in checker.ownership_errors()))

    def test_identity_session_token_parent_provenance_rejects_foreign_domain(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_tenant_session_token')
        self.assertEqual('PeanutAdmin\\Modules\\Identity\\Persistence\\Model\\TenantSession', row['parent_review']['parent_model'])
        row['parent_review']['parent_model'] = 'PeanutAdmin\\Modules\\Identity\\Persistence\\Model\\PlatformSession'
        self.save()
        self.assertTrue(any('PARENT_SCOPED_REGISTRATION_REQUIRED' in error for error in checker.ownership_errors()))

    def test_identity_audit_technical_interface_does_not_remove_public_review(self):
        entry = 'PeanutAdmin\\Modules\\Identity\\Audit\\AuditService'
        proof = self.registry['access_contracts'][entry]['public_use_case']
        self.assertEqual(['PeanutAdmin\\Kernel\\Audit\\AuditWriter'], [row['entry'] for row in proof['interfaces']])
        proof['interfaces'] = []
        self.assertTrue(any('PUBLIC_USE_CASE_INTERFACE_REVIEW_REQUIRED' in error for error in checker.access_contract_errors({entry:self.registry['access_contracts'][entry]})))

    def test_implemented_retention_is_separate_from_tenant_authority(self):
        entries = {
            'PeanutAdmin\\Modules\\ImportExport\\Engine\\Persistence\\ImportExportStore': ('expireDue', 'ExpireOperationsCommand.php'),
            'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository': ('purgeExpiredDeliveryData', 'PurgeExpiredDeliveriesCommand.php'),
        }
        for entry, (method, command) in entries.items():
            with self.subTest(entry=entry):
                contract = self.registry['access_contracts'][entry]
                self.assertNotIn(method, contract['tenant_operations'])
                self.assertIn(method, contract['other_operations'])
                self.assertNotIn(method, contract['pending_operations'])
                self.assertEqual([], checker.access_contract_errors({entry: contract}))
                sources = {Path(row['path']).name for row in contract['support_sources']}
                self.assertTrue({command, 'ContextualCommand.php', 'CurrentExecutionContext.php',
                                 'ExecutionContextStore.php', 'InstanceExecutionContext.php', 'AuditContractHost.php',
                                 'ModuleProvider.php'}.issubset(sources))
                path, _ = checker.reviewed_source(contract)
                self.assertIn('PLATFORM_MAINTENANCE_CONTEXT_REQUIRED', path.read_text())
                self.assertIn('PHP_SAPI', path.read_text())

    def test_retention_context_source_change_invalidates_review(self):
        entry = 'PeanutAdmin\\Modules\\ImportExport\\Engine\\Persistence\\ImportExportStore'
        contract = self.registry['access_contracts'][entry]
        source = next(row for row in contract['support_sources'] if row['path'].endswith('/ContextualCommand.php'))
        source['sha256'] = '0' * 64
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.access_contract_errors({entry: contract})))

    def test_retention_method_cannot_disappear_from_all_roles(self):
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        contract = self.registry['access_contracts'][entry]
        contract['other_operations'].remove('purgeExpiredDeliveryData')
        self.assertTrue(any('ACCESS_METHOD_COVERAGE' in error for error in checker.access_contract_errors({entry: contract})))

    def test_identity_delta_catalog_owners_match_existing_private_models(self):
        owners, _ = checker.module_table_inventory()
        for table in ('pa_module_installation', 'pa_menu_definition'):
            row = next(row for row in self.registry['model_owners'] if row['table'] == table)
            self.assertEqual('official.identity', owners.get(table))
            self.assertEqual('instance', row['owner'])
            path = checker.composer_model_path(row['model'])
            self.assertEqual(table, checker.source_model_table(path))
            self.assertEqual((row['model'], 'think\\Model'), checker.model_header(path))
            self.assertNotIn(row['model'], checker.declared_module_exports())

    def test_identity_delta_owner_removal_is_not_hidden_by_registration(self):
        # Only mutate the immutable-source fixture's one manifest, never A/B source.
        path = checker.ROOT / 'server/app/modules/official/identity/module.json'
        original = path.read_bytes()
        try:
            for table in ('pa_module_installation', 'pa_menu_definition'):
                manifest = json.loads(original)
                self.assertIn(table, manifest['database']['owned_tables'])
                manifest['database']['owned_tables'].remove(table)
                path.write_text(json.dumps(manifest), encoding='utf-8')
                owners, _ = checker.module_table_inventory()
                self.assertIsNone(owners.get(table))
                self.assertTrue(any(row['table'] == table for row in self.registry['model_owners']))
        finally:
            path.write_bytes(original)
        owners, _ = checker.module_table_inventory()
        self.assertEqual(['official.identity', 'official.identity'],
                         [owners.get(table) for table in ('pa_module_installation', 'pa_menu_definition')])

    def test_identity_delta_catalog_entries_bind_new_method_sources(self):
        prefix = 'PeanutAdmin\\Modules\\Identity\\'
        expected = {
            'pa_module_installation': {
                prefix + 'Authorization\\CatalogLifecycleService': {'registerDeployedManifest'},
                prefix + 'Contract\\TenantModuleStateQueries': {'installationIdentity', 'activeInstallationKeys', 'activeInstallationMetadata'},
            },
            'pa_menu_definition': {prefix + 'Menu\\MenuCatalogSynchronizer': {'activeKeysOutsideModules'}},
        }
        for table, entries in expected.items():
            row = next(row for row in self.registry['model_owners'] if row['table'] == table)
            sources = {source['entry']: source for source in row['instance_review']['access_sources']}
            for entry, methods in entries.items():
                with self.subTest(entry=entry):
                    path, _ = checker.reviewed_source(sources[entry])
                    self.assertEqual(path, checker.composer_model_path(entry))
                    declaration = checker._ast_class(checker.parse_schema_php(path.read_text()))
                    self.assertEqual(entry, checker._ast_name(declaration.get('namespacedName')))
                    declared = {checker._ast_name(node.get('name')): node for node in declaration['stmts']
                                if checker._ast_is(node, 'Stmt_ClassMethod')}
                    for method in methods:
                        self.assertIn(method, declared)
                        result = declared[method]['returnType']
                        if checker._ast_is(result, 'NullableType'):
                            result = result['type']
                        self.assertEqual('array', checker._ast_name(result))
                    self.assertIn(entry, checker.declared_module_exports())

    def test_identity_delta_changed_support_evidence_is_current(self):
        changed = {'server/app/modules/official/identity/module.json',
                   'server/app/modules/official/identity/src/ModuleProvider.php',
                   'server/app/adminapi/services/dept/DeptApplicationService.php'}
        seen = set()
        for contract in self.registry['access_contracts'].values():
            proofs = list(contract.get('support_sources', []))
            manifest = contract.get('public_use_case', {}).get('manifest_source')
            if manifest:
                proofs.append(manifest)
            for proof in proofs:
                if proof['path'] in changed:
                    checker.reviewed_source(proof)
                    seen.add(proof['path'])
        self.assertEqual(changed, seen)

    def test_identity_delta_old_source_evidence_stays_rejected(self):
        old = {
            'server/app/modules/official/identity/module.json': '8f449fd4673fc54a33ef4358f6802a1c9e7658b5c751f0e1caa160994ad0a9e1',
            'server/app/modules/official/identity/src/ModuleProvider.php': 'a3998366f3c00d819f545229c9285d4f0ba65789ed3dad039837647de9ae833f',
            'server/app/adminapi/services/dept/DeptApplicationService.php': '1e32b41d0e88ea8eaf5f6349f620982dada1624dc1901ecf59878b3479a2a1af',
            'server/app/modules/official/identity/src/Menu/MenuCatalogSynchronizer.php': '1bd0db218260c15c9d7b33d21b004fb1993a948a0b994b9430d9de5abadec48b',
        }
        for path, digest in old.items():
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, 'SOURCE_REVIEW_STALE'):
                checker.reviewed_source({'source': 'application', 'path': path, 'sha256': digest})

    def test_identity_delta_provider_bindings_do_not_publish_policy_storage(self):
        path = checker.ROOT / 'server/app/modules/official/identity/src/ModuleProvider.php'
        declaration = checker._ast_class(checker.parse_schema_php(path.read_text()))
        method = checker._ast_method(declaration, 'bindings', False, 0)
        returned = checker._ast_return(method)
        self.assertTrue(checker._ast_is(returned, 'Expr_Array'))
        bindings = {}
        for item in returned['items']:
            self.assertTrue(checker._ast_is(item['key'], 'Expr_ClassConstFetch'))
            self.assertTrue(checker._ast_is(item['value'], 'Expr_ClassConstFetch'))
            bindings[checker._ast_name(item['key']['class'])] = checker._ast_name(item['value']['class'])
        catalog = 'PeanutAdmin\\Modules\\Identity\\DataPermission\\Catalog\\ThinkPhpResourceOperationCatalog'
        repository = 'PeanutAdmin\\Modules\\Identity\\DataPermission\\Policy\\ThinkPhpPolicyRepository'
        self.assertEqual(catalog, bindings.get('PeanutAdmin\\DataPermission\\Catalog\\ResourceOperationCatalog'))
        self.assertEqual(repository, bindings.get('PeanutAdmin\\DataPermission\\Policy\\PolicyRepository'))
        exports = checker.declared_module_exports()
        self.assertIn(catalog, exports)
        self.assertNotIn(repository, exports)
        self.assertNotIn('PeanutAdmin\\Modules\\Identity\\DataPermission\\Model\\DataPermissionPolicyRecord', exports)

    def test_identity_delta_new_access_fingerprints_and_case_are_required(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_module_installation')
        sources = {Path(source['path']).name: source for source in row['instance_review']['access_sources']}
        for name in ('CatalogLifecycleService.php', 'TenantModuleStateQueries.php'):
            source = copy.deepcopy(sources[name])
            checker.reviewed_source(source)
            source['sha256'] = '0' * 64
            with self.assertRaisesRegex(ValueError, 'SOURCE_REVIEW_STALE'):
                checker.reviewed_source(source)
            source = copy.deepcopy(sources[name])
            source['path'] = source['path'].replace(name, name.lower())
            with self.assertRaisesRegex(ValueError, 'REVIEW_PATH_MISSING'):
                checker.reviewed_source(source)

    def test_workflow_trusted_caller_is_in_the_review_boundary(self):
        entry = 'PeanutAdmin\\Modules\\Workflow\\Persistence\\ThinkPhpWorkflowRepository'
        support = self.registry['access_contracts'][entry]['support_sources']
        caller = next(item for item in support if item['path'].endswith('/Application/WorkflowRuntime.php'))
        checker.reviewed_source(caller)
        caller['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in e for e in checker.ownership_errors()))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-ref', required=True)
    parser.add_argument('--php-core-root', required=True)
    parser.add_argument('--php-core-ref', required=True)
    OPTIONS, rest = parser.parse_known_args()
    unittest.main(argv=[__file__, *rest])
