#!/usr/bin/env python3
"""Prepare ordinary nonRoot Workflow identities through existing HTTP APIs.

No SQL, fixture seed, graph publication, workflow start, worker or retry entry.
The immutable runtime handoff is evidence input, never a replacement for the
native lease checker. See docs/maintenance/workflow-authorized-fixture.md.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import time
import urllib.parse


ROOT = Path(__file__).resolve().parent.parent
IDENTITY_WRITES = {
    'pa_tenant', 'pa_account', 'pa_credential', 'pa_tenant_member', 'pa_department',
    'pa_role', 'pa_member_role', 'pa_role_permission', 'pa_tenant_session',
    'pa_tenant_session_token', 'pa_auth_security_event', 'pa_tenant_audit_event',
    'pa_data_permission_policy', 'pa_data_permission_group', 'pa_data_permission_condition',
    'pa_data_permission_target_set', 'pa_data_permission_target',
    'pa_task_job', 'pa_task_job_event', 'pa_task_job_attempt',
}


class Stop(RuntimeError):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()


def digest(value):
    return hashlib.sha256(value).hexdigest()


def private(path):
    p = Path(path)
    need(p.is_absolute() and p.resolve() == p and not p.is_symlink(), 'CANONICAL_PRIVATE_PATH_REQUIRED')
    info = p.stat()
    need(stat.S_ISREG(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o600
         and info.st_nlink == 1 and info.st_uid == os.geteuid(), 'PRIVATE_FILE_REQUIRED')
    return p


def pinned(reference):
    p = private(reference['path'])
    raw = p.read_bytes()
    need(len(raw) <= 2_000_000 and digest(raw) == reference['sha256'], 'EVIDENCE_HASH_MISMATCH')
    return json.loads(raw)


def positive(value):
    need(type(value) is int and 0 < value < 10**18, 'POSITIVE_INTEGER_REQUIRED')
    return value


def native_caller(c):
    # CODEX_THREAD_ID is the existing native execution convention, not a
    # caller-provided JSON actor. Never set/replace it from the handoff.
    actual = os.environ.get('CODEX_THREAD_ID', '')
    need(re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}', actual) is not None, 'ACTUAL_NATIVE_THREAD_REQUIRED')
    need(c.get('thread') == actual and c.get('owner') == actual, 'ACTUAL_NATIVE_OWNER_THREAD_MISMATCH')
    return actual


def verified_correlation(expected, carriers):
    need(isinstance(expected, str) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._:-]{0,63}', expected), 'REQUEST_IDENTITY_INVALID')
    need(isinstance(carriers, dict) and set(carriers) <= {'headers', 'meta'}, 'RESPONSE_CORRELATION_INVALID')
    headers = carriers.get('headers', [])
    need(isinstance(headers, (list, tuple)), 'RESPONSE_CORRELATION_INVALID')
    values = list(headers)
    if 'meta' in carriers:
        values.append(carriers['meta'])
    need(values and all(isinstance(value, str) and value == expected for value in values), 'RESPONSE_REQUEST_ID_MISSING_OR_MISMATCH')
    return carriers


def safe_selectors(body):
    if body is None:
        return {}
    selected = {}
    for key in ['id', 'role_id', 'dept_id', 'leader_member_id', 'pid', 'revision', 'expected_revision']:
        if key not in body:
            continue
        value = body[key]
        values = value if isinstance(value, list) else [value]
        need(len(values) <= 100 and all(item is None or
             ((type(item) is int or isinstance(item, str)) and re.fullmatch(r'(0|[1-9][0-9]{0,17})', str(item)))
             for item in values), 'SAFE_TARGET_SELECTOR_INVALID')
        selected[key] = value
    return selected


def unresolved_intents(rows):
    intents = {row['sequence']: row for row in rows if row['state'] == 'INTENT'}
    resolved = set()
    for row in rows:
        if row['state'] != 'RESPONSE' or row.get('correlation_verified') is not True:
            continue
        intent = intents.get(row['sequence'])
        if intent is None or row.get('request_id') != intent['request_id']:
            continue
        try:
            verified_correlation(intent['request_id'], row.get('correlation'))
        except Stop:
            continue
        resolved.add(row['sequence'])
    return [row for sequence, row in intents.items() if sequence not in resolved]


def verified_primary_department(row, expected):
    actual = row.get('dept_id')
    need(isinstance(actual, list) and len(actual) == 1 and type(actual[0]) is int
         and actual[0] == positive(expected), 'PRIMARY_DEPARTMENT_READBACK_MISMATCH')
    return actual[0]


def load_input(path, expected_hash):
    p = private(path)
    raw = p.read_bytes()
    need(len(raw) <= 2_000_000 and digest(raw) == expected_hash, 'INPUT_HASH_MISMATCH')
    c = json.loads(raw)
    need(c.get('execute') is True, 'EXECUTION_NOT_ADMITTED')
    need(c.get('schema') == 'workflow-authorized-fixture/v1', 'INPUT_SCHEMA_INVALID')
    native_caller(c)
    need(re.fullmatch(r'[a-z][a-z0-9-]{7,47}', c['namespace']) is not None, 'NAMESPACE_INVALID')
    need(c['runtime_root'] == str(ROOT), 'FIXED_RUNTIME_ROOT_REQUIRED')
    need(digest(Path(__file__).read_bytes()) == c['driver_sha256'], 'DRIVER_CHANGED')
    need(digest((ROOT / 'scripts/tests/installed-business-http.py').read_bytes()) == c['http_driver_sha256'], 'HTTP_DRIVER_CHANGED')
    need(c['owner'] and c['thread'] and c['owner'] != c['reviewer'], 'INDEPENDENT_REVIEW_REQUIRED')
    plan = c['tenants']
    need(isinstance(plan, list) and 1 <= len(plan) <= 2, 'BOUNDED_TENANTS_REQUIRED')
    tenant_ids, aliases = set(), set()
    for tenant in plan:
        positive(tenant['id'])
        need(tenant['id'] not in tenant_ids, 'DUPLICATE_TENANT')
        tenant_ids.add(tenant['id'])
        need(re.fullmatch(r'[A-Za-z0-9.-]+(?::[0-9]+)?', tenant['host']) is not None, 'HOST_INVALID')
        need(tenant['code'] and isinstance(tenant['code'], str), 'TENANT_CODE_REQUIRED')
        need(1 <= len(tenant['roles']) <= 8 and 1 <= len(tenant['actors']) <= 8
             and 1 <= len(tenant['departments']) <= 4, 'BOUNDED_PLAN_REQUIRED')
        for group in ['roles', 'actors', 'departments']:
            local = [x['alias'] for x in tenant[group]]
            need(len(local) == len(set(local)), 'DUPLICATE_ALIAS')
            for alias in local:
                need(re.fullmatch(r'[a-z][a-z0-9-]{0,23}', alias) is not None, 'ALIAS_INVALID')
        role_names = {x['alias'] for x in tenant['roles']}
        department_names = {x['alias'] for x in tenant['departments']}
        actor_names = {x['alias'] for x in tenant['actors']}
        for role in tenant['roles']:
            need(isinstance(role['permissions'], list) and len(role['permissions']) <= 100
                 and len(role['permissions']) == len(set(role['permissions']))
                 and all(isinstance(k, str) and 0 < len(k) <= 160 for k in role['permissions']), 'PERMISSION_KEYS_INVALID')
            need(len(role['policies']) <= 24, 'BOUNDED_POLICIES_REQUIRED')
            for policy in role['policies']:
                need(set(policy) == {'resource_key', 'operation', 'condition_key'}
                     and policy['resource_key'].startswith('fixture.workflow-business.')
                     and policy['condition_key'] in ['core.self', 'core.tenant_all', 'core.own_department'], 'POLICY_SCOPE_INVALID')
        for actor in tenant['actors']:
            need(actor['alias'] not in aliases, 'ACTOR_ALIAS_MUST_BE_GLOBAL_UNIQUE')
            aliases.add(actor['alias'])
            need(actor['roles'] and set(actor['roles']) <= role_names
                 and actor['department'] in department_names, 'ACTOR_BINDING_INVALID')
        for department in tenant['departments']:
            need(department['leader_actor'] is None or department['leader_actor'] in actor_names, 'LEADER_ALIAS_INVALID')
    authority = pinned(c['authority'])
    # This is a normalized, independently reviewed protected runtime handoff.
    # It does not grant any native permission, and the driver never writes it.
    scope = {k: c[k] for k in ['runtime_root', 'runtime_commit', 'driver_sha256', 'http_driver_sha256',
                              'namespace', 'owner', 'thread', 'lease', 'gate', 'registry', 'resources', 'tenants', 'credentials']}
    need(authority.get('scope_sha256') == digest(canonical(scope))
         and authority.get('approved') is True and authority.get('reviewer') == c['reviewer']
         and authority.get('owner') == c['owner'] and authority.get('thread') == c['thread'], 'PROTECTED_HANDOFF_REQUIRED')
    need(authority['parent_task'] == 'EX00003' and authority['allocation_id']
         and authority['expires_at'] > time.time() + 30, 'TASK_ALLOCATION_EXPIRED_OR_MISSING')
    need(authority.get('queue_consumption_allowed') is False
         and authority.get('existing_member_mutation_allowed') is False, 'MINIMAL_SCOPE_REQUIRED')
    need(authority.get('complete_effects_reviewed') is True, 'COMPLETE_EFFECTS_REVIEW_REQUIRED')
    # Capture receipts remain in their native schemas. Review pins their actual
    # bytes and complete table/FK/config coverage; no invented backup PASS.
    for evidence in authority['protection_evidence']:
        p = private(evidence['path'])
        need(digest(p.read_bytes()) == evidence['sha256'], 'PROTECTION_EVIDENCE_CHANGED')
    need(len(authority['protection_evidence']) >= 2, 'BACKUP_AND_INDEPENDENT_PROTECTION_REQUIRED')
    positive(authority['http_limit'])
    positive(authority['write_limit'])
    ledger = Path(authority['ledger'])
    need(ledger.is_absolute() and ledger.resolve() == ledger and not ledger.exists(), 'LEDGER_EXISTS_OR_NONCANONICAL_STOP')
    parent = ledger.parent
    info = parent.stat()
    need(parent.resolve() == parent and stat.S_ISDIR(info.st_mode)
         and stat.S_IMODE(info.st_mode) == 0o700 and info.st_uid == os.geteuid(), 'PRIVATE_LEDGER_PARENT_REQUIRED')
    need(Path(authority['allocation_path']).parent == parent, 'ONE_ALLOCATION_LOCATION_REQUIRED')
    allocation = pinned(authority['allocation'])
    need(authority['allocation_path'] == authority['allocation']['path']
         and allocation['allocation_id'] == authority['allocation_id']
         and allocation['parent_task'] == 'EX00003' and allocation['ledger'] == str(ledger)
         and allocation['scope_sha256'] == authority['scope_sha256']
         and allocation.get('owner') == c['owner'] and allocation.get('thread') == c['thread']
         and allocation['http_limit'] == authority['http_limit']
         and allocation['write_limit'] == authority['write_limit'], 'UNIQUE_TASK_BUDGET_REQUIRED')
    c['_input_sha256'] = expected_hash
    return c, authority


class Fixture:
    def __init__(self, c, authority):
        self.c, self.authority = c, authority
        self.http, self.writes, self.sequence = 0, 0, 0
        self.sessions = []
        self.mapping = []
        self.guard()
        self.credentials = pinned(c['credentials'])
        new_emails = []
        for tenant in c['tenants']:
            positive(tenant['operator_member_id'])
            positive(tenant['operator_account_id'])
            for actor in tenant['actors']:
                credential = self.credentials[actor['credentials']]
                need(isinstance(credential['email'], str) and credential['email'].startswith(c['namespace'] + '-')
                     and isinstance(credential['password'], str) and len(credential['password']) >= 16, 'NEW_PRIVATE_ACCOUNT_REQUIRED')
                new_emails.append(credential['email'].lower())
        need(len(new_emails) == len(set(new_emails)), 'NEW_ACCOUNTS_MUST_BE_DISTINCT')
        # This canonical allocation claim lives next to the independently pinned
        # task budget, not in a caller-selected output directory. Never remove it.
        claim = Path(authority['allocation_path'] + '.consumed')
        self.write_new(claim, {'allocation_id': authority['allocation_id'], 'scope_sha256': authority['scope_sha256'],
                               'ledger': authority['ledger'], 'native_thread': native_caller(c), 'state': 'STARTED_NO_REPLAY'})
        self.fd = os.open(authority['ledger'], os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        self.record({'state': 'STARTED', 'allocation_id': authority['allocation_id'], 'scope_sha256': authority['scope_sha256'],
                     'native_thread': native_caller(c),
                     'input_sha256': c['_input_sha256'], 'authority_sha256': c['authority']['sha256']})
        spec = importlib.util.spec_from_file_location('workflow_fixture_http', ROOT / 'scripts/tests/installed-business-http.py')
        http = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(http)
        self.Session = http.Session

    @staticmethod
    def write_new(path, value):
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(canonical(value) + b'\n')
            stream.flush()
            os.fsync(stream.fileno())
        directory = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)

    def record(self, value):
        data = canonical(value) + b'\n'
        view = memoryview(data)
        while view:
            view = view[os.write(self.fd, view):]
        os.fsync(self.fd)

    def guard(self):
        c = self.c
        native_caller(c)
        need(time.time() + 30 < self.authority['expires_at'], 'PROTECTION_OR_BUDGET_EXPIRED')
        need(subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip() == c['runtime_commit']
             and not subprocess.check_output(['git', 'status', '--porcelain=v1'], cwd=ROOT), 'RUNTIME_SOURCE_CHANGED')
        registry = Path(c['registry']['path'])
        need(registry.is_absolute() and registry.resolve() == registry
             and digest(registry.read_bytes()) == c['registry']['sha256'], 'REGISTRY_CHANGED')
        proof = subprocess.check_output([str(ROOT / 'scripts/project-resource-lease'), 'show', '--lease', c['lease'], '--verify-locks'],
                                       cwd=ROOT, env=os.environ | {'PEANUT_RESOURCE_REGISTRY': str(registry)},
                                       text=True, timeout=20)
        head, sep, body = proof.partition('resources\n')
        need(sep, 'NATIVE_LEASE_FORMAT_INVALID')
        fields = dict(line.split('\t', 1) for line in head.splitlines())
        for key, expected in {'lease': c['lease'], 'owner': c['owner'], 'thread': c['thread'],
                              'candidate': c['runtime_commit'], 'candidate_repository': str(ROOT),
                              'worktree': str(ROOT), 'gate': c['gate'], 'status': 'ACTIVE'}.items():
            need(fields.get(key) == expected, 'NATIVE_AUTHORITY_MISMATCH')
        need(int(fields['expires_at']) > time.time() + 30, 'NATIVE_LEASE_EXPIRED')
        observed = {tuple(line.split('\t', 1)) for line in body.splitlines() if line}
        expected = {tuple(pair) for pair in c['resources']}
        need(expected <= observed and ('port', '20180') in expected
             and ('registry-sha256', c['registry']['sha256']) in expected
             and ('output-dir', str(Path(self.authority['ledger']).parent)) in expected, 'EXACT_NATIVE_SCOPE_REQUIRED')
        schemas = [value for kind, value in expected if kind == 'mysql-schema']
        access = [json.loads(value) for kind, value in expected if kind == 'mysql-access']
        need(len(schemas) == 1 and len(access) == 1 and access[0]['schema'] == schemas[0]
             and access[0]['complete'] is True and access[0]['operation'] == 'transaction', 'COMPLETE_NATIVE_TABLE_SCOPE_REQUIRED')
        # Insert/unique-check/identity revocation effects are not isolated by a
        # marker. Native complete tables must be reviewed for the entire batch.
        need(all(row['rows'] == '*' for row in access[0]['tables']), 'INSERT_REQUIRES_COMPLETE_TABLE_SCOPE')
        writes = {row['table'] for row in access[0]['tables'] if row['access'] == 'write'}
        need(IDENTITY_WRITES <= writes, 'IDENTITY_EFFECTS_NOT_COVERED')
        for reference in [c['authority'], c['credentials'], self.authority['allocation'], *self.authority['protection_evidence']]:
            p = private(reference['path'])
            need(digest(p.read_bytes()) == reference['sha256'], 'ADMITTED_INPUT_CHANGED')

    def call(self, session, actor, method, path, body=None):
        self.guard()
        mutation = method != 'GET'
        need(self.http < self.authority['http_limit'] and (not mutation or self.writes < self.authority['write_limit']), 'ORIGINAL_TASK_BUDGET_EXHAUSTED')
        need(path.startswith('/adminapi/') and not any(x in path for x in ['\r', '\n']), 'FIXED_API_PATH_REQUIRED')
        self.sequence += 1
        self.http += 1
        self.writes += int(mutation)
        label = f'{self.c["namespace"]}-{self.sequence}'
        rid = 'rw08-' + digest((self.authority['allocation_id'] + ':' + str(self.sequence)).encode())[:32]
        key = 'rw08-' + digest((self.authority['scope_sha256'] + ':' + str(self.sequence)).encode())
        intent = {'state': 'INTENT', 'sequence': self.sequence, 'request_id': rid, 'actor': actor,
                  'input_sha256': self.c['_input_sha256'], 'scope_sha256': self.authority['scope_sha256'],
                  'target': {'method': method, 'host': session.host, 'port': 20180, 'path': path},
                  'idempotency_key': key, 'request_sha256': digest(canonical([method, path, body])),
                  'target_selectors': safe_selectors(body),
                  'http_reserved': self.http, 'writes_reserved': self.writes, 'at': time.time()}
        self.record(intent)  # fsync BEFORE Session can open a socket.
        try:
            result = session.request(method, path, body, label=label,
                                     statuses=(200, 204) if path.endswith('/session/logout') else (200,),
                                     headers={'Idempotency-Key': key, 'X-Request-Id': rid})
            carriers = {'headers': list(session.response_request_ids)}
            if isinstance(result.get('meta'), dict) and 'request_id' in result['meta']:
                carriers['meta'] = result['meta']['request_id']
            verified_correlation(rid, carriers)
        except Exception as error:
            self.record({'state': 'UNKNOWN_STOP', 'sequence': self.sequence, 'request_id': rid,
                         'reason': str(error) if isinstance(error, Stop) else type(error).__name__,
                         'instruction': 'Reconcile the original request; never replay this allocation.'})
            raise Stop('REQUEST_UNKNOWN_STOP_NO_REPLAY') from None
        self.record({'state': 'RESPONSE', 'sequence': self.sequence, 'request_id': rid,
                     'correlation_verified': True, 'correlation': carriers,
                     'response_sha256': digest(canonical(result)), 'at': time.time()})
        return result.get('data')

    def login(self, tenant, credential_alias, member_id=None):
        secret = self.credentials[credential_alias]
        session = self.Session(20180, tenant['host'], [])
        data = self.call(session, credential_alias, 'POST', '/adminapi/tenant/session/login',
                         {'email': secret['email'], 'password': secret['password'], 'tenant_code': tenant['code']})
        need(isinstance(data, dict) and isinstance(data.get('access_token'), str), 'LEGAL_SESSION_REQUIRED')
        session.token = data['access_token']
        self.sessions.append((session, credential_alias))
        context = data['context']
        need(int(context['tenant_id']) == tenant['id'] and context['audience'] == 'tenant', 'TENANT_IDENTITY_MISMATCH')
        info = self.call(session, credential_alias, 'POST', '/adminapi/user/info', {})
        need(info['root'] in [0, False] and int(info['id']) == int(context['tenant_member_id']), 'NONROOT_ACTOR_REQUIRED')
        need(member_id is None or int(info['id']) == member_id, 'MEMBER_IDENTITY_MISMATCH')
        return session, {'member_id': int(context['tenant_member_id']), 'account_id': int(context['account_id']),
                         'tenant_id': tenant['id'], 'credential_alias': credential_alias, 'root': False}

    def operator(self, tenant):
        session, identity = self.login(tenant, tenant['operator_credentials'], tenant['operator_member_id'])
        need(identity['account_id'] == tenant['operator_account_id'], 'OPERATOR_ACCOUNT_MISMATCH')
        return session

    def pages(self, session, actor, path, params=None):
        rows = []
        for page in range(1, 33):
            query = dict(params or {}, page_no=page, page_size=100)
            data = self.call(session, actor, 'GET', path + '?' + urllib.parse.urlencode(query))
            need(isinstance(data, dict) and isinstance(data['lists'], list) and len(data['lists']) <= 100, 'BOUNDED_LIST_REQUIRED')
            rows.extend(data['lists'])
            if len(rows) >= data['count']:
                return rows
        raise Stop('LIST_EXCEEDS_ADMITTED_PAGE_BOUND')

    def prepare(self, tenant):
        actor = tenant['operator_credentials']
        operator = self.operator(tenant)
        roles, departments, people = {}, {}, {}
        baseline_roles = self.pages(operator, actor, '/adminapi/role/lists')
        for role in tenant['roles']:
            name = self.c['namespace'] + '-' + str(tenant['id']) + '-' + role['alias']
            need(not any(row['name'] == name for row in baseline_roles), 'ROLE_NAMESPACE_ALREADY_EXISTS')
            operator = self.operator(tenant)
            self.call(operator, actor, 'POST', '/adminapi/role/add', {'name': name, 'desc': self.c['namespace'], 'menu_keys': []})
            rows = self.pages(operator, actor, '/adminapi/role/lists')
            found = [row for row in rows if row['name'] == name and row['id'] not in {r['id'] for r in baseline_roles}]
            need(len(found) == 1, 'NEW_ROLE_READBACK_AMBIGUOUS')
            role_id = int(found[0]['id'])
            detail = self.call(operator, actor, 'GET', '/adminapi/role/permissions?' + urllib.parse.urlencode({'role_id': role_id}))
            need(int(detail['role_id']) == role_id and detail['key'], 'CANONICAL_ROLE_KEY_REQUIRED')
            operator = self.operator(tenant)
            self.call(operator, actor, 'PUT', '/adminapi/role/permissions', {'role_id': role_id,
                      'expected_revision': detail['revision'], 'permission_keys': role['permissions']})
            actual = self.call(operator, actor, 'GET', '/adminapi/role/permissions?' + urllib.parse.urlencode({'role_id': role_id}))
            need(set(actual['permission_keys']) == set(role['permissions']), 'ROLE_PERMISSION_READBACK_MISMATCH')
            actual['role_id'] = int(actual['role_id'])
            actual['revision'] = int(actual['revision'])
            roles[role['alias']] = actual
            self.record({'state': 'READBACK_BOUND', 'tenant_id': tenant['id'], 'kind': 'role',
                         'alias': role['alias'], 'id': role_id, 'key': actual['key']})
            for policy in role['policies']:
                query = {'role_id': role_id, 'resource_key': policy['resource_key'], 'operation': policy['operation']}
                operator = self.operator(tenant)
                self.call(operator, actor, 'PUT', '/adminapi/role/data-policy', query | {'expected_revision': None,
                          'policy': {'status': 'active', 'reason': self.c['namespace'], 'valid_from': None, 'valid_until': None,
                          'groups': [{'name': self.c['namespace'], 'conditions': [{'condition_key': policy['condition_key'], 'target_set': None, 'config': []}]}]}})
                actual_policy = self.call(operator, actor, 'GET', '/adminapi/role/data-policy?' + urllib.parse.urlencode(query))
                need(actual_policy['status'] == 'active' and len(actual_policy['groups']) == 1
                     and len(actual_policy['groups'][0]['conditions']) == 1
                     and actual_policy['groups'][0]['conditions'][0]['condition_key'] == policy['condition_key'], 'DATA_POLICY_READBACK_REQUIRED')
        # Each code is the independently approved namespace, not a made-up alias
        # for a built-in scene key. Normal API still decides uniqueness.
        for department in tenant['departments']:
            code = self.c['namespace'] + '-' + department['alias']
            operator = self.operator(tenant)
            self.call(operator, actor, 'POST', '/adminapi/dept/add', {'code': code, 'name': code, 'pid': 0,
                      'leader': '', 'leader_member_id': None, 'mobile': '', 'sort': 0, 'status': 1})
            data = self.call(operator, actor, 'GET', '/adminapi/dept/lists?' + urllib.parse.urlencode({'mode': 'search', 'name': code, 'pageNo': 1, 'pageSize': 100}))
            found = [row for row in data['lists'] if row.get('code') == code]
            need(len(found) == 1, 'DEPARTMENT_READBACK_AMBIGUOUS')
            departments[department['alias']] = found[0]
            self.record({'state': 'READBACK_BOUND', 'tenant_id': tenant['id'], 'kind': 'department',
                         'alias': department['alias'], 'id': found[0]['id'], 'key': found[0]['code']})
        for person in tenant['actors']:
            secret = self.credentials[person['credentials']]
            need(secret['email'].startswith(self.c['namespace'] + '-'), 'NEW_ACCOUNT_NAMESPACE_REQUIRED')
            need(isinstance(secret['password'], str) and len(secret['password']) >= 16, 'NEW_PRIVATE_PASSWORD_REQUIRED')
            operator = self.operator(tenant)
            before = self.pages(operator, actor, '/adminapi/admin/lists', {'account': secret['email']})
            need(not before, 'EXISTING_MEMBER_MUST_NOT_BE_REACTIVATED')
            selected_roles = [roles[key]['role_id'] for key in person['roles']]
            self.call(operator, actor, 'POST', '/adminapi/admin/add', {'account': secret['email'],
                      'name': self.c['namespace'] + '-' + person['alias'], 'password': secret['password'],
                      'password_confirm': secret['password'], 'role_id': selected_roles,
                      'dept_id': [departments[person['department']]['id']], 'jobs_id': [], 'disable': 0, 'multipoint_login': 1})
            after = self.pages(operator, actor, '/adminapi/admin/lists', {'account': secret['email']})
            found = [row for row in after if row['account'] == secret['email']]
            need(len(found) == 1 and found[0]['id'] not in [1, 2, 3, 4] and found[0]['root'] == 0
                 and set(found[0]['role_id']) == set(selected_roles), 'NEW_NONROOT_MEMBER_READBACK_REQUIRED')
            expected_department = int(departments[person['department']]['id'])
            primary_department_id = verified_primary_department(found[0], expected_department)
            _, identity = self.login(tenant, person['credentials'], int(found[0]['id']))
            identity['primary_department_id'] = primary_department_id
            identity['primary_department_source'] = '/adminapi/admin/lists.dept_id'
            people[person['alias']] = identity
            self.record({'state': 'READBACK_BOUND', 'kind': 'member', 'alias': person['alias'], **identity})
        for department in tenant['departments']:
            if department['leader_actor'] is None:
                continue
            operator = self.operator(tenant)
            row = self.call(operator, actor, 'GET', '/adminapi/dept/detail?id=' + str(departments[department['alias']]['id']))
            leader = people[department['leader_actor']]['member_id']
            self.call(operator, actor, 'POST', '/adminapi/dept/edit', {'id': row['id'], 'revision': row['revision'],
                      'pid': row['pid'], 'name': row['name'], 'leader': row['leader'], 'leader_member_id': leader,
                      'mobile': row['mobile'], 'sort': row['sort'], 'status': row['status']})
            updated = self.call(operator, actor, 'GET', '/adminapi/dept/detail?id=' + str(row['id']))
            need(updated['leader_member_id'] == leader, 'LEADER_READBACK_REQUIRED')
            departments[department['alias']] = updated
        self.mapping.append({'tenant_id': tenant['id'], 'tenant_code': tenant['code'], 'host': tenant['host'],
                             'roles': roles, 'departments': departments, 'actors': people,
                             'assignment_inputs': {
                                 'member': {alias: str(row['member_id']) for alias, row in people.items()},
                                 'role_members': {alias: row['key'] for alias, row in roles.items()},
                                 'department_members': {alias: row['code'] for alias, row in departments.items()},
                                 'department_leaders': {alias: row['code'] for alias, row in departments.items() if row.get('leader_member_id') is not None},
                                 'primary_department_by_member': {str(row['member_id']): row['primary_department_id'] for row in people.values()},
                             }})

    def run(self):
        try:
            for tenant in self.c['tenants']:
                self.prepare(tenant)
            # Close only sessions this driver created; never logout-all.
            for session, alias in self.sessions:
                self.call(session, alias, 'POST', '/adminapi/tenant/session/logout', {})
            self.write_new(Path(self.authority['ledger'] + '.result.json'), {'schema': 'workflow-authorized-fixture-result/v1',
                           'status': 'IDENTITY_FIXTURE_READBACK_ONLY', 'business_pass': False,
                           'allocation_id': self.authority['allocation_id'], 'scope_sha256': self.authority['scope_sha256'],
                           'input_sha256': self.c['_input_sha256'],
                           'credentials': self.c['credentials'], 'tenants': self.mapping,
                           'workflow_instances': [], 'frozen_candidates': [], 'jobs': [], 'queue_consumed': 0,
                           'http': self.http, 'write_attempts': self.writes})
            self.record({'state': 'COMPLETE_IDENTITY_FIXTURE_ONLY', 'http': self.http, 'writes': self.writes})
        except Exception as error:
            self.record({'state': 'STOP_NO_REPLAY', 'error': str(error) if isinstance(error, Stop) else type(error).__name__,
                         'http': self.http, 'writes': self.writes, 'active_sessions_may_remain': len(self.sessions)})
            raise
        finally:
            os.close(self.fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['run', 'reconcile'])
    parser.add_argument('--input', required=True)
    parser.add_argument('--input-sha256', required=True)
    args = parser.parse_args()
    if args.action == 'reconcile':
        c = json.loads(private(args.input).read_bytes())
        need(digest(private(args.input).read_bytes()) == args.input_sha256, 'INPUT_HASH_MISMATCH')
        authority = pinned(c['authority'])
        ledger = private(authority['ledger'])
        rows = [json.loads(line) for line in ledger.read_text().splitlines()]
        pending = unresolved_intents(rows)
        print(json.dumps({'action': 'READ_ONLY_LOCAL_RECONCILIATION', 'unresolved': pending,
                          'last_state': rows[-1]['state'], 'automatic_replay_allowed': False}))
        return
    c, authority = load_input(args.input, args.input_sha256)
    Fixture(c, authority).run()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'status': 'STOP', 'code': str(error) if isinstance(error, Stop) else type(error).__name__,
                          'automatic_replay_allowed': False}))
        raise SystemExit(1)
