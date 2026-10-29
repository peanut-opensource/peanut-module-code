#!/usr/bin/env python3
"""Use the locked TypeScript compiler, not handwritten approximations of its declarations."""
from __future__ import annotations

import importlib.machinery
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('freeze_native_types', str(ROOT / 'scripts/freeze-core-release'))
spec = importlib.util.spec_from_loader(loader.name, loader)
release = importlib.util.module_from_spec(spec)
loader.exec_module(release)
VERSION = '4.0.0-rc.20260926'


class NativeDeclarationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        temporary_root = Path(os.environ.get('TMPDIR', '')).resolve()
        if not temporary_root.is_relative_to(ROOT / '.local/tmp'):
            raise RuntimeError('Use this Code checkout-owned TMPDIR')
        compiler = ROOT / 'tools/quality/node_modules/typescript/bin/tsc'
        node = shutil.which('node')
        if node is None or not compiler.is_file():
            raise RuntimeError('Install the locked tools/quality dependencies and provide Node on PATH')
        cls.temporary = tempfile.TemporaryDirectory(dir=temporary_root, prefix='native-declarations-')
        cls.addClassCleanup(cls.temporary.cleanup)
        cls.root = Path(cls.temporary.name)
        for suffix, symbol in release.WEB_VERSION_EXPORTS.items():
            source = cls.root / 'src' / suffix / 'index.ts'
            source.parent.mkdir(parents=True)
            source.write_text(f"export const {symbol} = '{VERSION}' as const;\n", encoding='utf-8')
        config = {'compilerOptions': {'target': 'ES2022', 'module': 'ESNext', 'strict': True,
                  'declaration': True, 'types': [], 'rootDir': 'src', 'outDir': 'dist'},
                  'include': ['src/**/*.ts']}
        (cls.root / 'tsconfig.json').write_text(json.dumps(config), encoding='utf-8')
        result = subprocess.run([node, str(compiler), '--project', str(cls.root / 'tsconfig.json')],
                                cwd=cls.root, capture_output=True, text=True, timeout=60)
        if result.returncode != 0:
            raise RuntimeError('Native TypeScript compilation failed: ' + result.stdout + result.stderr)

    def packed(self, suffix='vue', declaration=None, javascript=None):
        path = self.root / (self._testMethodName + '-' + suffix + '.tgz')
        contents = {
            'package/package.json': json.dumps({'name': '@peanut-admin/' + suffix, 'version': VERSION}),
            'package/LICENSE': 'Synthetic test license',
            'package/dist/index.js': javascript if javascript is not None else
                (self.root / 'dist' / suffix / 'index.js').read_text(),
            'package/dist/index.d.ts': declaration if declaration is not None else
                (self.root / 'dist' / suffix / 'index.d.ts').read_text(),
        }
        with tarfile.open(path, 'w:gz') as archive:
            for name, text in contents.items():
                data = text.encode('utf-8')
                member = tarfile.TarInfo(name)
                member.size = len(data)
                archive.addfile(member, io.BytesIO(data))
        return path

    def reject(self, **mutation):
        with self.assertRaisesRegex(ValueError, 'exported version'):
            release.inspect_web_archive(self.packed(**mutation), 'vue', VERSION)

    def test_real_compiler_declarations_for_each_versioned_package_are_accepted(self):
        for suffix, symbol in release.WEB_VERSION_EXPORTS.items():
            with self.subTest(package=suffix):
                text = (self.root / 'dist' / suffix / 'index.d.ts').read_text()
                self.assertIn(f'{symbol}: "{VERSION}";', text)
                self.assertEqual(release.inspect_web_archive(self.packed(suffix), suffix, VERSION)['version'], VERSION)

    def test_stale_type_literal_is_rejected(self):
        self.reject(declaration='export declare const PEANUT_ADMIN_VUE_VERSION: "4.0.0-dev.1";\n')

    def test_widened_type_is_not_a_fixed_version(self):
        self.reject(declaration='export declare const PEANUT_ADMIN_VUE_VERSION: string;\n')

    def test_duplicate_version_declarations_are_rejected(self):
        line = f'export declare const PEANUT_ADMIN_VUE_VERSION: "{VERSION}";\n'
        self.reject(declaration=line + line)

    def test_missing_version_declaration_is_rejected(self):
        self.reject(declaration='export declare const OTHER: "unrelated";\n')

    def test_stale_javascript_is_rejected_even_with_current_types(self):
        self.reject(javascript='export const PEANUT_ADMIN_VUE_VERSION = "4.0.0-dev.1";\n')

    def test_type_syntax_cannot_masquerade_as_executable_javascript(self):
        self.reject(javascript=f'export const PEANUT_ADMIN_VUE_VERSION: "{VERSION}";\n')

    def test_mismatched_quote_delimiters_are_rejected(self):
        self.reject(declaration=f'export declare const PEANUT_ADMIN_VUE_VERSION: "{VERSION}\';\n')


if __name__ == '__main__':
    unittest.main(verbosity=2)
