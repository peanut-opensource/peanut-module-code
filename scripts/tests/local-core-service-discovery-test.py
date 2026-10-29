#!/usr/bin/env python3
"""Exercise the real local-Core wrapper with isolated Composer/Think fixtures.

No dependencies are downloaded and no database or HTTP application is started.
The Think fixture represents command outcomes, not native framework acceptance.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[1] / 'local-core-composer'


class LocalCoreServiceDiscoveryTest(unittest.TestCase):
    def setUp(self):
        # Keep every synthetic checkout inside this worktree, not macOS /var
        # aliases. Never follow a redirected scratch root or edit vendor source.
        fixture_parent = SOURCE.parents[1]
        for component in ('.local', 'tmp', 'local-core-service-discovery'):
            fixture_parent = fixture_parent / component
            if fixture_parent.is_symlink():
                raise RuntimeError('service fixture root must not be a symlink')
            fixture_parent.mkdir(mode=0o700, exist_ok=True)
            if not fixture_parent.is_dir():
                raise RuntimeError('service fixture root must be a directory')
        self.tmp = tempfile.TemporaryDirectory(prefix='peanut-services-', dir=fixture_parent)
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / 'application'
        self.server = self.root / 'server'
        self.core = Path(self.tmp.name) / 'core'
        for p in [self.root / 'scripts', self.server / 'app', self.server / 'vendor/composer',
                  self.server / 'vendor/peanut-admin', self.core]:
            p.mkdir(parents=True, exist_ok=True)
        self.script = self.root / 'scripts/local-core-composer'
        shutil.copy2(SOURCE, self.script)
        self.script.chmod(0o755)
        subprocess.run(['git', 'init', '-q', '-b', 'dev', str(self.core)], check=True)
        (self.core / 'composer.json').write_text('{"name":"peanut-admin/core"}\n')
        (self.server / 'vendor/peanut-admin/core').symlink_to(self.core, target_is_directory=True)
        self.env = self.server / '.env'
        self.env.write_text('APP_ENV=development\nDB_PASS=synthetic-never-print\n')
        self.env.chmod(0o600)
        self.manifest = self.server / 'composer.json'
        self.manifest.write_text(json.dumps({
            'require': {'peanut-admin/core': 'dev-dev'},
            'autoload': {'psr-4': {'app\\': 'app'}},
            'scripts': {'post-autoload-dump': ['@php think service:discover', '@php think vendor:publish']},
        }))
        self.lock = self.server / 'composer.lock'
        self.lock.write_text('{"content-hash":"fixture-unchanged","packages":[]}\n')
        self.installed = self.server / 'vendor/composer/installed.json'
        self.installed.write_text(json.dumps({'packages': [
            {'name': 'topthink/think-multi-app', 'extra': {'think': {'services': ['think\\app\\Service']}}},
            {'name': 'fixture/provider', 'extra': {'think': {'services': ['Fixture\\OtherService']}}},
        ]}))
        (self.server / 'vendor/autoload.php').write_text(
            '<?php namespace think\\app { class Service {} } '
            'namespace Fixture { class OtherService {} }\n')
        self.registry = self.server / 'vendor/services.php'
        (self.server / 'think').write_text(r'''<?php
$root = dirname(__DIR__);
file_put_contents($root . '/think-call.json', json_encode([
    'args' => array_slice($argv, 1), 'cwd' => getcwd(),
    'env_path' => getenv('PEANUT_SERVER_ENV_FILE'), 'tmpdir' => getenv('TMPDIR'),
]));
if (array_slice($argv, 1) !== ['service:discover']) exit(31);
if (is_file($root . '/discovery-fails')) exit(32);
$services = [];
$installed = json_decode(file_get_contents(__DIR__ . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($installed['packages'] ?? $installed as $package) {
    $services = array_merge($services, (array)($package['extra']['think']['services'] ?? []));
}
if (is_file($root . '/wrong-registry')) $services = [];
file_put_contents(__DIR__ . '/vendor/services.php', '<?php return ' . var_export($services, true) . ';');
echo "fixture-discovery-success\n";
''')
        # Use the actual PHP CLI for manifest projection, validation and the
        # Think fixture; only Composer and its environment launcher are faked.
        composer = self.root / 'scripts/project-composer'
        composer.write_text('''#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
printf '%s\\n' "$@" > "$root/composer-call.txt"
if [ -f "$root/composer-fails" ]; then exit 33; fi
''')
        composer.chmod(0o755)
        launcher = self.root / 'scripts/run-with-environment-file'
        launcher.write_text('''#!/bin/sh
set -eu
[ "$1" = --env-file ]
file=$2
shift 2
[ "$1" = -- ]
shift
while IFS='=' read -r key value; do export "$key=$value"; done < "$file"
exec "$@"
''')
        launcher.chmod(0o755)

    def run_action(self, action):
        # Do not inherit credentials/backend variables from a developer shell.
        env = {key: value for key, value in os.environ.items()
               if key in ('PATH', 'HOME', 'SYSTEMROOT')}
        # Composer lock backups made by the wrapper must use the same owner root.
        env['TMPDIR'] = self.tmp.name
        return subprocess.run([str(self.script), action, '--core-dir', str(self.core),
                               '--backend-env', str(self.env)], env=env,
                              capture_output=True, text=True, timeout=15)

    def assert_ready(self, result):
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('multi-app included', result.stdout)
        self.assertNotIn('synthetic-never-print', result.stdout + result.stderr)

    def test_install_discovers_services_after_no_scripts_composer(self):
        before = self.lock.read_bytes(), self.manifest.read_bytes()
        result = self.run_action('install')
        self.assertTrue(self.registry.is_file(), 'successful Core installation left ThinkPHP services undiscovered')
        self.assert_ready(result)
        self.assertIn('--no-scripts', (self.root / 'composer-call.txt').read_text())
        call = json.loads((self.root / 'think-call.json').read_text())
        self.assertEqual(call['args'], ['service:discover'])
        self.assertEqual(call['cwd'], str(self.server))
        self.assertEqual(call['env_path'], str(self.env))
        self.assertEqual(call['tmpdir'], self.tmp.name)
        self.assertEqual(Path(self.tmp.name).parent,
                         SOURCE.parents[1] / '.local/tmp/local-core-service-discovery')
        self.assertEqual((self.lock.read_bytes(), self.manifest.read_bytes()), before)

    def test_autoload_refresh_discovers_services(self):
        self.assert_ready(self.run_action('autoload'))
        self.assertIn('dump-autoload', (self.root / 'composer-call.txt').read_text())

    def test_services_only_repairs_without_composer_or_manifest_projection(self):
        self.assert_ready(self.run_action('services'))
        self.assertFalse((self.root / 'composer-call.txt').exists())
        self.assertFalse((self.root / '.local/composer-core').exists())

    def test_services_check_missing_registry_does_not_write(self):
        result = self.run_action('services-check')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('LOCAL_APPLICATION_SERVICE_REGISTRY_NOT_READY', result.stderr)
        self.assertFalse(self.registry.exists())
        self.assertFalse((self.root / 'think-call.json').exists())
        self.assertFalse((self.root / '.local').exists())

    def test_services_check_valid_registry_is_read_only(self):
        self.assert_ready(self.run_action('services'))
        before = self.registry.read_bytes(), self.env.read_bytes(), self.lock.read_bytes()
        (self.root / 'think-call.json').unlink()
        self.assert_ready(self.run_action('services-check'))
        self.assertEqual((self.registry.read_bytes(), self.env.read_bytes(), self.lock.read_bytes()), before)
        self.assertFalse((self.root / 'think-call.json').exists())

    def test_stale_registry_fails_check_then_repairs(self):
        self.registry.write_text("<?php return [];\n")
        self.assertNotEqual(self.run_action('services-check').returncode, 0)
        self.assert_ready(self.run_action('services'))
        before = self.registry.read_bytes()
        self.assert_ready(self.run_action('services'))
        self.assertEqual(self.registry.read_bytes(), before)

    def test_composer_failure_does_not_discover_or_report_active(self):
        (self.root / 'composer-fails').touch()
        before = self.lock.read_bytes()
        result = self.run_action('install')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('local core active:', result.stdout)
        self.assertFalse((self.root / 'think-call.json').exists())
        self.assertEqual(self.lock.read_bytes(), before)

    def test_discovery_failure_is_not_ignored(self):
        (self.root / 'discovery-fails').touch()
        result = self.run_action('services')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('service discovery failed', result.stderr)
        self.assertNotIn('local core active:', result.stdout)

    def test_command_success_without_correct_registry_is_rejected(self):
        (self.root / 'wrong-registry').touch()
        result = self.run_action('services')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('did not produce a usable registry', result.stderr)
        self.assertNotIn('local core active:', result.stdout)

    def test_generated_registry_symlink_is_not_followed(self):
        outside = Path(self.tmp.name) / 'outside.php'
        outside.write_text('<?php return [];\n')
        self.registry.symlink_to(outside)
        result = self.run_action('services')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(outside.read_text(), '<?php return [];\n')
        self.assertFalse((self.root / 'think-call.json').exists())

    def test_missing_installed_metadata_is_rejected_before_discovery(self):
        self.installed.unlink()
        result = self.run_action('services')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / 'think-call.json').exists())

    def test_missing_multi_app_or_unloadable_service_is_not_ready(self):
        self.assert_ready(self.run_action('services'))
        (self.server / 'vendor/autoload.php').write_text('<?php namespace think\\app { class Service {} }')
        self.assertNotEqual(self.run_action('services-check').returncode, 0)
        self.installed.write_text('{"packages":[]}')
        self.registry.write_text('<?php return [];')
        self.assertNotEqual(self.run_action('services-check').returncode, 0)


if __name__ == '__main__':
    unittest.main(verbosity=2)
