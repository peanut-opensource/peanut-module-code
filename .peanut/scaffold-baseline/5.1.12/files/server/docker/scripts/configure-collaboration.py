#!/usr/bin/env python3
"""Publish collaboration configuration; deployment remains owner-controlled."""
import argparse
import contextlib
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
from urllib.parse import urlsplit

PROTOCOL = 'peanut.collaboration.configuration.v1'
# Default up follows mysql -> php -> nginx healthy dependencies. Including probe
# duration, compose.yaml budgets mysql 320s + php 190s; allow 90s for orchestration.
# Only explicit application startup invokes Compose through this module.
STARTUP_COMMAND_TIMEOUT = 600
IMAGE = re.compile(r'(?:sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})\Z')
CONFIG_KEYS = {'protocol', 'schema_version', 'instance_id', 'edition', 'service_instance_id', 'key_id', 'image',
               'php_origin', 'control_origin', 'wss_hostname', 'wss_bind', 'wss_port', 'config_revision', 'projector_version', 'ca_sha256',
               'service_root', 'compose_project', 'network', 'secret', 'websocket_scheme'}
SERVICE_DIRECTORY = 'private/collaboration/service'
SERVICE_CA = SERVICE_DIRECTORY + '/ca.pem'
PUBLICATION_KEYS = {'COLLABORATION_CONFIG_REVISION', 'COLLABORATION_CONFIG_SHA256',
                    'COLLABORATION_SERVICE_ROOT', 'COLLABORATION_COMPOSE_PROJECT'}

def public_wss_url(data):
    hostname=data.get('wss_hostname')
    port=data.get('wss_port')
    if not isinstance(hostname,str) or len(hostname)>253 or not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*',hostname) or type(port) is not int or not 1<=port<=65535:
        raise ValueError('COLLABORATION_WSS_IDENTITY_INVALID')
    if data.get('websocket_scheme') not in ('ws', 'wss'):
        raise ValueError('COLLABORATION_WEBSOCKET_SCHEME_INVALID')
    return data['websocket_scheme']+'://'+hostname.lower()+':'+str(port)+'/collaboration'


def digest(data):
    return hashlib.sha256(data).hexdigest()


def ordinary(path, private=True):
    path = Path(path)
    if not path.exists() and not path.is_symlink():
        raise FileNotFoundError('COLLABORATION_CONFIGURATION_REFERENCE_UNAVAILABLE')
    if not path.is_absolute() or path.resolve() != path or not path.is_file() or path.is_symlink():
        raise ValueError('COLLABORATION_FILE_UNSAFE')
    stat = path.stat()
    if stat.st_nlink != 1 or (private and stat.st_mode & 0o777 != 0o600) or (not private and stat.st_mode & 0o022):
        raise ValueError('COLLABORATION_FILE_PERMISSIONS_INVALID')
    return stat


def environment(path, payload=None, compose=False):
    ordinary(path)
    rows = {}
    for line in (path.read_text() if payload is None else payload.decode()).split('\n'):
        if not line or line.startswith('#'):
            continue
        key, separator, value = line.partition('=')
        if not separator or key in rows or not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
            raise ValueError('COLLABORATION_ENVIRONMENT_INVALID')
        if compose and value.startswith('"'):
            # Published Compose values are single-quoted. Accept only simple
            # literal double quotes from initial templates, never JSON escapes
            # or process interpolation that this reader cannot attest.
            if not value.endswith('"') or len(value) < 2 or any(character in value[1:-1] for character in ('\\', '$', '"')):
                raise ValueError('COLLABORATION_ENVIRONMENT_INVALID')
            value = value[1:-1]
        elif compose and value.startswith("'") and value.endswith("'"):
            value = value[1:-1].replace("\\'", "'")
        elif compose and (value.startswith("'") or '$' in value or re.search(r'\s+#', value)):
            raise ValueError('COLLABORATION_ENVIRONMENT_INVALID')
        elif compose:
            value = value.strip(' \t')
        elif not compose:
            # Only the publisher/native installer's flat INI_RAW forms are read.
            # JSON escapes are literal bytes here; hand-edited inline comments
            # are rejected instead of being mistaken for PHP's actual value.
            value = value.strip(' \t')
            if value.startswith('"'):
                if not value.endswith('"') or len(value) < 2:
                    raise ValueError('COLLABORATION_ENVIRONMENT_INVALID')
                value = value[1:-1]
            elif ';' in value:
                raise ValueError('COLLABORATION_ENVIRONMENT_INVALID')
        rows[key] = value
    return rows


def backend_environment(server):
    path=server / '.env'
    return environment(path) if path.exists() or path.is_symlink() else {}


def canonical_root(root):
    if not root.is_absolute() or root.resolve() != root or root.is_symlink() or not (root / 'server/docker/compose.yaml').is_file():
        raise ValueError('COLLABORATION_APP_ROOT_INVALID')
    for relative in ('server', 'server/docker', 'server/private'):
        if (root / relative).is_symlink():
            raise ValueError('COLLABORATION_PARENT_LINKED')
    return root / 'server'


def atomic(path, payload, expected=None, owner=None, private=True):
    if path.exists() or path.is_symlink():
        ordinary(path, private)
        if expected is not None and digest(path.read_bytes()) != expected:
            raise ValueError('COLLABORATION_CONFIGURATION_CAS_CONFLICT')
    elif expected is not None:
        raise ValueError('COLLABORATION_CONFIGURATION_CAS_CONFLICT')
    if path.parent.is_symlink() or path.parent.resolve() != path.parent:
        raise ValueError('COLLABORATION_PARENT_LINKED')
    fd, name = tempfile.mkstemp(prefix='.' + path.name + '-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            os.fchmod(stream.fileno(), 0o600 if private else 0o644)
            if owner is not None:
                os.fchown(stream.fileno(), owner.st_uid, owner.st_gid)
            stream.write(payload); stream.flush(); os.fsync(stream.fileno())
        os.replace(name, path)
        descriptor = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
    finally:
        if os.path.exists(name):
            os.unlink(name)


@contextlib.contextmanager
def locked(server):
    path = server / '.env.collaboration.lock'
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        st = os.fstat(fd)
        if st.st_nlink != 1 or st.st_mode & 0o777 != 0o600 or st.st_uid != os.geteuid():
            raise ValueError('COLLABORATION_LOCK_UNSAFE')
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)


def validate_configuration(data, server):
    if set(data) != CONFIG_KEYS or data['protocol'] != 'peanut.collaboration.configuration.v1' or data['schema_version'] != 1:
        raise ValueError('COLLABORATION_CONFIGURATION_PROTOCOL_INVALID')
    for key in ('instance_id', 'service_instance_id', 'key_id'):
        if not isinstance(data[key], str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,128}', data[key]):
            raise ValueError('COLLABORATION_IDENTITY_INVALID')
    if data['edition'] not in ('engine', 'standalone', 'multi-tenant') or data['projector_version'] != 'peanut.richtext-yjs/1':
        raise ValueError('COLLABORATION_COMPATIBILITY_INVALID')
    if type(data['config_revision']) is not int or data['config_revision'] < 1 or not IMAGE.fullmatch(data['image']):
        raise ValueError('COLLABORATION_IMAGE_REVISION_INVALID')
    root = Path(data['service_root'])
    if not root.is_absolute() or root.resolve() != root or root.is_symlink() or not (root / 'compose.yaml').is_file():
        raise ValueError('COLLABORATION_INDEPENDENT_SERVICE_REQUIRED')
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}', data['compose_project']) or not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}', data['network']):
        raise ValueError('COLLABORATION_INDEPENDENT_SERVICE_BINDING_INVALID')
    if data['image'] != 'node:22.23.2-bookworm-slim@sha256:48e4b67d85f87bd551df43704e24d252f56cc5f8e9718841aace50f19948f0f9':
        raise ValueError('COLLABORATION_FIXED_RUNTIME_IMAGE_REQUIRED')
    tls = data['websocket_scheme'] == 'wss'
    if not tls and backend_environment(server).get('APP_ENV') != 'development':
        raise ValueError('COLLABORATION_DEVELOPMENT_TRANSPORT_REQUIRED')
    for key in ('php_origin', 'control_origin'):
        url = urlsplit(data[key])
        if url.scheme != ('https' if tls else 'http') or not url.hostname or url.username or url.password or url.path not in ('', '/') or url.query or url.fragment \
                or (tls and url.port != 8443) or (url.port is not None and not 1 <= url.port <= 65535) \
                or re.search(r'[\x00-\x20\x7f\\]', data[key]):
            raise ValueError('COLLABORATION_PRIVATE_ORIGIN_INVALID')
    public_wss_url(data)
    if ipaddress.ip_address(data['wss_bind']).version != 4:
        raise ValueError('COLLABORATION_WSS_BIND_INVALID')
    if not isinstance(data['secret'], str) or not 32 <= len(data['secret'].encode('utf-8')) <= 4096 or any(c in data['secret'] for c in ('\n', '\r', '\0')):
        raise ValueError('COLLABORATION_SECRET_INVALID')
    if not isinstance(data['ca_sha256'], str) or (data['ca_sha256'] and not re.fullmatch(r'[a-f0-9]{64}', data['ca_sha256'])):
        raise ValueError('COLLABORATION_CA_REFERENCE_INVALID')
    if not tls:
        if data['ca_sha256'] != '':
            raise ValueError('COLLABORATION_HTTP_CA_NOT_APPLICABLE')
        if not ipaddress.ip_address(data['wss_bind']).is_loopback:
            raise ValueError('COLLABORATION_DEVELOPMENT_LOOPBACK_REQUIRED')
        service = environment(root / '.env', compose=True)
        if service.get('PORT', '1234') != str(data['wss_port']) or service.get('BIND_ADDRESS', '127.0.0.1') != data['wss_bind']:
            raise ValueError('COLLABORATION_DIRECT_LISTENER_MISMATCH')
        return data
    ordinary(server / 'docker/tls/private-key.pem')
    ordinary(server / 'docker/tls/certificate.pem', False)
    if data['ca_sha256']:
        ca=server/'docker/tls/ca.pem'
        ordinary(ca, False)
        if ca.stat().st_mode&0o777 not in (0o444,0o644) or ca.stat().st_size>1048576 or digest(ca.read_bytes())!=data['ca_sha256']:
            raise ValueError('COLLABORATION_CA_REFERENCE_INVALID')
    return data


def deployment_environment(server):
    path = server / 'docker/collaboration.env'
    return environment(path, compose=True) if path.exists() or path.is_symlink() else {}


def result(action, values, deployment):
    return {'protocol': PROTOCOL, 'schema_version': 1, 'action': action,
            'desired_enabled': values.get('COLLABORATION_ENABLED') == 'enabled',
            'config_revision': int(deployment.get('COLLABORATION_CONFIG_REVISION', '0')),
            'runtime_state': 'not_observed'}


def env_payload(values, compose=False):
    def quote(value):
        # Compose single quotes suppress interpolation, including secrets containing '$'.
        value = str(value)
        if any(character in value for character in ('\r', '\n', '\0')):
            raise ValueError('COLLABORATION_ENVIRONMENT_INVALID')
        if compose and (len(value) - len(value.rstrip('\\'))) % 2 == 1:
            # An odd trailing backslash escapes Compose's closing single quote.
            raise ValueError('COLLABORATION_COMPOSE_VALUE_UNREPRESENTABLE')
        # ThinkPHP uses INI_SCANNER_RAW: quoted UTF-8 and backslashes are literal,
        # not JSON strings. Keep bytes intact instead of emitting JSON escapes.
        return "'" + value.replace("'", "\\'") + "'" if compose else '"' + value + '"'
    return ''.join(key + '=' + quote(value) + '\n' for key, value in values.items()).encode()


def independent_service_values(data, existing=None):
    root = Path(data['service_root'])
    if existing is None:
        existing = environment(root / '.env', compose=True)
    port = existing.get('PORT', '1234')
    bind = existing.get('BIND_ADDRESS', '127.0.0.1')
    if not re.fullmatch(r'[0-9]{1,5}', port) or not 1 <= int(port) <= 65535:
        raise ValueError('COLLABORATION_SERVICE_PORT_REQUIRED')
    if ipaddress.ip_address(bind).version != 4:
        raise ValueError('COLLABORATION_SERVICE_BIND_INVALID')
    # Preserve the service owner's explicit advanced settings; only publish
    # this application's paired identity and selected integration coordinates.
    runtime = {**existing, 'BASE_URL': data['php_origin'].rstrip('/') + '/adminapi/internal/collaboration',
            'INSTANCE_ID': data['instance_id'], 'SERVICE_INSTANCE_ID': data['service_instance_id'],
            'KEY_ID': data['key_id'], 'SERVICE_SECRET': data['secret'], 'CA_SHA256': data['ca_sha256'],
            'COMPOSE_PROJECT_NAME': data['compose_project'], 'NETWORK': data['network'],
            'PORT': port, 'BIND_ADDRESS': bind}
    if not data['ca_sha256']:
        runtime.pop('CA_SHA256', None)
        runtime.pop('NODE_EXTRA_CA_CERTS', None)
    return runtime


def publish_independent_service(server, data, owner):
    root = Path(data['service_root'])
    path = root / '.env'
    ordinary(path)
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        service_owner = os.fstat(stream.fileno())
        if not stat.S_ISREG(service_owner.st_mode) or service_owner.st_nlink != 1 or service_owner.st_mode & 0o777 != 0o600:
            raise ValueError('COLLABORATION_FILE_PERMISSIONS_INVALID')
        previous = stream.read()
    runtime = independent_service_values(data, environment(path, previous, compose=True))
    runtime_payload = env_payload(runtime, True)
    if data['ca_sha256']:
        for directory in (root / 'certs', server / 'private/collaboration', server / SERVICE_DIRECTORY):
            if not directory.exists(): directory.mkdir(mode=0o755)
            if directory.is_symlink() or directory.resolve() != directory or not directory.is_dir() or directory.stat().st_mode & 0o022:
                raise ValueError('COLLABORATION_DIRECTORY_UNSAFE')
        ca = (server / 'docker/tls/ca.pem').read_bytes()
        atomic(root / 'certs/ca.pem', ca, private=False)
        atomic(server / SERVICE_CA, ca, owner=owner, private=False)
    atomic(path, runtime_payload, digest(previous), service_owner)


def publication_values(data):
    config_sha = digest(json.dumps(data, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode())
    node = {'COLLABORATION_IMAGE': data['image'], 'COLLABORATION_INSTANCE_ID': data['instance_id'],
                'COLLABORATION_SERVICE_INSTANCE_ID': data['service_instance_id'], 'COLLABORATION_CA_SHA256': data['ca_sha256'],
                'COLLABORATION_KEY_ID': data['key_id'], 'COLLABORATION_EDITION': data['edition'],
                'COLLABORATION_PHP_ORIGIN': data['php_origin'], 'COLLABORATION_PHP_TLS_HOST': urlsplit(data['php_origin']).hostname,
                'COLLABORATION_CONTROL_TLS_HOST': urlsplit(data['control_origin']).hostname,
                'COLLABORATION_WSS_BIND': data['wss_bind'], 'COLLABORATION_WSS_PORT': str(data['wss_port']),
                   'COLLABORATION_WSS_HOSTNAME': data['wss_hostname'].lower(),
                   'COLLABORATION_NETWORK': data['network'], 'COLLABORATION_WEBSOCKET_SCHEME': data['websocket_scheme'],
                   'COLLABORATION_CONFIG_REVISION': str(data['config_revision']), 'COLLABORATION_CONFIG_SHA256': config_sha,
                   'COLLABORATION_SERVICE_ROOT': data['service_root'], 'COLLABORATION_COMPOSE_PROJECT': data['compose_project']}
    updates = {'COLLABORATION_ENABLED': 'enabled', 'COLLABORATION_INSTANCE_ID': data['instance_id'],
                   'COLLABORATION_SERVICE_INSTANCE_ID': data['service_instance_id'], 'COLLABORATION_SERVICE_KEY_ID': data['key_id'],
                   'COLLABORATION_SECRET': data['secret'], 'COLLABORATION_CONTROL_ORIGIN': data['control_origin'],
                   'COLLABORATION_PUBLIC_WSS_URL': public_wss_url(data),
                   'COLLABORATION_CA_FILE': SERVICE_CA if data['ca_sha256'] else '',
                   'COLLABORATION_CA_SHA256': data['ca_sha256']}
    return node, updates


def migrate_state(server, data, expected_revision):
    # A reviewed metadata relocation, never a protocol/identity or runtime change.
    validate_configuration(data, server)
    if data['websocket_scheme'] != 'wss' or data['config_revision'] != expected_revision:
        raise ValueError('COLLABORATION_STATE_MIGRATION_INPUT_INVALID')
    node, updates = publication_values(data)
    node_payload = env_payload(node, True)
    env_payload(independent_service_values(data), True)
    legacy = {key: value for key, value in data.items() if key != 'websocket_scheme'}
    legacy_sha = digest(json.dumps(legacy, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode())
    with locked(server):
        path = server / '.env'; owner = ordinary(path); old = path.read_bytes(); values = environment(path)
        projection = server / 'docker/collaboration.env'; projection_owner = ordinary(projection)
        previous = projection.read_bytes(); deployment = environment(projection, previous, compose=True)
        expected_legacy = {key: value for key, value in node.items() if key not in PUBLICATION_KEYS | {'COLLABORATION_WEBSOCKET_SCHEME'}}
        expected_state = {key: node[key] for key in PUBLICATION_KEYS}
        expected_state['COLLABORATION_CONFIG_SHA256'] = legacy_sha
        completed = not (PUBLICATION_KEYS & values.keys()) and deployment == node
        if values.get('DEPLOYMENT_MODE') != data['edition'] or any(values.get(key) != value for key, value in updates.items()) \
                or (not completed and (any(values.get(key) != value for key, value in expected_state.items()) \
                    or deployment not in (expected_legacy, node))) \
                or environment(Path(data['service_root']) / '.env', compose=True) != independent_service_values(data):
            raise ValueError('COLLABORATION_STATE_MIGRATION_MISMATCH')
        if data['ca_sha256']:
            for ca in (Path(data['service_root']) / 'certs/ca.pem', server / SERVICE_CA):
                ordinary(ca, False)
                if digest(ca.read_bytes()) != data['ca_sha256']:
                    raise ValueError('COLLABORATION_CA_REFERENCE_INVALID')
        if completed:
            return result('migrate-state', values, node)
        atomic(projection, node_payload, digest(previous), projection_owner)
        lines = [line for line in old.decode().rstrip('\n').split('\n') if line.partition('=')[0] not in PUBLICATION_KEYS]
        atomic(path, ('\n'.join(lines) + '\n').encode(), digest(old), owner)
        return result('migrate-state', values, node)


def enable(server, data, expected_revision):
    validate_configuration(data, server)
    with locked(server):
        path = server / '.env'; owner = ordinary(path); old = path.read_bytes(); values = environment(path)
        deployment = deployment_environment(server)
        revision = int(deployment.get('COLLABORATION_CONFIG_REVISION', '0'))
        if PUBLICATION_KEYS & values.keys():
            raise ValueError('COLLABORATION_LEGACY_PUBLICATION_STATE_REQUIRES_REVIEW')
        if values.get('DEPLOYMENT_MODE') != data['edition']:
            raise ValueError('COLLABORATION_EDITION_MISMATCH')
        node, updates = publication_values(data)
        node_payload = env_payload(node, True)
        updates_payload = env_payload(updates)
        if deployment.get('COLLABORATION_CONFIG_SHA256') == node['COLLABORATION_CONFIG_SHA256'] and values.get('COLLABORATION_ENABLED') == 'enabled':
            root = Path(data['service_root'])
            env_payload(independent_service_values(data), True)
            if any(values.get(key) != value for key, value in updates.items()) \
                    or environment(root / '.env', compose=True) != independent_service_values(data) \
                    or environment(server / 'docker/collaboration.env', compose=True) != node:
                raise ValueError('COLLABORATION_CONFIGURATION_PUBLICATION_MISMATCH')
            if data['ca_sha256']:
                for ca in (root / 'certs/ca.pem', server / SERVICE_CA):
                    ordinary(ca, False)
                    if digest(ca.read_bytes()) != data['ca_sha256']:
                        raise ValueError('COLLABORATION_CA_REFERENCE_INVALID')
            return result('enable', values, deployment)
        # The projection can commit before the PHP environment. Resume only the
        # same reviewed input/revision; never treat a partial publish as enabled.
        if deployment == node and values.get('COLLABORATION_ENABLED') != 'enabled' \
                and revision == expected_revision + 1 and data['config_revision'] == revision:
            revision = expected_revision
        if revision != expected_revision or data['config_revision'] != revision + 1 or values.get('COLLABORATION_ENABLED') == 'enabled':
            raise ValueError('COLLABORATION_CONFIGURATION_CAS_CONFLICT')
        publish_independent_service(server, data, owner)
        atomic(server / 'docker/collaboration.env', node_payload)
        retired = {'COLLABORATION_SERVICE_SECRET_FILE', 'COLLABORATION_TICKET_SECRET_FILE',
                   'RICH_TEXT_COLLABORATION_URL', 'RICH_TEXT_COLLABORATION_SECRET'}
        lines = [line for line in old.decode().rstrip('\n').split('\n') if line.partition('=')[0] not in updates.keys() | retired]
        atomic(path, ('\n'.join(lines) + '\n').encode() + updates_payload, digest(old), owner)
        return result('enable', updates, node)


def compose_arguments(server):
    values = backend_environment(server)
    arguments = ['docker', 'compose', '--env-file', '.env', '-f', 'compose.yaml']
    configuration_path = server / 'private/resources/configuration.json'
    source = 'bundled'
    if configuration_path.exists() or configuration_path.is_symlink():
        ordinary(configuration_path)
        configuration = json.loads(configuration_path.read_text())
        source = configuration.get('database_source')
    if source not in ('bundled', 'existing'):
        raise ValueError('RUNTIME_DATABASE_SOURCE_INVALID')
    if source == 'existing':
        ordinary(server / 'docker/compose.registered-database.yaml', private=False)
        arguments += ['-f', 'compose.registered-database.yaml']
    if values.get('COLLABORATION_ENABLED') == 'enabled':
        node = deployment_environment(server)
        if any(node.get(key) != values.get(key) for key in ('COLLABORATION_INSTANCE_ID', 'COLLABORATION_SERVICE_INSTANCE_ID', 'COLLABORATION_CA_SHA256')):
            raise ValueError('COLLABORATION_PROFILE_CONFIGURATION_STALE')
        scheme = urlsplit(values.get('COLLABORATION_PUBLIC_WSS_URL', '')).scheme
        if scheme != node.get('COLLABORATION_WEBSOCKET_SCHEME') or scheme not in ('ws', 'wss'):
            raise ValueError('COLLABORATION_PROFILE_CONFIGURATION_STALE')
        if scheme == 'ws' and values.get('APP_ENV') != 'development':
            raise ValueError('COLLABORATION_DEVELOPMENT_TRANSPORT_REQUIRED')
        if urlsplit(values.get('COLLABORATION_CONTROL_ORIGIN', '')).scheme != ('http' if scheme == 'ws' else 'https'):
            raise ValueError('COLLABORATION_PROFILE_CONFIGURATION_STALE')
        overlay = 'compose.collaboration-development.yaml' if scheme == 'ws' else 'compose.collaboration.yaml'
        arguments += ['--env-file', 'collaboration.env', '-f', overlay]
    return arguments


def registered_database_services(server, arguments):
    # Recognise this package-owned file even before the wizard publishes .env.
    # Explicit service targets also exclude MySQL when COMPOSE_PROFILES is set.
    official = server / 'docker/compose.registered-database.yaml'
    for index, argument in enumerate(arguments):
        value = None
        if argument in ('-f', '--file') and index + 1 < len(arguments):
            value = arguments[index + 1]
        elif argument.startswith('--file='):
            value = argument[len('--file='):]
        if value is not None:
            path = Path(value)
            if not path.is_absolute():
                path = server / 'docker' / path
            if path.resolve() == official.resolve():
                ordinary(official, private=False)
                ordinary(path, private=False)
                return ['php', 'nginx']
    return []


def run(server, arguments):
    try:
        process = subprocess.run(arguments, cwd=server / 'docker', stdin=subprocess.DEVNULL,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=STARTUP_COMMAND_TIMEOUT)
    except subprocess.TimeoutExpired:
        raise RuntimeError('COLLABORATION_NATIVE_COMMAND_UNCONFIRMED') from None
    if process.returncode:
        raise RuntimeError('COLLABORATION_NATIVE_COMMAND_UNCONFIRMED')
    return process.stdout.decode()


def start(server, extra=()):
    # Application deployment only. Node starts independently from its own config.
    arguments = compose_arguments(server) + list(extra)
    run(server, arguments + ['config', '--quiet'])
    run(server, arguments + ['up', '-d', '--wait', '--no-build'] + registered_database_services(server, arguments))


def stop(server, extra=()):
    # This composition contains APP services and ingress, not the independent Node.
    arguments = compose_arguments(server) + list(extra)
    run(server, arguments + ['stop'] + registered_database_services(server, arguments))


def execute():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('plan', 'enable', 'status', 'migrate-state'))
    parser.add_argument('--app-root', type=Path, required=True)
    parser.add_argument('--configuration', type=Path)
    parser.add_argument('--expected-revision', type=int)
    try:
        args = parser.parse_args(); server = canonical_root(args.app_root)
        values = backend_environment(server)
        if args.action in ('enable', 'migrate-state'):
            if args.configuration is None or args.expected_revision is None:
                raise ValueError('COLLABORATION_CONFIGURATION_REQUIRED')
            ordinary(args.configuration)
            publish = migrate_state if args.action == 'migrate-state' else enable
            answer = publish(server, json.loads(args.configuration.read_text()), args.expected_revision)
        else:
            if args.configuration is not None:
                ordinary(args.configuration); validate_configuration(json.loads(args.configuration.read_text()), server)
            answer = result(args.action, values, deployment_environment(server))
        print(json.dumps(answer, sort_keys=True)); return 0
    except (OSError, ValueError, TypeError, KeyError, RuntimeError, subprocess.SubprocessError) as error:
        print(json.dumps({'protocol': PROTOCOL, 'schema_version': 1, 'error_code': 'COLLABORATION_CONFIGURATION_UNCONFIRMED', 'runtime_state': 'unknown'}))
        return 4 if isinstance(error, RuntimeError) else 2


if __name__ == '__main__':
    sys.exit(execute())
