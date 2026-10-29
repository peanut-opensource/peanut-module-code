"""Pure filesystem tests for the retained architecture checker's Composer mapping.

Only synthetic files in a test-owned temporary directory are created. PHP source
is inspected, never loaded or executed, and the historical register is not edited.
"""
from __future__ import annotations

import importlib.machinery
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


SOURCE_ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader(
    'tpq_source_resolution', str(SOURCE_ROOT / 'scripts/check-thinkphp-architecture')
)
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


class ComposerSourceResolutionTest(unittest.TestCase):
    def setUp(self):
        parent = SOURCE_ROOT / '.local/tmp/tpq-source-resolution'
        parent.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=parent)
        self.root = Path(self.temporary.name)
        self.previous = checker.ROOT
        checker.ROOT = self.root
        self.mapping({'app\\': 'app', 'PeanutAdmin\\Modules\\Article\\': 'app/modules/official/article/src/'})

    def tearDown(self):
        checker.ROOT = self.previous
        self.temporary.cleanup()

    def mapping(self, mappings):
        path = self.root / 'server/composer.json'
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({'autoload': {'psr-4': mappings}}))

    def source(self, relative):
        path = self.root / 'server' / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text('<?php /* never execute this source */')
        return path

    def test_host_and_module_mappings_are_resolved(self):
        host = self.source('app/common/model/Jobs.php')
        module = self.source('app/modules/official/article/src/Model/Article.php')
        self.assertEqual(host, checker.composer_model_path('app\\common\\model\\Jobs'))
        self.assertEqual(module, checker.composer_model_path('PeanutAdmin\\Modules\\Article\\Model\\Article'))

    def test_longest_prefix_wins_without_guessing_a_path(self):
        self.mapping({'Fixture\\': 'broad/', 'Fixture\\Module\\': 'specific/'})
        self.source('broad/Module/Record.php')
        expected = self.source('specific/Record.php')
        self.assertEqual(expected, checker.composer_model_path('Fixture\\Module\\Record'))
        self.assertIsNone(checker.composer_model_path('Unregistered\\Record'))

    def test_multiple_directories_use_the_existing_exact_source(self):
        self.mapping({'Fixture\\': ['first/', 'second/']})
        expected = self.source('second/Record.php')
        self.assertEqual(expected, checker.composer_model_path('Fixture\\Record'))
        self.source('first/Record.php')
        with self.assertRaisesRegex(ValueError, 'AMBIGUOUS'):
            checker.composer_model_path('Fixture\\Record')

    def test_case_mismatch_is_rejected_even_on_a_case_insensitive_host(self):
        self.mapping({'Fixture\\': 'src/'})
        self.source('src/Model/Record.php')
        self.assertIsNone(checker.composer_model_path('Fixture\\model\\Record'))
        self.assertIsNone(checker.composer_model_path('Fixture\\Model\\record'))

    def test_parent_escape_and_absolute_mapping_are_rejected(self):
        for mapping in ['../../outside/', '/absolute/path/']:
            self.mapping({'Fixture\\': mapping})
            with self.assertRaisesRegex(ValueError, 'OUTSIDE'):
                checker.composer_model_path('Fixture\\Record')

    def test_symlink_source_cannot_escape_the_declared_checkout(self):
        self.mapping({'Fixture\\': 'src/'})
        directory = self.root / 'server/src'
        directory.mkdir()
        outside = self.root.parent / (self.root.name + '-outside.php')
        outside.write_text('<?php')
        try:
            (directory / 'Record.php').symlink_to(outside)
            with self.assertRaisesRegex(ValueError, 'OUTSIDE'):
                checker.composer_model_path('Fixture\\Record')
        finally:
            outside.unlink()

    def test_explicit_public_service_is_not_rejected_by_its_directory_name(self):
        published = 'PeanutAdmin\\Modules\\Article\\Service\\PublicSummary'
        path = self.root / 'server/app/modules/fixture/article/module.json'
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({'contracts': {'exports': [published]}}))
        host = self.root / 'server/app/common/services/Consumer.php'
        hits = checker.hits_for(host, '<?php\nuse ' + published + ' as Summary;\n')
        categories = {hit[0] for hit in hits}
        self.assertNotIn('host_module_internal_dependency', categories)
        self.assertNotIn('common_module_reverse_dependency', categories)
        private = 'PeanutAdmin\\Modules\\Article\\Service\\PrivateSummary'
        private_hits = checker.hits_for(host, '<?php\nuse ' + private + ';\n')
        self.assertIn('host_module_internal_dependency', {hit[0] for hit in private_hits})
        self.assertIn('common_module_reverse_dependency', {hit[0] for hit in private_hits})

    def test_public_manifest_does_not_turn_raw_table_access_into_an_api(self):
        host = self.root / 'server/app/adminapi/services/Consumer.php'
        hits = checker.hits_for(host, '<?php\nuse think\\facade\\Db;\nDb::name("member")->select();\n', set())
        self.assertIn('application_raw_persistence', {hit[0] for hit in hits})

    def test_missing_source_is_not_reported_as_an_existing_class(self):
        self.assertIsNone(checker.composer_model_path('PeanutAdmin\\Modules\\Article\\Model\\Missing'))


if __name__ == '__main__':
    unittest.main()
