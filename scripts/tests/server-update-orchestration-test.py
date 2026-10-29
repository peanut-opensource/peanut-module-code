#!/usr/bin/env python3
from pathlib import Path
import json
import hashlib
import os
import shutil
import stat
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[2]
UPDATE = ROOT / 'server/docker/scripts/update.sh'
TEMP_ROOT = ROOT / '.local/tmp'
TEMP_ROOT.mkdir(parents=True, exist_ok=True)


def write(path: Path, contents: str, mode: int = 0o644) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(contents)
    path.chmod(mode)


source = UPDATE.read_text()
compose_source = (ROOT / 'server/docker/compose.yaml').read_text()
nginx_source = (ROOT / 'server/docker/conf/nginx.conf').read_text()
entrypoint_source = (ROOT / 'server/docker/scripts/php-entrypoint.sh').read_text()
assert 'compose stop --timeout 60 nginx php' in source
assert 'compose up -d --no-build php nginx' in source
assert 'compose stop mysql' not in source
assert ' down' not in source
assert ' down --volumes' not in source
assert 'compose build' not in source
assert ' restart' not in source
assert '../runtime/upgrade:/var/www/peanut-admin/server/runtime/upgrade:ro' in compose_source
assert 'nginx -t >/dev/null 2>&1' in compose_source
assert 'if (!-f /var/www/peanut-admin/server/runtime/upgrade/.mount-ready) { return 503; }' in nginx_source
assert 'if (-e /var/www/peanut-admin/server/runtime/upgrade/maintenance.json) { return 503; }' in nginx_source
assert 'if (-l ' not in nginx_source
assert 'if (!-f /var/www/peanut-admin/server/runtime/upgrade/.traffic-ready) { return 503; }' in nginx_source
assert 'if (-e /var/www/peanut-admin/server/runtime/upgrade/current-update.json) { return 503; }' in nginx_source
assert 'runtime/upgrade/.mount-ready' in entrypoint_source
assert 'initialize-traffic --instance-server="$SERVER_ROOT"' in entrypoint_source
assert 'http_healthz 503' in source
assert 'http_healthz 200' in source

with tempfile.TemporaryDirectory(prefix='server-update-orchestration-', dir=TEMP_ROOT) as tmp:
    base = Path(tmp)
    fixture = base / 'fixture/server/docker/scripts'
    fixture.mkdir(parents=True)
    shutil.copy2(UPDATE, fixture / 'update.sh')
    write(fixture / 'update-plan.php', "<?php\n", 0o644)
    image = 'fixture@sha256:' + 'a' * 64
    write(base / 'fixture/server/docker/.env', f"PHP_IMAGE={image}\nNGINX_IMAGE={image}\nMYSQL_IMAGE={image}\nHTTP_BIND=127.0.0.1\nHTTP_PORT=18080\n")
    (base / 'workspace').mkdir()
    write(base / 'workspace/.trusted-update-plan.php', "<?php\n", 0o600)
    tools = base / 'workspace/.trusted-tools'
    write(tools / 'prepare-vendor.sh', '#!/bin/sh\nprintf "PREPARE_VENDOR\\n" >> "$FAKE_LOG"\n', 0o700)
    write(tools / 'vendor-state.py', '# fixture-only vendor probe\n')
    stub = '''#!/usr/bin/env python3
import os, sys
from pathlib import Path
phase = sys.argv[1]
name = Path(sys.argv[0]).name
label = {'update-preparation.py': 'PREPARATION', 'update-recovery.py': 'RECOVERY'}[name] + ':' + phase
with Path(os.environ['FAKE_LOG']).open('a') as output: output.write(label + '\\n')
if os.environ.get('FAKE_FAIL_STUB') == label: sys.exit(44)
workspace = Path(os.environ['FAKE_WORKSPACE'])
if label == 'PREPARATION:record': (workspace / 'preparation.json').write_text('{}')
if label == 'RECOVERY:backup': (workspace / 'backup.json').write_text('{}')
'''
    write(tools / 'update-preparation.py', stub)
    write(tools / 'update-recovery.py', stub)
    write(tools / 'update-database.php', '<?php\n')
    files = {str(path.relative_to(base / 'workspace')): hashlib.sha256(path.read_bytes()).hexdigest()
             for path in [base / 'workspace/.trusted-update-plan.php', *sorted(tools.iterdir())]}
    write(tools / 'manifest.json', json.dumps({'protocol': 'peanut.server-update-tools.v1', 'files': files}), 0o600)
    log = base / 'commands.log'
    state = base / 'state.json'
    state.write_text(json.dumps({'php': 'running', 'nginx': 'running', 'mysql': 'running'}))
    fake_bin = base / 'bin'
    fake_bin.mkdir()

    write(fake_bin / 'docker', r'''#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

log_path = Path(os.environ['FAKE_LOG'])
state_path = Path(os.environ['FAKE_STATE'])
root = Path.cwd().parent
marker = root / 'runtime/upgrade/maintenance.json'
permit = root / 'runtime/upgrade/.traffic-ready'
pointer = root / 'runtime/upgrade/current-update.json'
ids = {'php': 'p' * 64, 'nginx': 'n' * 64, 'mysql': 'm' * 64}

def log(line):
    with log_path.open('a') as output:
        output.write(line + '\n')

def state():
    return json.loads(state_path.read_text())

def save(value):
    state_path.write_text(json.dumps(value))

def reject(reason=''):
    log('UNEXPECTED:' + ' '.join(sys.argv[1:]) + (':' + reason if reason else ''))
    sys.exit(98)

args = sys.argv[1:]
if args == ['image', 'inspect', os.environ['FAKE_IMAGE']]:
    log('IMAGE')
    sys.exit(0)

if args and args[0] == 'run':
    if any('dst=/tool/update-database.php,readonly' in arg for arg in args):
        if '--network' not in args or args[args.index('--network') + 1] != 'container:' + ids['mysql']:
            reject('database-network')
        phase = args[args.index('/tool/update-database.php') + 1]
        log('DATABASE:' + phase)
        sys.exit(42 if os.environ.get('FAKE_FAIL_PHASE') == 'database:' + phase else 0)
    if not any('src=' + os.environ['FAKE_TRUSTED_TOOL'] + ',dst=/tool/update-plan.php,readonly' in arg for arg in args):
        reject('untrusted-tool-mount')
    try:
        phase = args[args.index('/tool/update-plan.php') + 1]
    except ValueError:
        reject('missing-tool')
    log('RUN:' + phase)
    if phase in ['verify-applied', 'verify-recovered']:
        if '--network' not in args or not args[args.index('--network') + 1].startswith('container:'):
            reject('verification-must-be-private')
        if not marker.is_file():
            reject('verification-without-maintenance')
    elif '--network' in args and args[args.index('--network') + 1] != 'none':
        reject('unexpected-network')
    if os.environ.get('FAKE_FAIL_PHASE') == phase:
        sys.exit(42)
    if phase == 'begin' and os.environ.get('FAKE_BEGIN_ACTION') in ['none', 'verify_activate']:
        action = os.environ['FAKE_BEGIN_ACTION']
        log('BEGIN_ACTION:' + action)
        sys.exit(10 if action == 'none' else 11)
    if phase in ['begin', 'recover']:
        permit.unlink(missing_ok=True)
        pointer.parent.mkdir(parents=True, exist_ok=True)
        pointer.write_text('{}')
    if phase in ['begin', 'apply', 'recover']:
        marker.parent.mkdir(parents=True, exist_ok=True)
        marker.write_text('{"schema_version":1,"protocol":"peanut.server-update-maintenance.v1","status":"active","update_id":"server_update_20260930000000_aaaaaaaaaaaa","maintenance_key":"maintenance_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","reason_key":"planned-upgrade"}')
    if phase in ['activate', 'finish-recovery'] and marker.exists():
        permit.write_text('peanut.server-traffic-ready.v1\n')
        marker.unlink()
        pointer.unlink(missing_ok=True)
    print('{"status":"' + phase + '"}')
    sys.exit(0)

if args and args[0] == 'compose':
    forbidden = {'build', 'down', 'restart', 'run'}
    if any(item in forbidden for item in args):
        reject('forbidden-compose')
    command = next((item for item in args if item in ['config', 'stop', 'up', 'ps', 'exec']), None)
    if command is None:
        reject('missing-compose-command')
    rest = args[args.index(command) + 1:]
    current = state()
    if command == 'config':
        if rest != ['--quiet']:
            reject('config-rest')
        log('COMPOSE:config')
        sys.exit(0)
    if command == 'stop':
        if rest != ['--timeout', '60', 'nginx', 'php']:
            reject('stop-targets')
        current['nginx'] = 'exited'
        current['php'] = 'exited'
        save(current)
        log('STOP:60:nginx,php')
        sys.exit(0)
    if command == 'up':
        if rest != ['-d', '--no-build', 'php', 'nginx']:
            reject('up-targets')
        current['php'] = 'running'
        current['nginx'] = 'running'
        save(current)
        log('UP:php,nginx:no-build')
        sys.exit(0)
    if command == 'ps':
        if len(rest) == 2 and rest[0] == '-q' and rest[1] in ids:
            print(ids[rest[1]])
            log('PS:' + rest[1])
            sys.exit(0)
        reject('ps-rest')
    if command == 'exec':
        joined = ' '.join(rest)
        if rest[:3] != ['-T', 'php', 'php']:
            reject('exec-prefix')
        if 'database/install.php --status' in joined:
            log('INSTALL_STATUS')
            print('{"state":"installed","health":{"tenant_count":1}}')
            sys.exit(0)
        if 'ServerReleaseIdentity::load' in joined:
            log('RELEASE_IDENTITY')
            print('ok')
            sys.exit(0)
        reject('exec-command')

if args and args[0] == 'inspect':
    if '--format' not in args:
        reject('inspect-format')
    pattern = args[args.index('--format') + 1]
    container = args[-1]
    service = next((name for name, value in ids.items() if value == container), None)
    if service is None:
        reject('inspect-container')
    current = state()
    if pattern == '{{.State.Status}}':
        print(current[service])
    elif pattern == '{{.State.Health.Status}}':
        print('healthy' if current[service] == 'running' else 'starting')
    else:
        reject('inspect-pattern')
    log('INSPECT:' + service + ':' + pattern)
    sys.exit(0)

reject()
''', 0o755)

    write(fake_bin / 'curl', r'''#!/usr/bin/env python3
import os
import sys
from pathlib import Path
with Path(os.environ['FAKE_LOG']).open('a') as log:
    log.write('HTTP:' + sys.argv[-1] + '\n')
if os.environ.get('FAKE_FAIL_HTTP') == '1':
    sys.exit(43)
if sys.argv[-1] != 'http://127.0.0.1:18080/healthz':
    sys.exit(98)
marker = Path(os.environ['FAKE_MARKER'])
permit = Path(os.environ['FAKE_PERMIT'])
pointer = Path(os.environ['FAKE_POINTER'])
sys.stdout.write('503' if marker.is_file() or marker.is_symlink() or pointer.exists() or pointer.is_symlink() or not permit.is_file() else '200')
sys.exit(0)
''', 0o755)
    write(fake_bin / 'sleep', "#!/bin/sh\nexit 0\n", 0o755)

    env = os.environ.copy()
    env['PATH'] = str(fake_bin) + os.pathsep + env['PATH']
    env['FAKE_LOG'] = str(log)
    env['FAKE_IMAGE'] = image
    env['FAKE_WORKSPACE'] = str(base / 'workspace')
    env['FAKE_STATE'] = str(state)
    env['FAKE_TRUSTED_TOOL'] = str(base / 'workspace/.trusted-update-plan.php')
    env['FAKE_MARKER'] = str(base / 'fixture/server/runtime/upgrade/maintenance.json')
    env['FAKE_PERMIT'] = str(base / 'fixture/server/runtime/upgrade/.traffic-ready')
    env['FAKE_POINTER'] = str(base / 'fixture/server/runtime/upgrade/current-update.json')

    executed_flows = 0
    for command in ['apply', 'recover']:
        if log.exists():
            log.unlink()
        marker = base / 'fixture/server/runtime/upgrade/maintenance.json'
        marker.parent.mkdir(parents=True, exist_ok=True)
        permit = marker.parent / '.traffic-ready'
        pointer = marker.parent / 'current-update.json'
        permit.write_text('peanut.server-traffic-ready.v1\n')
        pointer.unlink(missing_ok=True)
        if command == 'recover':
            marker.write_text('{}')
        else:
            marker.unlink(missing_ok=True)
        result = subprocess.run(
            [str(fixture / 'update.sh'), command, f'--workspace={base / "workspace"}'],
            cwd=base,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
        )
        output = result.stdout
        assert result.returncode == 0, output
        lines = log.read_text().splitlines()
        assert not any(line.startswith('UNEXPECTED:') for line in lines), lines
        assert lines[0:4] == ['IMAGE', 'IMAGE', 'IMAGE', 'COMPOSE:config'], lines
        if command == 'apply':
            expected_order = ['PREPARATION:record', 'RUN:begin', 'STOP:60:nginx,php', 'RECOVERY:backup', 'PREPARATION:switch', 'RUN:apply', 'DATABASE:migrate', 'DATABASE:verify', 'UP:php,nginx:no-build']
            assert all(item in lines for item in expected_order), lines
            assert [lines.index(item) for item in expected_order] == sorted(lines.index(item) for item in expected_order), lines
            assert lines.index('UP:php,nginx:no-build') < lines.index('RUN:verify-applied') < lines.index('RUN:activate')
        else:
            expected_order = ['RUN:assert-recoverable', 'STOP:60:nginx,php', 'RECOVERY:restore', 'RUN:recover', 'PREPARATION:recover', 'UP:php,nginx:no-build']
            assert all(item in lines for item in expected_order), lines
            assert [lines.index(item) for item in expected_order] == sorted(lines.index(item) for item in expected_order), lines
            assert lines.index('UP:php,nginx:no-build') < lines.index('RUN:verify-recovered') < lines.index('RUN:finish-recovery')
        final_state = json.loads(state.read_text())
        assert final_state == {'php': 'running', 'nginx': 'running', 'mysql': 'running'}, final_state
        executed_flows += 1

    for failure in ['apply', 'http']:
        log.unlink()
        marker.unlink(missing_ok=True)
        permit.write_text('peanut.server-traffic-ready.v1\n')
        pointer.unlink(missing_ok=True)
        state.write_text(json.dumps({'php': 'running', 'nginx': 'running', 'mysql': 'running'}))
        failed_env = env.copy()
        if failure == 'apply':
            failed_env['FAKE_FAIL_PHASE'] = 'apply'
        else:
            failed_env['FAKE_FAIL_HTTP'] = '1'
        result = subprocess.run(
            [str(fixture / 'update.sh'), 'apply', f'--workspace={base / "workspace"}'],
            cwd=base,
            env=failed_env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
        )
        assert result.returncode != 0, (failure, result.stdout)
        lines = log.read_text().splitlines()
        assert 'RUN:activate' not in lines, (failure, lines)
        assert marker.is_file(), (failure, lines)
        assert json.loads(state.read_text())['mysql'] == 'running', (failure, lines)
        executed_flows += 1

    # Stub failures prove shell propagation and ordering only; they do not run Docker or a database.
    for failure in ['PREPARATION:record', 'RECOVERY:backup', 'PREPARATION:switch',
                    'database:migrate', 'database:verify']:
        log.unlink()
        (base / 'workspace/preparation.json').unlink(missing_ok=True)
        (base / 'workspace/backup.json').unlink(missing_ok=True)
        marker.unlink(missing_ok=True)
        permit.write_text('peanut.server-traffic-ready.v1\n')
        pointer.unlink(missing_ok=True)
        state.write_text(json.dumps({'php': 'running', 'nginx': 'running', 'mysql': 'running'}))
        failed_env = env.copy()
        if failure.startswith('database:'):
            failed_env['FAKE_FAIL_PHASE'] = failure
        else:
            failed_env['FAKE_FAIL_STUB'] = failure
        result = subprocess.run(
            [str(fixture / 'update.sh'), 'apply', f'--workspace={base / "workspace"}'],
            cwd=base, env=failed_env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        )
        assert result.returncode != 0, (failure, result.stdout)
        lines = log.read_text().splitlines()
        expected = 'DATABASE:' + failure.split(':', 1)[1] if failure.startswith('database:') else failure
        assert expected in lines and 'RUN:activate' not in lines, (failure, lines)
        if failure == 'PREPARATION:record':
            assert 'RUN:begin' not in lines and 'STOP:60:nginx,php' not in lines, lines
        else:
            assert lines.index('RUN:begin') < lines.index('STOP:60:nginx,php') < lines.index(expected), lines
            assert marker.is_file(), (failure, lines)
        assert json.loads(state.read_text())['mysql'] == 'running', (failure, lines)
        executed_flows += 1

    for action in ['none', 'verify_activate']:
        log.unlink()
        marker.unlink(missing_ok=True)
        permit.write_text('peanut.server-traffic-ready.v1\n')
        pointer.unlink(missing_ok=True)
        state.write_text(json.dumps({'php': 'running', 'nginx': 'running', 'mysql': 'running'}))
        action_env = env.copy()
        action_env['FAKE_BEGIN_ACTION'] = action
        if action == 'verify_activate':
            marker.write_text('{}')
            permit.unlink()
            pointer.write_text('{}')
        result = subprocess.run(
            [str(fixture / 'update.sh'), 'apply', f'--workspace={base / "workspace"}'],
            cwd=base,
            env=action_env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
        )
        assert result.returncode == 0, (action, result.stdout)
        lines = log.read_text().splitlines()
        assert 'STOP:60:nginx,php' not in lines and 'RUN:apply' not in lines, (action, lines)
        if action == 'none':
            assert 'UP:php,nginx:no-build' not in lines and 'RUN:verify-applied' not in lines, lines
        else:
            assert lines.index('UP:php,nginx:no-build') < lines.index('RUN:verify-applied') < lines.index('RUN:activate'), lines
            assert 'RUN:activate' in lines and not marker.exists(), lines
        executed_flows += 1

    log.unlink()
    marker.unlink(missing_ok=True)
    permit.write_text('peanut.server-traffic-ready.v1\n')
    pointer.unlink(missing_ok=True)
    failing_begin = env.copy()
    failing_begin['FAKE_FAIL_PHASE'] = 'begin'
    result = subprocess.run(
        [str(fixture / 'update.sh'), 'apply', f'--workspace={base / "workspace"}'],
        cwd=base, env=failing_begin, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
    )
    assert result.returncode == 42, result.stdout
    lines = log.read_text().splitlines()
    assert 'STOP:60:nginx,php' not in lines and 'RUN:apply' not in lines, lines
    executed_flows += 1

print(f'SERVER-UPDATE-ORCHESTRATION passed flows={executed_flows}')
