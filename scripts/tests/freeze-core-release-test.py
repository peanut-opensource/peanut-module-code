#!/usr/bin/env python3
"""Exercise release transformations with real temporary Git/ZIP/TAR objects."""
import importlib.machinery
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('freeze_core_release', str(ROOT / 'scripts/freeze-core-release'))
spec = importlib.util.spec_from_loader(loader.name, loader)
release = importlib.util.module_from_spec(spec)
loader.exec_module(release)

class FreezeCoreReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='peanut-release-transform-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def repo(self):
        root = self.root / 'repo'; root.mkdir()
        subprocess.run(['git', 'init', '-q', '-b', 'dev', str(root)], check=True)
        subprocess.run(['git', '-C', str(root), 'remote', 'add', 'origin',
                        'https://github.com/peanut-business/peanut-admin-core-php.git'], check=True)
        (root / 'composer.json').write_text('{"name":"peanut-admin/core","autoload":{"psr-4":{"Probe\\\\":"src/"}}}')
        (root / 'src').mkdir(); (root / 'src/Probe.php').write_text('<?php namespace Probe; class Probe {}\n')
        (root / 'LICENSE').write_text('Fixture license\n')
        subprocess.run(['git', '-C', str(root), 'add', '.'], check=True)
        subprocess.run(['git', '-C', str(root), '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                        'commit', '-qm', 'fixture'], check=True)
        return root

    def test_default_composer_uses_project_pinned_entry_not_global_path(self):
        with patch.object(release.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0)) as run:
            release.validate_composer_version('4.0.0-rc.1', self.root)
        self.assertEqual(run.call_args.args[0][0], str(ROOT / 'scripts/project-composer'))
        self.assertEqual(run.call_args.kwargs['cwd'], self.root / 'composer-version-probe')

    def test_explicit_prepared_composer_is_literal_argv(self):
        entry = self.root / 'prepared composer'
        entry.write_text('#!/bin/sh\nexit 0\n'); entry.chmod(0o755)
        with patch.object(release.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0)) as run:
            release.validate_composer_version('4.0.0-rc.1', self.root, entry)
        self.assertEqual(run.call_args.args[0], [str(entry), 'show', '--self', '--format=json', '--no-interaction'])

    def test_missing_composer_rejects_before_creating_probe(self):
        with self.assertRaisesRegex(ValueError, 'Prepared project Composer'):
            release.validate_composer_version('4.0.0-rc.1', self.root, self.root / 'missing')
        self.assertFalse((self.root / 'composer-version-probe').exists())

    def test_selected_version_still_requires_real_composer_acceptance(self):
        with patch.object(release.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1)):
            with self.assertRaisesRegex(ValueError, 'Composer rejected'):
                release.validate_composer_version('4.0.0-rc.1', self.root)

    def test_missing_corepack_is_rejected(self):
        with patch.object(release.shutil, 'which', return_value=None):
            with self.assertRaisesRegex(ValueError, 'Corepack is required'):
                release.corepack_command()

    def test_task_only_pnpm_entry_uses_literal_argv_with_spaces(self):
        corepack = str(self.root / 'prepared corepack')
        parent_path = os.environ.get('PATH', '')

        def enable(arguments, **kwargs):
            self.assertEqual(arguments, [corepack, 'enable', 'pnpm',
                f'--install-directory={self.root / "tool-bin"}'])
            entry = self.root / 'tool-bin' / 'pnpm'
            entry.write_text('#!/bin/sh\nexit 0\n'); entry.chmod(0o755)
            return subprocess.CompletedProcess(arguments, 0)

        with patch.object(release.subprocess, 'run', side_effect=enable):
            environment = release.task_pnpm_environment(self.root, corepack)
        self.assertEqual(environment['PATH'].split(os.pathsep)[0], str(self.root / 'tool-bin'))
        self.assertEqual(os.environ.get('PATH', ''), parent_path)

    def test_install_build_and_pack_share_the_same_child_environment(self):
        corepack = str(self.root / 'corepack with spaces')
        environment = {'PATH': str(self.root / 'tool bin'), 'FIXTURE': 'same'}
        with patch.object(release.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0)) as run:
            release.run_pnpm(corepack, ['install', '--frozen-lockfile'], self.root, environment, 900)
            release.run_pnpm(corepack, ['run', 'build'], self.root, environment, 900)
            release.run_pnpm(corepack, ['pack', '--pack-destination', str(self.root / 'packed output')],
                             self.root / 'package with spaces', environment, 120)
        self.assertEqual([call.args[0] for call in run.call_args_list], [
            [corepack, 'pnpm', 'install', '--frozen-lockfile'],
            [corepack, 'pnpm', 'run', 'build'],
            [corepack, 'pnpm', 'pack', '--pack-destination', str(self.root / 'packed output')],
        ])
        self.assertTrue(all(call.kwargs['env'] is environment for call in run.call_args_list))

    def test_exact_versions_and_prereleases_are_accepted(self):
        for value in ('4.0.0', '4.0.0-rc.1', '4.0.0-dev.2', '4.0.0+build.12'):
            self.assertEqual(release.version(value), value)

    def test_branch_range_alias_and_malformed_versions_are_rejected(self):
        for value in ('dev-dev', '^4.0.0', 'v4.0.0', '4.0.0 as 3.0.0', '04.0.0', '4.0.0-01', '4.0.0\n'):
            with self.assertRaises(ValueError, msg=value): release.version(value)

    def test_source_identity_records_real_git_commit_and_tree(self):
        root = self.repo()
        result = release.identity(root, 'peanut-business/peanut-admin-core-php')
        self.assertEqual(result['commit'], release.git(root, 'rev-parse', 'HEAD'))
        self.assertEqual(result['tree'], release.git(root, 'rev-parse', 'HEAD^{tree}'))

    def test_dirty_release_input_is_rejected(self):
        root = self.repo(); (root / 'src/Probe.php').write_text('changed')
        with self.assertRaisesRegex(ValueError, 'dirty'):
            release.identity(root, 'peanut-business/peanut-admin-core-php')

    def test_wrong_repository_is_rejected(self):
        root = self.repo()
        with self.assertRaisesRegex(ValueError, 'origin'):
            release.identity(root, 'peanut-business/peanut-admin-core-web')

    def test_php_freeze_is_reproducible_and_does_not_mutate_source(self):
        root = self.repo(); original = (root / 'composer.json').read_bytes()
        commit = release.git(root, 'rev-parse', 'HEAD')
        for suffix in ('a', 'b'):
            projection = self.root / suffix
            release.snapshot(root, commit, projection)
            release.build_php_archive(projection, self.root / (suffix + '.zip'), '4.0.0-rc.1')
        self.assertEqual((self.root / 'a.zip').read_bytes(), (self.root / 'b.zip').read_bytes())
        with zipfile.ZipFile(self.root / 'a.zip') as archive:
            self.assertEqual(json.loads(archive.read('core/composer.json'))['version'], '4.0.0-rc.1')
            self.assertIn('core/src/Probe.php', archive.namelist())
        self.assertEqual((root / 'composer.json').read_bytes(), original)
        self.assertEqual(release.git(root, 'status', '--porcelain'), '')

    def test_php_projection_does_not_package_maintainer_or_environment_files(self):
        root = self.repo(); projection = self.root / 'projection'
        release.snapshot(root, release.git(root, 'rev-parse', 'HEAD'), projection)
        (projection / 'AGENTS.md').write_text('maintainer-only fixture')
        (projection / '.env').write_text('FIXTURE_ONLY=true')
        output = self.root / 'core.zip'; release.build_php_archive(projection, output, '4.0.0')
        with zipfile.ZipFile(output) as archive:
            self.assertNotIn('core/AGENTS.md', archive.namelist())
            self.assertNotIn('core/.env', archive.namelist())

    def test_web_stamping_preserves_external_versions_and_source_code(self):
        root = self.root / 'web'; root.mkdir()
        release.write_json(root / 'package.json', {'name': 'workspace', 'version': '1.0.0'})
        for name in release.PACKAGES:
            package = root / 'packages' / name; package.mkdir(parents=True)
            release.write_json(package / 'package.json', {'name': '@peanut-admin/' + name,
                'version': '1.0.0', 'peerDependencies': {'@peanut-admin/client': '1.0.0', 'vue': '^3.4.21'},
                'devDependencies': {'@peanut-admin/client': 'workspace:*'}})
            (package / 'source.ts').write_text('export const value = "1.0.0";')
            symbol = {'vue': 'PEANUT_ADMIN_VUE_VERSION', 'ui-vue': 'PEANUT_ADMIN_UI_VUE_VERSION',
                      'testing': 'WEB_TESTING_VERSION'}.get(name)
            if symbol:
                (package / 'src').mkdir()
                (package / 'src/index.ts').write_text(f"export const {symbol} = '1.0.0' as const\n")
        release.stamp_web(root, '4.0.0-rc.1')
        data = release.read_json(root / 'packages/vue/package.json')
        self.assertEqual(data['version'], '4.0.0-rc.1')
        self.assertEqual(data['peerDependencies']['@peanut-admin/client'], '4.0.0-rc.1')
        self.assertEqual(data['peerDependencies']['vue'], '^3.4.21')
        self.assertEqual(data['devDependencies']['@peanut-admin/client'], 'workspace:*')
        self.assertIn('1.0.0', (root / 'packages/vue/source.ts').read_text())
        for suffix in ('vue', 'ui-vue', 'testing'):
            self.assertIn("'4.0.0-rc.1' as const", (root / 'packages' / suffix / 'src/index.ts').read_text())

    def packed_web(self, manifest_version, javascript_version, type_version):
        path = self.root / 'version-fixture.tgz'
        documents = {
            'package/package.json': json.dumps({'name': '@peanut-admin/vue', 'version': manifest_version}),
            'package/LICENSE': 'Synthetic test license',
            'package/dist/index.js': f'export const PEANUT_ADMIN_VUE_VERSION = "{javascript_version}";\n',
            'package/dist/index.d.ts': f'export declare const PEANUT_ADMIN_VUE_VERSION = "{type_version}";\n',
        }
        with tarfile.open(path, 'w:gz') as archive:
            for name, text in documents.items():
                raw = text.encode(); info = tarfile.TarInfo(name); info.size = len(raw)
                archive.addfile(info, io.BytesIO(raw))
        return path

    def test_packed_runtime_version_must_match_the_manifest(self):
        path = self.packed_web('4.0.0-rc.1', '4.0.0-dev.0', '4.0.0-rc.1')
        with self.assertRaisesRegex(ValueError, 'exported version'):
            release.inspect_web_archive(path, 'vue', '4.0.0-rc.1')

    def test_packed_type_literal_must_match_the_manifest(self):
        path = self.packed_web('4.0.0-rc.1', '4.0.0-rc.1', '4.0.0-dev.0')
        with self.assertRaisesRegex(ValueError, 'exported version'):
            release.inspect_web_archive(path, 'vue', '4.0.0-rc.1')

    def test_matching_packed_runtime_and_type_versions_are_accepted(self):
        path = self.packed_web('4.0.0-rc.1', '4.0.0-rc.1', '4.0.0-rc.1')
        self.assertEqual(release.inspect_web_archive(path, 'vue', '4.0.0-rc.1')['version'], '4.0.0-rc.1')

    def test_existing_output_is_never_replaced(self):
        output = self.root / 'output'; output.mkdir(); (output / 'keep').write_text('keep')
        with self.assertRaisesRegex(ValueError, 'new absolute'):
            release.freeze(self.root, self.root, output, '4.0.0', '4.0.0')
        self.assertEqual((output / 'keep').read_text(), 'keep')

    def test_incomplete_web_archive_is_rejected(self):
        path = self.root / 'bad.tgz'
        with tarfile.open(path, 'w:gz') as archive:
            raw = b'{}'; info = tarfile.TarInfo('package/package.json'); info.size = len(raw)
            archive.addfile(info, io.BytesIO(raw))
        with self.assertRaisesRegex(ValueError, 'incomplete'):
            release.inspect_web_archive(path, 'vue', '4.0.0')

if __name__ == '__main__': unittest.main(verbosity=2)
