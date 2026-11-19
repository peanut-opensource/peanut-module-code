#!/usr/bin/env python3
"""Synchronize module composer.json, frontend package.json, and modules.lock versions from module.json (single source of truth)."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def sync_json_version(path: Path, version: str) -> bool:
    if not path.is_file():
        return False
    data = json.loads(path.read_text('utf-8'))
    if data.get('version') == version:
        return False
    data['version'] = version
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + '\n', 'utf-8')
    return True


def main() -> int:
    versions: dict[str, str] = {}
    updated_files: list[str] = []

    for mj in sorted((ROOT / 'server/app/modules').glob('*/*/module.json')):
        meta = json.loads(mj.read_text('utf-8'))
        key = meta.get('key')
        ver = meta.get('version')
        if not isinstance(key, str) or not isinstance(ver, str):
            continue
        versions[key] = ver
        if sync_json_version(mj.parent / 'composer.json', ver):
            updated_files.append(str((mj.parent / 'composer.json').relative_to(ROOT)))
        slug = key.replace('.', '-')
        for fe_root in (
            ROOT / 'web/src/modules' / slug / 'package.json',
            ROOT / 'platform/src/modules' / slug / 'package.json',
            ROOT / 'uniapp/src/modules' / slug / 'package.json',
            ROOT / 'pc/modules' / slug / 'package.json',
        ):
            if sync_json_version(fe_root, ver):
                updated_files.append(str(fe_root.relative_to(ROOT)))

    lock_path = ROOT / 'modules.lock'
    if lock_path.is_file():
        lock = json.loads(lock_path.read_text('utf-8'))
        lock_changed = False
        for entry in lock.get('modules', []):
            if not isinstance(entry, dict):
                continue
            key = entry.get('key')
            if key not in versions:
                continue
            ver = versions[key]
            if entry.get('version') != ver:
                entry['version'] = ver
                lock_changed = True
            for bucket in ('npm', 'composer'):
                for pkg in entry.get(bucket) or []:
                    if isinstance(pkg, dict) and pkg.get('version') != ver:
                        pkg['version'] = ver
                        lock_changed = True
        if lock_changed:
            lock_path.write_text(json.dumps(lock, indent=4, ensure_ascii=False) + '\n', 'utf-8')
            updated_files.append('modules.lock')

    print(f'Synced {len(versions)} modules; updated {len(updated_files)} files.')
    for f in updated_files:
        print(f'  - {f}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
