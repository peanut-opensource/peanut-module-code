"""Real temporary Git/TAR inputs; native pack/check are stubbed to test orchestration.
These assertions do not claim Module business, database or browser acceptance.
"""
import contextlib
import hashlib
import io
import json
from pathlib import Path
import runpy
import subprocess
import tempfile
import tarfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
P = runpy.run_path(str(ROOT / 'scripts/build-module-release-index'))
C = P['CATALOG']
PUB = runpy.run_path(str(ROOT / 'scripts/publish-module-release'))
BUILD, PLAN = P['build'], P['make_plan']
SHA = lambda b: hashlib.sha256(b).hexdigest()


class Fixture:
    def __init__(self, parent):
        self.root = Path(parent) / 'source'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.email', 'test@example.invalid')
        self.git('config', 'user.name', 'Test')
        self.manifest('file')
        self.manifest('task', deps=[{'module_key': 'peanut.file', 'version': '^1.0.0'}])
        self.base = self.commit('baseline')
        self.archives = {n: self.archive(n) for n in ('file', 'task')}
        rows = P['selected_records'](self.root, self.base, 'prerelease')
        for row in rows:
            row.pop('source_sha256')
            data = self.archives[row['key'][7:]]
            row.update(sha256=SHA(data), bytes=len(data))
        self.index = {'schema_version': 1, 'protocol': 'peanut.module-release-index.v1',
                      'repository': C['REPO'], 'tag': 'v1.0.0-rc.1', 'commit': self.base, 'modules': rows}
        self.raw = json.dumps(self.index).encode()
        self.baseline_file = Path(parent) / 'baseline.json'
        self.baseline_file.write_bytes(self.raw)
        self.calls, self.fail_check_once = [], False

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], text=True, stderr=subprocess.PIPE).strip()

    def write(self, relative, content):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding='utf8')

    def manifest(self, name, version='1.0.0', deps=None):
        backend = 'server/app/modules/peanut/' + name
        self.write(backend + '/module.json', json.dumps({'key': 'peanut.' + name, 'version': version,
            'license': 'Apache-2.0', 'kernel_constraint': '^5.0', 'dependencies': deps or [],
            'frontend': {'entry': f'web/src/modules/peanut-{name}/contribution.ts'}}) + '\n')
        self.write(backend + '/composer.json', json.dumps({'license': 'Apache-2.0', 'extra': {
            'peanut': {'editions': ['standalone', 'multi-tenant']}}}) + '\n')
        self.write(backend + '/source.php', '<?php return 1;\n')
        self.write(f'web/src/modules/peanut-{name}/contribution.ts', 'export const feature = 1;\n')

    def commit(self, label):
        self.git('add', '.')
        self.git('commit', '-qm', label)
        return self.git('rev-parse', 'HEAD')

    def archive(self, name):
        members = {}
        for relative in ('server/app/modules/peanut/' + name, 'web/src/modules/peanut-' + name):
            for file in (self.root / relative).rglob('*'):
                if file.is_file():
                    members[file.relative_to(self.root).as_posix()] = file.read_bytes()
        members['META-INF/files.sha256'] = ''.join(f'{p}\0{SHA(data)}\n' for p, data in sorted(members.items())).encode()
        out = io.BytesIO()
        with tarfile.open(fileobj=out, mode='w', format=tarfile.USTAR_FORMAT) as tar:
            for name, data in sorted(members.items()):
                info = tarfile.TarInfo(name)
                info.size, info.mode, info.mtime = len(data), 0o644, 0
                tar.addfile(info, io.BytesIO(data))
        return out.getvalue()

    def native(self, root, operation, args):
        key = args[0]
        self.calls.append((operation, key))
        version = json.loads((self.root / ('server/app/modules/peanut/' + key[7:] + '/module.json')).read_bytes())['version']
        if operation == 'pack':
            data = self.archive(key[7:])
            Path(args[2]).write_bytes(data)
            return {'sha256': SHA(data), 'package_key': key, 'version': version}
        if self.fail_check_once:
            self.fail_check_once = False
            raise RuntimeError('injected native check failure')
        return {'status': 'ready', 'module': {'version': version},
                'checks': [{'id': n, 'status': 'passed'} for n in
                    ('manifest', 'version', 'dependencies', 'permissions', 'menus', 'frontend', 'package')]}

    def reference(self, index, row):
        return {'tag_name': C['artifact_tag'](index, row)}

    def bytes(self, release, asset):
        return self.archives[asset.split('.')[1].split('-')[0]]

    def patches(self):
        return patch.dict(BUILD.__globals__, {'native': self.native,
             'dependency_check': lambda *a: None, 'public_contract_check': lambda *a: None,
             'validation_environment': lambda *a: 'e' * 64})


class ModuleAutomationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='module-release-automation-')
        self.addCleanup(self.temp.cleanup)
        self.f = Fixture(self.temp.name)

    def plan(self):
        return PLAN(self.f.root, self.f.git('rev-parse', 'HEAD'), self.f.raw)

    def changed(self):
        self.f.manifest('file', '1.0.1')
        self.f.commit('file change')
        return self.plan()

    def build(self, plan, resume=False):
        with self.f.patches(), contextlib.redirect_stdout(io.StringIO()):
            return BUILD(self.f.root, Path(self.temp.name) / 'output', 'v1.0.0-rc.2', 'prerelease',
                         plan['source_commit'], plan, resume, self.f)

    def test_zero_changes_skips_output_and_native_checks(self):
        plan = self.plan()
        self.assertEqual(plan['status'], 'no_module_release_needed')
        self.assertIsNone(self.build(plan))
        self.assertEqual(self.f.calls, [])
        self.assertFalse((Path(self.temp.name) / 'output').exists())

    def test_one_change_packs_one_and_checks_reverse_dependency(self):
        plan = self.changed()
        self.assertEqual(plan['summary'], {'unchanged': 1, 'changed': 1, 'blocked': 0})
        self.assertEqual(plan['validation_modules'], ['peanut.file', 'peanut.task'])
        index = self.build(plan)
        self.assertEqual(index['schema_version'], 2)
        rows = {r['key']: r for r in index['modules']}
        self.assertEqual(rows['peanut.task']['sha256'], self.f.index['modules'][1]['sha256'])
        self.assertEqual(rows['peanut.task']['artifact_tag'], 'v1.0.0-rc.1')
        self.assertEqual(rows['peanut.file']['artifact_tag'], 'v1.0.0-rc.2')
        self.assertEqual([c for c in self.f.calls if c[0] == 'pack'], [('pack', 'peanut.file')])
        self.assertFalse((Path(self.temp.name) / 'output/peanut.task-1.0.0.tar').exists())

    def test_source_change_without_version_and_downgrade_block(self):
        self.f.write('web/src/modules/peanut-file/contribution.ts', 'export const feature = 2;\n')
        self.f.commit('bad no version')
        self.assertEqual(self.plan()['status'], 'blocked')
        self.f.manifest('file', '0.9.0')
        self.f.commit('bad downgrade')
        self.assertIn('version_downgrade', [r['status'] for r in self.plan()['modules']])

    def test_failed_check_resumes_without_repacking(self):
        plan = self.changed()
        self.f.fail_check_once = True
        with self.assertRaisesRegex(RuntimeError, 'injected'):
            self.build(plan)
        self.build(plan, resume=True)
        self.assertEqual([c for c in self.f.calls if c[0] == 'pack'], [('pack', 'peanut.file')])
        self.f.calls.clear()
        self.build(plan, resume=True)
        self.assertEqual(self.f.calls, [])

    def test_environment_change_rechecks_without_repacking(self):
        plan = self.changed()
        self.build(plan)
        self.f.calls.clear()
        with self.f.patches(), patch.dict(BUILD.__globals__, {'validation_environment': lambda *_: 'f' * 64}), contextlib.redirect_stdout(io.StringIO()):
            BUILD(self.f.root, Path(self.temp.name) / 'output', 'v1.0.0-rc.2', 'prerelease', plan['source_commit'], plan, True, self.f)
        self.assertEqual([c for c in self.f.calls if c[0] == 'pack'], [])
        self.assertEqual(len([c for c in self.f.calls if c[0] == 'check']), 2)

    def test_corrupt_artifact_is_not_silently_rebuilt(self):
        plan = self.changed()
        self.build(plan)
        (Path(self.temp.name) / 'output/peanut.file-1.0.1.tar').write_bytes(b'corrupt')
        self.f.calls.clear()
        with self.assertRaisesRegex(RuntimeError, 'RESUME_ARTIFACT_CHANGED'):
            self.build(plan, resume=True)
        self.assertEqual(self.f.calls, [])

    def test_plan_and_external_baseline_hash_reject_drift(self):
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_DIGEST_MISMATCH'):
            P['plan_source_changes'](self.f.root, self.f.base, self.f.baseline_file, '0' * 64)
        plan = self.changed()
        path = Path(self.temp.name) / 'plan.json'
        P['write_json'](path, plan)
        self.assertEqual(P['load_plan'](self.f.root, plan['source_commit'], path, plan['plan_sha256']), plan)
        plan['records'][0]['version'] = '9.9.9'
        P['write_json'](path, plan)
        with self.assertRaisesRegex(RuntimeError, 'PLAN_IDENTITY_CHANGED'):
            P['load_plan'](self.f.root, plan['source_commit'], path, plan['plan_sha256'])

    def test_fingerprint_independent_of_checkout_path(self):
        paths = P['source_paths_at'](self.f.root, self.f.base, 'peanut.file')[1]
        before = P['source_fingerprint'](self.f.root, self.f.base, paths)
        clone = Path(self.temp.name) / 'other-machine-path'
        subprocess.run(['git', 'clone', '-q', str(self.f.root), str(clone)], check=True)
        self.assertEqual(before, P['source_fingerprint'](clone, self.f.base, paths))

    def test_partial_draft_only_missing_originals(self):
        fn = PUB['verify_partial_draft']
        files = {'one.tar': b'one', 'index.json': b'index'}
        with patch.dict(fn.__globals__, {'pages': lambda _: iter([{'name': 'one.tar'}]), 'asset_read': lambda *_: b'one'}):
            self.assertEqual(fn({'draft': True, 'id': 12}, files), ['index.json'])
        with patch.dict(fn.__globals__, {'pages': lambda _: iter([{'name': 'one.tar'}]), 'asset_read': lambda *_: b'changed'}):
            with self.assertRaisesRegex(RuntimeError, 'DRAFT_BYTES_CHANGED'):
                fn({'draft': True, 'id': 12}, files)

    def test_workflow_guards_effects_and_removes_unused_cli_install(self):
        workflow = (ROOT / '.github/workflows/module-release.yml').read_text()
        self.assertLess(workflow.index('id: plan'), workflow.index('uses: shivammathur/setup-php'))
        self.assertNotIn('npm install --global', workflow)
        self.assertIn('--plan-file "$RUNNER_TEMP/module-release-plan.json"', workflow)
        self.assertIn('official-modules-${{ github.run_id }}-${{ github.run_attempt }}', workflow)
        self.assertIn('&& inputs.publish && !inputs.plan_only', workflow)
        for step in ('Install exact native Composer lock', 'Native incremental pack', 'Audit all previous'):
            block = workflow[workflow.index('      - name: ' + step):].split('\n      - ', 1)[0]
            self.assertIn("if: steps.plan.outputs.release_required == 'true' && !inputs.plan_only", block)


if __name__ == '__main__':
    unittest.main()
