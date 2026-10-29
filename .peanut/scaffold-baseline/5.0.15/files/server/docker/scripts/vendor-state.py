#!/usr/bin/env python3
"""Seal and verify a Composer installation made from this server's fixed lock."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import zipfile


RECEIPT = ".peanut-complete.json"
SEMVER = r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
CORE_ARCHIVE = r'\.\./(packages/core-php/peanut-admin-core-(' + SEMVER + r')\.zip)'
SERVER_CORE_ARCHIVE = r'(\.peanut/packages/core-php/peanut-admin-core-(' + SEMVER + r')\.zip)'


def digest(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def ordinary(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1:
        raise ValueError(f"missing or unsafe file: {path}")


PROTECTED = ("runtime/", "public/storage/", "public/uploads/", "private/storage/",
             "private/installation/", "private/resources/", "docker/mysql/", "docker/secrets/",
             "vendor/", ".git/")
PROTECTED_FILES = {".env", "docker/.env"}


def release_path(value):
    if not isinstance(value, str) or not value.startswith("server/"):
        raise ValueError("release path is invalid")
    parts = value.split("/")
    if len(parts) < 2 or any(part in ("", ".", "..") for part in parts) or "\\" in value or any(ord(c) < 32 for c in value):
        raise ValueError("release path is ambiguous or unsafe")
    relative = value[len("server/"):]
    if relative in PROTECTED_FILES or any(relative == prefix[:-1] or relative.startswith(prefix) for prefix in PROTECTED):
        raise ValueError("release path enters protected instance data")
    return relative


def ordinary_parents(root, relative):
    cursor = root
    for part in Path(relative).parts[:-1]:
        cursor /= part
        if cursor.is_symlink() or (cursor.exists() and not cursor.is_dir()):
            raise ValueError("release parent is linked or not a directory")


def inventory(vendor, exclude_receipt=True):
    if vendor.is_symlink() or not vendor.is_dir():
        raise ValueError("vendor directory is missing or unsafe")
    result = {}
    for base, dirs, files in os.walk(vendor, followlinks=False, onerror=lambda error: (_ for _ in ()).throw(error)):
        for name in dirs:
            child = Path(base) / name
            if child.is_symlink() or not child.is_dir():
                raise ValueError("vendor contains a linked directory")
        for name in files:
            path = Path(base) / name
            if exclude_receipt and path == vendor / RECEIPT:
                continue
            ordinary(path)
            relative = path.relative_to(vendor).as_posix()
            result[relative] = [digest(path), stat.S_IMODE(path.stat().st_mode)]
    return dict(sorted(result.items()))


def module_packages(server):
    """Native local packages come from the application's selected Module roots."""
    path = server / 'plugins.lock'
    if not path.is_file():
        path = server.parent / 'plugins.lock'
    if not path.is_file():
        return {}
    ordinary(path)
    lock = json.loads(path.read_text())
    projected = lock.get('protocol') == 'peanut.server-plugin-lock.v1'
    root = server if projected else server.parent
    packages = {}
    for plugin in lock.get('plugins', []):
        for module in plugin.get('modules', []):
            relative = module['root']
            prefix = 'app/modules/' if projected else 'server/app/modules/'
            if not relative.startswith(prefix) or any(part in ('', '.', '..') for part in relative.split('/')):
                raise ValueError('Module Composer root is invalid')
            ordinary_parents(root, relative + '/composer.json')
            package_path = root / relative / 'composer.json'
            ordinary(package_path)
            package = json.loads(package_path.read_text())
            packages[package['name']] = {'version': package['version'],
                                         'url': (root / relative).relative_to(server).as_posix(),
                                         'development': {'name': 'peanut-module-dev/' + module['key'],
                                                         'version': package['version'], 'type': 'metapackage',
                                                         'require': package.get('require-dev', {})}}
    return packages


def native_module(package, modules):
    declared = modules.get(package.get('name'))
    dist = package.get('dist') or {}
    return declared is not None and package.get('version') == declared['version'] \
        and not package.get('source') and dist.get('type') == 'path' and dist.get('url') == declared['url']


def server_core_archives(server):
    """Consume the ordinary server release's owned, frozen seven-archive proof."""
    if server.resolve() != server:
        raise ValueError('server archive root is not canonical')
    identity_path = server / '.peanut/release-identity.json'
    ordinary_parents(server, '.peanut/release-identity.json'); ordinary(identity_path)
    if stat.S_IMODE(identity_path.stat().st_mode) != 0o644:
        raise ValueError('server release identity mode differs')
    identity = json.loads(identity_path.read_text())
    application = identity.get('application', {})
    if identity.get('schema_version') != 1 or identity.get('protocol') != 'peanut.server-release.v1' \
            or application.get('kind') != 'application' or application.get('scope') == 'internal-candidate' \
            or application.get('formal_release_eligible') is False or 'candidate_marker' in application \
            or application.get('edition') not in ('standalone', 'multi-tenant') \
            or any(not isinstance(application.get(key), str) or not application[key]
                   for key in ('slug', 'package_identity', 'version')) \
            or any(re.fullmatch(r'[a-f0-9]{40}', str(application.get(key, ''))) is None for key in ('commit', 'tree')):
        raise ValueError('fixed archives require an ordinary application release')
    files = identity.get('files')
    if not isinstance(files, list) or not files or hashlib.sha256(json.dumps(files, ensure_ascii=False,
            separators=(',', ':')).encode()).hexdigest() != identity.get('files_sha256'):
        raise ValueError('server release inventory is invalid')
    owned = {}; previous = ''
    for row in files:
        if not isinstance(row, dict) or list(row) != ['path', 'sha256', 'mode'] \
                or not isinstance(row['path'], str) or row['path'] <= previous \
                or row['mode'] not in (0o644, 0o755) or re.fullmatch(r'[a-f0-9]{64}', str(row['sha256'])) is None:
            raise ValueError('server release inventory row is invalid')
        previous = row['path']; owned[release_path(row['path'])] = row

    def read_owned(relative):
        row = owned.get(relative)
        ordinary_parents(server, relative); path = server / relative; ordinary(path)
        if row is None or row['mode'] != 0o644 or stat.S_IMODE(path.stat().st_mode) != row['mode'] \
                or digest(path) != row['sha256']:
            raise ValueError('fixed Core input differs from owned release bytes')
        return path.read_bytes()

    receipt = json.loads(read_owned('.peanut/core-inputs.json'))
    versions = json.loads(read_owned('.peanut/release-versions.json'))
    composer = json.loads(read_owned('composer.json')); lock = json.loads(read_owned('composer.lock'))
    php = receipt.get('core_php', {}); web = receipt.get('core_web', {})
    local = php.get('source_type') == 'local' and web.get('source_type') == 'local'
    local_php_identity = php.get('source_identity')
    local_web_identity = web.get('source_identity')
    local_sources_valid = isinstance(local_php_identity, dict) and set(local_php_identity) == {'kind', 'snapshot_sha256'} \
        and local_php_identity.get('kind') == 'local' and re.fullmatch(r'[a-f0-9]{64}', str(local_php_identity.get('snapshot_sha256', ''))) \
        and isinstance(local_web_identity, dict) and set(local_web_identity) == {'kind', 'snapshot_sha256'} \
        and local_web_identity.get('kind') == 'local' and re.fullmatch(r'[a-f0-9]{64}', str(local_web_identity.get('snapshot_sha256', ''))) \
        and receipt.get('sources', {}).get('php') == local_php_identity \
        and receipt.get('sources', {}).get('web') == local_web_identity
    git_sources_valid = php.get('source_type') == 'git' and web.get('source_type') == 'git' \
        and isinstance(receipt.get('sources'), dict) \
        and all(receipt['sources'].get(name, {}).get('commit') == declaration.get('source_reference')
                and all(re.fullmatch(r'[a-f0-9]{40}', str(receipt['sources'][name].get(key, ''))) for key in ('commit', 'tree'))
                for name, declaration in (('php', php), ('web', web)))
    if receipt.get('schema_version') != 1 or receipt.get('status') != 'frozen-core-build-inputs-not-product-release' \
            or versions.get('protocol') != 'peanut.release-versions.v3' \
            or versions.get('core_php') != php or versions.get('core_web') != web \
            or application['version'] != identity.get('versions', {}).get('release_sequence_version') \
            or php.get('package') != 'peanut-admin/core' or not (local_sources_valid if local else git_sources_valid) \
            or php.get('constraint') != php.get('resolved_version') \
            or re.fullmatch(SEMVER, str(php.get('resolved_version', ''))) is None \
            or (not local and (re.fullmatch(r'[a-f0-9]{40}', str(php.get('source_reference', ''))) is None
                or not str(php.get('source_url', '')).startswith('https://')
                or not str(web.get('source_url', '')).startswith('https://'))):
        raise ValueError('fixed Core release declaration differs')
    if local and application.get('formal_release_eligible') is True:
        raise ValueError('local Core input cannot be marked formally eligible')
    archive = receipt.get('core_php_archive', {})
    expected_php_path = 'packages/core-php/peanut-admin-core-' + php['resolved_version'] + '.zip'
    if set(archive) != {'path', 'sha256', 'sha1'} or archive['path'] != expected_php_path \
            or re.fullmatch(r'[a-f0-9]{40}', str(archive['sha1'])) is None:
        raise ValueError('fixed PHP archive declaration is invalid')
    packages = web.get('packages', {})
    names = ['@peanut-admin/' + name for name in ('client', 'vue', 'ui-vue', 'nuxt', 'uniapp', 'testing')]
    if not isinstance(packages, dict) or set(packages) != set(names):
        raise ValueError('fixed Web archive set differs')
    archives = {'.peanut/' + archive['path']: archive['sha256']}
    for name in names:
        value = packages[name]
        if not isinstance(value, dict) or set(value) != {'version', 'archive', 'sha256'} \
                or re.fullmatch(SEMVER, str(value['version'])) is None \
                or value['archive'] != 'packages/core-web/peanut-admin-' + name.split('/')[1] + '-' + value['version'] + '.tgz':
            raise ValueError('fixed Web archive identity is invalid')
        archives['.peanut/' + value['archive']] = value['sha256']
    actual_paths = set()
    for path in (server / '.peanut/packages').rglob('*'):
        if path.is_symlink() or (not path.is_dir() and not path.is_file()):
            raise ValueError('fixed Core archive path is unsafe')
        if path.is_file(): actual_paths.add(path.relative_to(server).as_posix())
    owned_paths = {path for path in owned if path.startswith('.peanut/packages/')}
    if len(archives) != 7 or actual_paths != set(archives) or owned_paths != set(archives):
        raise ValueError('fixed Core archive inventory differs')
    for relative, sha in archives.items():
        if re.fullmatch(r'[a-f0-9]{64}', str(sha)) is None or hashlib.sha256(read_owned(relative)).hexdigest() != sha:
            raise ValueError('fixed Core archive digest differs')
    relative = '.peanut/' + archive['path']
    reference = local_php_identity['snapshot_sha256'] if local else php['source_reference']
    locked = [package for package in lock.get('packages', []) if package.get('name') == 'peanut-admin/core']
    repository = receipt.get('composer_repository')
    if not isinstance(repository, dict) or repository.get('type') != 'package':
        raise ValueError('fixed PHP repository proof is missing')
    repository = dict(repository); package = dict(repository.get('package', {})); dist = dict(package.get('dist', {}))
    if dist.get('url') != '../' + archive['path']:
        raise ValueError('fixed PHP original repository origin differs')
    dist['url'] = relative; package['dist'] = dist; repository['package'] = package
    expected_dist = {'type': 'zip', 'url': relative, 'reference': reference, 'shasum': archive['sha1']}
    expected_source = None if local else {'type': 'git', 'url': php['source_url'], 'reference': reference}
    modules = module_packages(server)
    module_urls = {package['url'] for package in modules.values()}
    development = [package['development'] for package in modules.values()]
    repositories = composer.get('repositories', [])
    repositories = repositories.values() if isinstance(repositories, dict) else repositories
    core_repositories = [entry for entry in repositories
                         if not (entry.get('type') == 'path' and entry.get('url') in module_urls)
                         and not (entry.get('type') == 'package' and entry.get('package') in development)]
    if len(locked) != 1 or locked[0].get('version') != php['resolved_version'] \
            or locked[0].get('source') != expected_source \
            or locked[0].get('dist') != expected_dist or core_repositories != [repository] \
            or package.get('name') != php['package'] or package.get('version') != php['resolved_version'] \
            or package.get('source') != locked[0].get('source') or dist != expected_dist \
            or composer.get('require', {}).get('peanut-admin/core') != php['constraint'] \
            or hashlib.sha1((server / relative).read_bytes()).hexdigest() != archive['sha1']:
        raise ValueError('fixed PHP native lock/repository origin differs')
    return {relative: {'sha256': archive['sha256'], 'sha1': archive['sha1'],
                       ('source_identity' if local else 'source_reference'): (local_php_identity if local else reference),
                       'version': php['resolved_version']}}


def archive_path(server, relative):
    return server / relative if re.fullmatch(SERVER_CORE_ARCHIVE, relative) else server.parent / relative


def local_archives(server, inputs=None):
    """Only the native Core zip may use a local dist, with independent canonical proof."""
    lock = json.loads((server / 'composer.lock').read_text())
    if any(re.fullmatch(SERVER_CORE_ARCHIVE, str(package.get('dist', {}).get('url', '')))
           for package in lock['packages']):
        if inputs is not None:
            raise ValueError('ordinary server release cannot use a source transaction proof')
        return server_core_archives(server)
    result = {}
    proof = None
    if inputs is not None:
        ordinary(inputs)
        if inputs != server / '.vendor-inputs.json' or server.resolve() != server or server.stat().st_mode & 0o077 \
                or inputs.stat().st_mode & 0o777 != 0o600 or inputs.stat().st_uid not in (0, os.geteuid()):
            raise ValueError('dependency input proof is not protected')
        proof = json.loads(inputs.read_text())
        workspace = server.parents[2]
        component = server.parent.parent.name
        authority_path = workspace / ('tool-inputs.json' if component == 'tools' else 'source-runtime.json')
        key = 'inputs' if component == 'tools' else 'vendor_target_inputs'
        if component not in ('tools', 'dependencies') or server.parent.name != 'prepared' \
                or workspace.resolve() != workspace or workspace.stat().st_mode & 0o077:
            raise ValueError('dependency proof is outside its protected transaction')
        ordinary_parents(workspace, authority_path.name); ordinary(authority_path)
        if authority_path.stat().st_mode & 0o777 != 0o600 or authority_path.stat().st_uid not in (0, os.geteuid()):
            raise ValueError('dependency authority is not protected')
        authority = json.loads(authority_path.read_text())
        if component == 'dependencies':
            checksum = authority.pop('state_sha256', None)
            canonical = json.dumps(authority, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()
            if authority.get('protocol') != 'peanut.source-runtime-transition.v1' or checksum != hashlib.sha256(canonical).hexdigest() \
                    or authority.get('activation_started') is not False or authority.get('binding', {}).get('workspace') != str(workspace):
                raise ValueError('dependency transaction checkpoint is invalid')
        elif authority.get('protocol') != 'peanut.source-tools-inputs.v1':
            raise ValueError('tool dependency authority is invalid')
        declared = authority.get(key, {})
        if proof.get('protocol') != 'peanut.source-vendor-inputs.v1' or proof.get('server_root') != str(server) \
                or proof.get('composer_sha256') != digest(server / 'composer.json') \
                or proof.get('lock_sha256') != digest(server / 'composer.lock') \
                or any(proof.get(field) != declared.get(field) for field in ('server_root', 'composer_sha256', 'lock_sha256', 'archives')):
            raise ValueError('dependency input proof differs from its transaction inputs')
    modules = module_packages(server)
    for package in lock['packages']:
        if native_module(package, modules):
            continue
        dist = package.get('dist') or {}
        url = str(dist.get('url', ''))
        if not url or url.startswith('https://'):
            continue
        match = re.fullmatch(CORE_ARCHIVE, url)
        if package.get('name') != 'peanut-admin/core' or dist.get('type') != 'zip' or match is None:
            raise ValueError('only the canonical native Core archive may be local')
        relative = match.group(1)
        source = package.get('source') or {}
        local_core = not source
        reference = dist.get('reference') if local_core else source.get('reference')
        if local_core:
            if not re.fullmatch(r'[a-f0-9]{64}', str(reference)):
                raise ValueError('local Core source identity is invalid')
        elif not re.fullmatch(r'[a-f0-9]{40}', str(reference)) or dist.get('reference') != reference \
                or source.get('type') != 'git' or not str(source.get('url', '')).startswith('https://'):
            raise ValueError('local Core source identity is invalid')
        root = server.parent
        if root.resolve() != root or root.is_symlink():
            raise ValueError('Core archive root is not canonical')
        ordinary_parents(root, relative)
        archive = root / relative
        ordinary(archive)
        if proof is not None:
            expected = proof.get('archives', {}).get(relative)
        else:
            for name in ('.peanut/application-manifest.json', 'release-versions.json'):
                ordinary_parents(root, name); ordinary(root / name)
            manifest = json.loads((root / '.peanut/application-manifest.json').read_text())
            versions = json.loads((root / 'release-versions.json').read_text())
            core = versions.get('core_php', {})
            entries = [row for row in manifest.get('files', []) if row.get('path') == relative]
            declared_local = core.get('source_type') == 'local'
            local_identity = core.get('source_identity')
            if declared_local:
                if not isinstance(local_identity, dict) or set(local_identity) != {'kind', 'snapshot_sha256'} \
                        or local_identity.get('kind') != 'local' \
                        or not re.fullmatch(r'[a-f0-9]{64}', str(local_identity.get('snapshot_sha256', ''))) \
                        or not local_core or dist.get('reference') != local_identity['snapshot_sha256']:
                    raise ValueError('local Core source identity is invalid')
                reference = local_identity
            else:
                if local_core or not re.fullmatch(r'[a-f0-9]{40}', str(reference)):
                    raise ValueError('local Core source identity is invalid')
            if manifest.get('protocol') != 'peanut.application-scaffold.v2' or versions.get('protocol') != 'peanut.release-versions.v3' \
                    or len(entries) != 1 or entries[0].get('classification') != 'managed' \
                    or core.get('package') != package['name'] or core.get('resolved_version') != package['version'] \
                    or (not declared_local and (core.get('source_reference') != reference or core.get('source_url') != source.get('url'))):
                raise ValueError('Core archive is not bound to the application declaration')
            expected = {'sha256': entries[0].get('sha256'), 'sha1': dist.get('shasum'),
                        ('source_identity' if declared_local else 'source_reference'): reference,
                        'version': package['version']}
        if proof is not None:
            actual_reference = {'kind': 'local', 'snapshot_sha256': reference} if local_core else reference
            key = 'source_identity' if local_core else 'source_reference'
        else:
            key = 'source_identity' if declared_local else 'source_reference'
            actual_reference = reference
        actual = {'sha256': digest(archive), 'sha1': hashlib.sha1(archive.read_bytes()).hexdigest(),
                  key: actual_reference, 'version': package['version']}
        if expected != actual or not re.fullmatch(r'[a-f0-9]{40}', str(dist.get('shasum', ''))) \
                or actual['sha1'] != dist['shasum'] or match.group(2) != package['version'] \
                or archive.stat().st_mode & 0o777 != 0o644:
            raise ValueError('Core archive digest, version or mode differs from canonical inputs')
        result[relative] = actual
    if proof is not None and (proof.get('archives') or {}) != result:
        raise ValueError('dependency proof contains missing or extra archives')
    return result


def check_lock(server, inputs=None):
    composer, lock = server / "composer.json", server / "composer.lock"
    ordinary(composer)
    ordinary(lock)
    data = json.loads(lock.read_text())
    if not isinstance(data.get("packages"), list) or not re.fullmatch(r"[a-f0-9]{32}", data.get("content-hash", "")):
        raise ValueError("composer.lock is invalid")
    modules = module_packages(server)
    for package in data["packages"]:
        if not re.fullmatch(r"[a-z0-9_.-]+/[a-z0-9_.-]+", package.get("name", "")):
            raise ValueError("locked package identity is invalid")
        if native_module(package, modules):
            continue
        for source in ("dist", "source"):
            value = package.get(source)
            if value and not str(value.get("url", "")).startswith("https://") and not (
                    source == 'dist' and package['name'] == 'peanut-admin/core'
                    and (re.fullmatch(CORE_ARCHIVE, str(value.get('url', '')))
                         or re.fullmatch(SERVER_CORE_ARCHIVE, str(value.get('url', ''))))):
                raise ValueError("locked package has a non-HTTPS source")
    local_archives(server, inputs)
    return digest(composer), digest(lock), {p["name"]: p["version"] for p in data["packages"]}


def check_installed(server, expected):
    vendor = server / "vendor"
    ordinary(vendor / "autoload.php")
    installed_path = vendor / "composer/installed.json"
    ordinary(installed_path)
    data = json.loads(installed_path.read_text())
    packages = data.get("packages") if isinstance(data, dict) else data
    if not isinstance(packages, list):
        raise ValueError("Composer installed metadata is invalid")
    actual = {p.get("name"): p.get("version") for p in packages}
    if len(actual) != len(packages) or actual != expected:
        raise ValueError("installed Composer packages differ from composer.lock")
    locked = [p for p in json.loads((server / 'composer.lock').read_text())['packages'] if p['name'] == 'peanut-admin/core']
    core = [p for p in packages if p.get('name') == 'peanut-admin/core']
    if len(locked) != 1 or len(core) != 1 or core[0].get('source') != locked[0].get('source') \
            or (core[0].get('dist') or {}).get('reference') != (locked[0].get('dist') or {}).get('reference'):
        raise ValueError('installed Core source differs from the fixed lock')
    relative = (locked[0].get('dist') or {}).get('url', '')
    if re.fullmatch(SERVER_CORE_ARCHIVE, str(relative)):
        root = vendor / 'peanut-admin/core'
        ordinary_parents(vendor, 'peanut-admin/core/composer.json')
        if not root.is_dir() or root.is_symlink() or root.resolve() != root \
                or core[0].get('dist') != locked[0]['dist']:
            raise ValueError('installed fixed Core location or dist differs')
        # Read the authenticated ZIP without extracting or executing package code.
        with zipfile.ZipFile(server / relative) as archive:
            entries = []
            for member in archive.infolist():
                path = Path(member.filename)
                if path.is_absolute() or any(part in ('', '.', '..') for part in member.filename.rstrip('/').split('/')) \
                        or '\\' in member.filename or stat.S_ISLNK(member.external_attr >> 16):
                    raise ValueError('fixed Core ZIP path is unsafe')
                if member.is_dir(): continue
                mode = stat.S_IFMT(member.external_attr >> 16)
                if mode not in (0, stat.S_IFREG):
                    raise ValueError('fixed Core ZIP member is not a regular file')
                entries.append((member.filename, archive.read(member)))
        if not any(name == 'composer.json' for name, _ in entries):
            prefixes = {name.split('/')[0] for name, _ in entries}
            if len(prefixes) != 1 or any('/' not in name for name, _ in entries):
                raise ValueError('fixed Core ZIP root differs')
            entries = [(name.split('/', 1)[1], data) for name, data in entries]
        seen = set()
        for name, data in entries:
            if name in seen or Path(name).as_posix() != name:
                raise ValueError('fixed Core ZIP has duplicate or ambiguous members')
            seen.add(name); ordinary_parents(root, name); path = root / name; ordinary(path)
            if path.read_bytes() != data:
                raise ValueError('installed fixed Core payload differs')
        actual = inventory(root, exclude_receipt=False)
        if set(actual) != seen or json.loads((root / 'composer.json').read_text()).get('name') != 'peanut-admin/core':
            raise ValueError('installed fixed Core payload inventory differs')


def freeze_tool_metadata(server, inputs):
    # Freeze only generated Composer metadata, after a genuine fixed-lock install.
    # Package code is independently installed, never copied from the APP vendor.
    if inputs is None or server.parent.parent.name != 'tools': return
    authority = json.loads((server.parents[2] / 'tool-inputs.json').read_text())['inputs']
    for relative in authority['tool_metadata']:
        if relative != 'autoload.php' and not re.fullmatch(r'composer/(?:(?:autoload_[A-Za-z0-9_]+|installed|platform_check)\.php|installed\.json)', relative):
            raise ValueError('invalid frozen Composer metadata path')
        source = server / '.tool-metadata' / relative
        proof = authority['files']['.tool-metadata/' + relative]
        ordinary_parents(server, '.tool-metadata/' + relative); ordinary(source)
        target = server / 'vendor' / relative
        ordinary_parents(server / 'vendor', relative); ordinary(target)
        if digest(source) != proof['sha256'] or stat.S_IMODE(source.stat().st_mode) != proof['mode']:
            raise ValueError('frozen tool metadata differs from its input authority')
        with target.open('wb') as output:
            os.fchmod(output.fileno(), proof['mode']); output.write(source.read_bytes()); output.flush(); os.fsync(output.fileno())


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=("seal", "check", "stage"))
    parser.add_argument("--server", type=Path, required=True)
    parser.add_argument("--destination", type=Path)
    parser.add_argument('--inputs', type=Path)
    args = parser.parse_args()
    server = args.server
    if server.is_symlink() or not server.is_dir():
        raise ValueError("server root is missing or unsafe")
    if args.operation == "stage":
        destination = args.destination
        if destination is None or not destination.is_absolute() or destination.exists() or destination.is_symlink():
            raise ValueError("new ordinary staging directory is required")
        if destination.parent.is_symlink() or not destination.parent.is_dir() or destination.parent.resolve() != destination.parent:
            raise ValueError("staging parent is unsafe")
        development = server / ".peanut/development-runtime.json"
        development_runtime = development.exists() or development.is_symlink()
        if development_runtime and ((server / ".peanut/release-identity.json").exists() or (server / ".peanut/release-identity.json").is_symlink()):
            raise ValueError("runtime identity conflicts with a release identity")
        identity_relative = ".peanut/development-runtime.json" if development_runtime else ".peanut/release-identity.json"
        identity_path = server / identity_relative
        ordinary_parents(server, identity_relative)
        ordinary(identity_path)
        identity = json.loads(identity_path.read_text())
        protocol = "peanut.development-server-runtime.v1" if development_runtime else "peanut.server-release.v1"
        if identity.get("schema_version") != 1 or identity.get("protocol") != protocol or not isinstance(identity.get("application"), dict):
            raise ValueError("server release identity is invalid")
        if development_runtime and identity.get("environment") != "development":
            raise ValueError("development runtime environment is invalid")
        files = identity.get("files")
        if not isinstance(files, list) or not files:
            raise ValueError("release file list is invalid")
        canonical = json.dumps(files, ensure_ascii=False, separators=(",", ":")).encode()
        if hashlib.sha256(canonical).hexdigest() != identity.get("files_sha256"):
            raise ValueError("release file list digest is invalid")
        previous = ""
        checked = []
        for row in files:
            if not isinstance(row, dict) or list(row) != ["path", "sha256", "mode"]:
                raise ValueError("release file row is invalid")
            relative = release_path(row["path"])
            if row["path"] <= previous or row["mode"] not in (0o644, 0o755) or not re.fullmatch(r"[a-f0-9]{64}", str(row["sha256"])):
                raise ValueError("release file order, mode or digest is invalid")
            previous = row["path"]
            source = server / relative
            ordinary_parents(server, relative)
            ordinary(source)
            if stat.S_IMODE(source.stat().st_mode) != row["mode"] or digest(source) != row["sha256"]:
                raise ValueError("release program file changed")
            checked.append((relative, source, row["mode"], row["sha256"]))
        destination.mkdir(mode=0o700)
        for relative, source, mode, expected_sha in checked:
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
            os.chmod(target, mode)
            if digest(target) != expected_sha:
                raise ValueError("release program changed during staging")
        target = destination / identity_relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(identity_path, target)
        archives = local_archives(server, args.inputs)
        for relative, proof in archives.items():
            if re.fullmatch(SERVER_CORE_ARCHIVE, relative):
                # All seven archives and their proof were copied as owned server files.
                continue
            target_archive = destination.parent / relative
            ordinary_parents(destination.parent, relative)
            target_archive.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            if target_archive.exists() or target_archive.is_symlink():
                raise ValueError('dependency archive staging path is occupied')
            shutil.copy2(server.parent / relative, target_archive)
            if digest(target_archive) != proof['sha256']:
                raise ValueError('dependency archive changed while staging')
        if archives and not any(re.fullmatch(SERVER_CORE_ARCHIVE, relative) for relative in archives):
            for relative in ('.peanut/application-manifest.json', 'release-versions.json'):
                ordinary_parents(server.parent, relative); ordinary(server.parent / relative)
                target = destination.parent / relative
                target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                shutil.copy2(server.parent / relative, target)
        check_lock(destination)
        print(json.dumps({"status": "staged", "file_count": len(files)}))
        return
    composer_sha, lock_sha, packages = check_lock(server, args.inputs)
    check_installed(server, packages)
    if args.operation == 'seal':
        freeze_tool_metadata(server, args.inputs)
        check_installed(server, packages)
    vendor = server / "vendor"
    manifest = inventory(vendor)
    archives = local_archives(server, args.inputs)
    installed = json.loads((vendor / 'composer/installed.json').read_text())
    installed = installed['packages'] if isinstance(installed, dict) else installed
    if archives:
        locked = next(p for p in json.loads((server / 'composer.lock').read_text())['packages'] if p['name'] == 'peanut-admin/core')
        actual = [p for p in installed if p.get('name') == 'peanut-admin/core']
        if len(actual) != 1 or actual[0].get('source') != locked.get('source') \
                or (actual[0].get('dist') or {}).get('reference') != locked['dist']['reference']:
            raise ValueError('installed local Core source differs from its lock')
    expected = {'protocol': 'peanut.composer-install.v1', 'composer_sha256': composer_sha,
                'lock_sha256': lock_sha, 'files': manifest}
    if archives:
        expected['local_archives'] = archives
    receipt_path = vendor / RECEIPT
    if args.operation == "seal":
        if receipt_path.exists() or receipt_path.is_symlink():
            raise ValueError("vendor already has a receipt; prepare into an empty directory")
        payload = expected
        temp = vendor / (RECEIPT + ".tmp")
        descriptor = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, "w") as output:
            json.dump(payload, output, sort_keys=True, separators=(",", ":"))
            output.flush()
            os.fsync(output.fileno())
        os.replace(temp, receipt_path)
        fd = os.open(vendor, os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    else:
        ordinary(receipt_path)
        payload = json.loads(receipt_path.read_text())
        if payload != expected:
            raise ValueError("vendor installation is incomplete or changed")
    print(json.dumps({"status": "complete", "lock_sha256": lock_sha, "file_count": len(manifest)}))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, json.JSONDecodeError) as error:
        print(f"vendor-state: {error}", file=sys.stderr)
        sys.exit(1)
