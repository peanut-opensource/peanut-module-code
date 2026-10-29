#!/usr/bin/env python3
"""Focused filesystem-boundary tests for browser public asset assembly."""
import hashlib
import importlib.util
import os
import stat
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TMP = ROOT / '.local/tmp/release-public-assets-20260929'
spec = importlib.util.spec_from_file_location('package_files', ROOT / 'scripts/package-release-files.py')
pack = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pack)


class ReleasePublicAssetsTest(unittest.TestCase):
    def make_case(self):
        TMP.mkdir(parents=True, exist_ok=True)
        temp = tempfile.TemporaryDirectory(prefix='public-assets-', dir=TMP)
        self.addCleanup(temp.cleanup)
        root = Path(temp.name)
        build = root / 'build'
        target = root / 'target'
        for source, text in {
            'web/dist/index.html': '<html>admin</html>',
            'web/dist/assets/app.js': 'admin();\n',
            'platform/dist/index.html': '<html>platform</html>',
            'platform/dist/assets/panel.css': 'body{}\n',
            'pc/.output/public/index.html': '<html>pc</html>',
            'pc/.output/public/_nuxt/entry.js': 'pc();\n',
            'uniapp/dist/build/h5/index.html': '<html>mobile</html>',
            'uniapp/dist/build/h5/static/app.js': 'mobile();\n',
        }.items():
            path = build / source
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text)
        target.mkdir()
        return build, target

    def assert_rejected(self, mutate, pattern):
        build, target = self.make_case()
        mutate(build, target)
        before = self._snapshot(target.parent)
        with self.assertRaisesRegex(ValueError, pattern):
            pack.public_assets(build, target)
        self.assertEqual(self._snapshot(target.parent), before, 'rejected inputs must not copy any files')

    def _snapshot(self, root):
        values = {}
        for path in root.rglob('*'):
            info = path.lstat()
            value = (info.st_mode, info.st_size)
            if path.is_symlink(): value += (os.readlink(path),)
            elif stat.S_ISREG(info.st_mode): value += (hashlib.sha256(path.read_bytes()).hexdigest(),)
            values[path.relative_to(root).as_posix()] = value
        return values

    def test_copies_four_browser_outputs_as_regular_public_files(self):
        build, target = self.make_case()
        hashes = pack.public_assets(build, target)
        expected = {
            'server/public/admin/index.html',
            'server/public/platform/index.html',
            'server/public/pc/_nuxt/entry.js',
            'server/public/mobile/static/app.js',
        }
        self.assertTrue(expected.issubset(hashes))
        for relative, digest in hashes.items():
            path = target / relative
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), digest)
            self.assertTrue(stat.S_ISREG(path.stat().st_mode), relative)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o644, relative)

    def test_missing_browser_entry_is_rejected(self):
        self.assert_rejected(lambda build, _target: (build / 'web/dist/index.html').unlink(),
                             'missing browser entry')

    def test_source_root_or_ancestor_symlink_is_rejected(self):
        def source_root(build, _target):
            real = build / 'real-web'
            (build / 'web').rename(real)
            (build / 'web').symlink_to(real, target_is_directory=True)
        self.assert_rejected(source_root, 'symlink')

    def test_hardlink_special_hidden_and_dependency_assets_are_rejected(self):
        cases = {
            'hard-linked': lambda build: os.link(build / 'platform/dist/index.html',
                                                build / 'web/dist/assets/hard.js'),
            'non-regular': lambda build: os.mkfifo(build / 'web/dist/assets/fifo.bin'),
            'non-public': lambda build: (build / 'web/dist/.env').write_text('SECRET=x\n'),
            'installed dependency': lambda build: self._write(build / 'web/dist/node_modules/pkg/index.js', 'bad\n'),
        }
        for pattern, mutate_source in cases.items():
            with self.subTest(pattern=pattern):
                self.assert_rejected(lambda build, _target, f=mutate_source: f(build), pattern)

    def test_existing_target_symlink_and_case_conflict_are_rejected(self):
        cases = {
            'symlink': lambda target: self._target_symlink(target),
            'case-colliding': lambda target: (target / 'server/public/Admin').mkdir(parents=True),
            'conflicts': lambda target: self._write(target / 'server/public/admin/index.html', 'old\n'),
            'path conflicts': lambda target: self._write(target / 'server/public/admin', 'not a directory\n'),
        }
        for pattern, mutate_target in cases.items():
            with self.subTest(pattern=pattern):
                self.assert_rejected(lambda _build, target, f=mutate_target: f(target), pattern)

    def test_case_colliding_source_assets_and_output_inside_input_are_rejected(self):
        build, target = self.make_case()
        upper = build / 'web/dist/INDEX.HTML'
        upper.write_text('other\n')
        if not os.path.samefile(build / 'web/dist/index.html', upper):
            with self.assertRaisesRegex(ValueError, 'case-colliding'):
                pack.public_assets(build, target)
        else:
            print('SOURCE_CASE_COLLISION_NOT_EXERCISED: case-insensitive filesystem; target case collision is separately tested')
        build, _target = self.make_case()
        with self.assertRaisesRegex(ValueError, 'outside the build input'):
            pack.public_assets(build, build / 'web/dist/release-out')

    def test_build_ancestor_link_and_late_invalid_input_are_rejected_without_writes(self):
        build, target = self.make_case()
        alias = build.parent / 'alias'
        alias.symlink_to(build.parent, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'symlink'):
            pack.public_assets(alias / 'build', target)
        self.assertEqual(list(target.iterdir()), [])
        self.assert_rejected(lambda build, _target: (build / 'uniapp/dist/build/h5/.env.local').write_text('SYNTHETIC=only'), 'non-public')

    def test_target_ancestor_link_cannot_write_to_other_directory(self):
        build, target = self.make_case()
        outside = target.parent / 'outside'
        outside.mkdir()
        alias = target.parent / 'alias'
        alias.symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'symlink'):
            pack.public_assets(build, alias / 'release')
        self.assertEqual(list(outside.iterdir()), [])

    def _write(self, path, text):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def _target_symlink(self, target):
        outside = target.parent / 'outside'
        outside.mkdir()
        link = target / 'server/public/admin/index.html'
        link.parent.mkdir(parents=True)
        link.symlink_to(outside / 'index.html')


if __name__ == '__main__':
    unittest.main(verbosity=2)
