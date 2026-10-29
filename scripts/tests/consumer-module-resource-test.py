#!/usr/bin/env python3
"""验证参考链的真实资源选择器；只用内存登记，不连接数据库。"""
from copy import deepcopy
from pathlib import Path
import runpy

source = Path(__file__).resolve().parents[1] / 'consumer-module-reference-chain'
namespace = runpy.run_path(str(source), run_name='resource_contract_test')
select = namespace['registered_database']
globals_ = select.__globals__
base = {
    'stable_resource_id': 'fixture-mysql',
    'environments': ['development-test'],
    'application_runtime': False,
    'lifecycle': 'ephemeral',
    'database': 'fixture_consumer',
    'synthetic_databases': {'test': ['fixture_author']},
    'upstream_endpoint': {
        'endpoint_id': 'fixture-host', 'host': '127.0.0.1', 'port': 23306,
        'consumers': ['host'],
    },
}
globals_['CONFIG_VALUES'].update(DB_HOST='127.0.0.1', DB_PORT='23306')
checks = 0

def check(resource, allowed, database='fixture_consumer', endpoint='fixture-host'):
    global checks
    globals_['read_json'] = lambda _: {'resources': {'databases': [resource]}}
    try:
        result = select('fixture-mysql', endpoint, database)
    except namespace['ChainError']:
        if allowed:
            raise
    else:
        assert allowed, 'unauthorized resource was accepted'
        assert result == ('127.0.0.1', 23306)
    checks += 1

check(deepcopy(base), True)
check(deepcopy(base), True, database='fixture_author')
legacy = deepcopy(base)
legacy['environments'] = ['development']
check(legacy, True)
for field, value in [('environments', ['production']), ('application_runtime', True), ('lifecycle', 'persistent')]:
    changed = deepcopy(base)
    changed[field] = value
    check(changed, False)
check(deepcopy(base), False, database='unregistered_database')
check(deepcopy(base), False, endpoint='other-host')
changed = deepcopy(base)
changed['upstream_endpoint']['host'] = '127.0.0.2'
check(changed, False)
print(f'CONSUMER-RESOURCE-CONTRACT-001 passed ({checks} cases)')
