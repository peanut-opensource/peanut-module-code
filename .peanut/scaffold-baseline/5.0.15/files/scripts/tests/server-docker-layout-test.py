#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
DOCKER = ROOT / 'server/docker'

def read(path: str) -> str:
    return (DOCKER / path).read_text()

compose = read('compose.yaml')
service_block = compose
services = re.findall(r'^  ([a-z][a-z0-9-]*):\n', service_block, re.M)
assert services == ['php', 'nginx', 'mysql'], services
assert 'build:' not in compose
assert './mysql:/var/lib/mysql' in compose
assert '../:/var/www/peanut-admin/server' in compose
assert '../public:/var/www/peanut-admin/server/public:ro' in compose
assert '\n  cron:' not in compose and '\n  redis:' not in compose
assert 'MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:' in compose
assert 'MYSQL_ROOT_PASSWORD' + '_FILE' not in compose
assert '/run/secrets/mysql-root-password' not in compose

local = read('compose.local.yaml')
assert '127.0.0.1:${MYSQL_HOST_PORT:-21306}:3306' in local
start = read('scripts/start.sh')
assert '--no-build' in start and 'compose build' not in start
assert 'install-token' in start and 'server/.env is not configured' not in start
assert 'private/resources' in start
entrypoint = read('scripts/php-entrypoint.sh')
assert '.env.bootstrap' in entrypoint and '.env.installing' in entrypoint
assert 'provision-database.php' in entrypoint and 'PEANUT_INSTALLATION_SETUP_TOKEN' in entrypoint
assert 'php server/think crontab' not in read('scripts/php-entrypoint.sh')
assert read('scripts/schedule.sh').rstrip().endswith('exit 0')
for line in read('conf/crontab').splitlines():
    assert not line.strip() or line.lstrip().startswith('#')

nginx = read('conf/nginx.conf')
for protected in ('/private/', '/runtime/', '/docker/', '/vendor/'):
    assert protected in nginx
assert 'location /install/' in nginx
assert (ROOT / 'server/public/install/index.html').is_file()
print('SERVER-DOCKER-LAYOUT passed')
