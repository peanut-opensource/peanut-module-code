"""Exercise the reviewed native ReferenceCodes parent path in isolated SQLite fixtures.

Requires explicit immutable Code/Core commits and an existing locked vendor root.
No package installation, business database, migrated schema, or A worktree write.
"""
from __future__ import annotations

import argparse
from contextlib import ExitStack
import hashlib
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_parent_runtime', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-ref', required=True)
    parser.add_argument('--php-core-root', required=True)
    parser.add_argument('--php-core-ref', required=True)
    parser.add_argument('--vendor-root', required=True)
    parser.add_argument('--case', action='append', choices=['baseline', 'missing-parent', 'foreign-parent', 'foreign-link',
                        'page-baseline', 'page-missing-parent', 'page-foreign-parent', 'page-foreign-set',
                        'page-corrupt-outside-page', 'page-callback-failure'])
    args = parser.parse_args()
    vendor = Path(args.vendor_root).resolve(strict=True)
    if not (vendor / 'autoload.php').is_file():
        raise ValueError('TPQ_PARENT_VENDOR_MISSING')
    def blob(path):
        return subprocess.check_output(['git', '--no-replace-objects', 'show', args.source_ref + ':' + path], cwd=ROOT)
    with ExitStack() as stack:
        stack.enter_context(checker.php_core_snapshot(args.php_core_root, args.php_core_ref))
        stack.enter_context(checker.ownership_source_snapshot(args.source_ref))
        target = checker.ROOT
        support = 'server/tests/Support/ThinkPhpTestConnection.php'
        # Materialize the exact declaration-only support fixture, not the
        # application checkout adjacent to a reused vendor directory.
        support_path = target / support
        support_path.parent.mkdir(parents=True, exist_ok=True)
        support_path.write_bytes(blob(support))
        fixture = 'server/tests/Unit/ReferenceCodeSnapshotBoundaryTest.php'
        path = target / fixture
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(blob(fixture))
        (target / 'server/vendor').symlink_to(vendor, target_is_directory=True)
        prefix = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\'
        classes = {prefix + 'ReferenceCodeStore': 'server/app/modules/official/reference_codes/src/Versioned/Persistence/ReferenceCodeStore.php',
                   prefix + 'Model\\ReferenceCodeEntryRecord': 'server/app/modules/official/reference_codes/src/Versioned/Persistence/Model/ReferenceCodeEntryRecord.php',
                   prefix + 'Model\\ReferenceCodeEntryVersionRecord': 'server/app/modules/official/reference_codes/src/Versioned/Persistence/Model/ReferenceCodeEntryVersionRecord.php',
                   'PeanutAdmin\\Modules\\Identity\\Contract\\AdminDirectoryQuery': 'server/app/modules/official/identity/src/Contract/AdminDirectoryQuery.php',
                   'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Application\\ReferenceCodeQuery': 'server/app/modules/official/reference_codes/src/Versioned/Application/ReferenceCodeQuery.php'}
        classes = {key: str(target / path) for key, path in classes.items()}
        classes['PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'] = str(checker.PHP_CORE_ROOT / 'kernel/src/Persistence/Model/TenantModel.php')
        classes['SharedPdoDbManager'] = str(support_path)
        lock = json.loads(blob('server/composer.lock'))
        packages = {row['name']: (row.get('source') or row.get('dist'))['reference']
                    for row in lock['packages'] + lock.get('packages-dev', [])
                    if row['name'] in {'topthink/framework', 'topthink/think-orm', 'phpunit/phpunit'}}
        if len(packages) != 3:
            raise ValueError('TPQ_PARENT_DEPENDENCY_LOCK_INCOMPLETE')
        (target / 'runtime-source-proof.json').write_text(json.dumps({'classes': classes, 'sha256': {p: hashlib.sha256(Path(p).read_bytes()).hexdigest() for p in classes.values()}, 'packages': packages}))
        env = os.environ.copy()
        env.update(TPQ_SOURCE_ROOT=str(target), TPQ_CORE_SOURCE_ROOT=str(checker.PHP_CORE_ROOT), TPQ_VENDOR_ROOT=str(vendor))
        cases = args.case or ['baseline', 'missing-parent', 'foreign-parent', 'foreign-link']
        for scenario in cases:
            result = subprocess.run([env.get('TPQ_PHP_BINARY', 'php'), str(ROOT / 'scripts/tests/thinkphp-parent-runtime-test.php'), scenario], env=env, cwd=target, capture_output=True, text=True, timeout=30)
            print(result.stdout, end='')
            if result.returncode:
                print(result.stderr, file=sys.stderr, end='')
                return result.returncode
        print(json.dumps({'tests': len(cases), 'cases': cases, 'status': 'passed', 'code': args.source_ref, 'php_core': args.php_core_ref,
                          'fixture_sha256': hashlib.sha256(blob(fixture)).hexdigest(), 'packages': packages}))
    return 0


if __name__ == '__main__':
    sys.exit(main())
