#!/usr/bin/env python3
"""Regression for preparation failures after a framework exception handler is installed."""
from pathlib import Path
import os
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='peanut-prepare-exit-') as name:
    root = Path(name)
    def write(path, text):
        target = root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)
    write('.peanut/application-manifest.json', '{"application":{"edition":"standalone","version":"1.0.0"}}')
    write('server/docker/scripts/vendor-state.py', 'def check_lock(server): pass\n')
    write('server/vendor/autoload.php', r'''<?php
namespace app\common\value\installation {
    class ApplicationSourceIdentity {
        public static function loadGeneratedApp($root) {
            if (getenv('PREPARE_FAILURE') === 'identity') throw new \RuntimeException('identity rejected');
        }
    }
    class ApplicationReleaseVersions {
        public static function load($path) { return new self(); }
        public function toArray() { return ['core_web' => []]; }
    }
}
namespace app\common\infrastructure\scaffold {
    class ReleaseDependencyIdentity { public static function verifyArchives(...$args) {} }
}
namespace app\platform\infrastructure\plugin {
    class PluginLockResolver { public function __construct(...$args) {} public function all() { return []; } }
}
namespace app\common\module {
    class Dependencies {
        public function __construct(...$args) {}
        public function prepareRelease(...$args) {
            if (getenv('PREPARE_FAILURE') === 'build') throw new \RuntimeException('frontend failed');
            return ['operations' => [], 'frontend_adoption' => []];
        }
    }
}
''')
    write('server/database/install.php', r'''<?php
function ensureThinkPhpApplication($root) {
    set_exception_handler(function (\Throwable $error) { echo "framework rendered exception\n"; });
    return (object) ['config' => new class {
        public function get($key) { return 'standalone'; }
    }];
}
''')
    write('server/module', '<?php file_put_contents(getenv("PREPARE_COMPILE_MARKER"), "compiled"); echo "{}";\n')
    (root / 'scripts').mkdir()
    shutil.copy2(ROOT / 'scripts/deploy-update.sh', root / 'scripts/deploy-update.sh')
    subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
    marker = root / 'compile-marker'
    for failure in ('identity', 'build', ''):
        if marker.exists():
            marker.unlink()
        env = dict(os.environ, PREPARE_FAILURE=failure, PREPARE_COMPILE_MARKER=str(marker))
        result = subprocess.run(['bash', 'scripts/deploy-update.sh', '--prepare-release', '--version=1.0.0'],
                                cwd=root, env=env, text=True, capture_output=True, timeout=20)
        if failure:
            assert result.returncode != 0, (failure, result.stdout, result.stderr)
            assert '[deploy-update]' in result.stderr, result.stderr
            assert not marker.exists(), 'compile must not follow failed preparation'
            assert '[deploy-update] Prepared' not in result.stdout
        else:
            assert result.returncode == 0, result.stderr
            assert marker.exists() and '[deploy-update] Prepared' in result.stdout
        print(f'{failure or "success"}: passed')
