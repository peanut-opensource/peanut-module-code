#!/usr/bin/env python3
"""Check the actual PHP Core command's cross-platform file guard, without installing dependencies."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class LocalCoreEnvironmentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='peanut-core-environment-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.code, self.core = self.root/'code', self.root/'core'
        (self.code/'scripts').mkdir(parents=True)
        (self.code/'server/vendor/peanut-admin').mkdir(parents=True)
        self.core.mkdir()
        subprocess.run(['git','init','-q','-b','dev',str(self.core)],check=True)
        (self.core/'composer.json').write_text('{"name":"peanut-admin/core"}')
        (self.code/'server/composer.json').write_text('{"require":{"peanut-admin/core":"dev-dev"}}')
        (self.code/'server/vendor/peanut-admin/core').symlink_to(self.core,target_is_directory=True)
        shutil.copy2(ROOT/'scripts/local-core-composer',self.code/'scripts/local-core-composer')
        tool = self.code/'scripts/project-composer';tool.write_text('#!/bin/sh\nexit 0\n');tool.chmod(0o755)
        tool = self.code/'scripts/run-with-environment-file'
        tool.write_text('#!/bin/sh\nwhile [ "$1" != -- ]; do shift; done\nshift\nexec "$@"\n');tool.chmod(0o755)
        self.env = self.root/'backend.env';self.env.write_text('APP_ENV=development\n');self.env.chmod(0o600)

    def run_guard(self):
        return subprocess.run(['bash',str(self.code/'scripts/local-core-composer'),'autoload',
             '--core-dir',str(self.core),'--backend-env',str(self.env)],capture_output=True,text=True,timeout=30)

    def test_regular_single_link_0600_is_accepted(self):
        result=self.run_guard();self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn('local core active:',result.stdout)

    def test_publicly_readable_file_is_rejected(self):
        self.env.chmod(0o644);self.assertNotEqual(self.run_guard().returncode,0)

    def test_hard_link_is_rejected(self):
        os.link(self.env,self.root/'second-link');self.assertNotEqual(self.run_guard().returncode,0)

    def test_symbolic_link_is_rejected(self):
        target=self.root/'actual.env';self.env.rename(target);self.env.symlink_to(target)
        self.assertNotEqual(self.run_guard().returncode,0)

    def test_missing_file_is_rejected(self):
        self.env.unlink();self.assertNotEqual(self.run_guard().returncode,0)

if __name__=='__main__':unittest.main(verbosity=2)
