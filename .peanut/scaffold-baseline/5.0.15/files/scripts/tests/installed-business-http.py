#!/usr/bin/env python3
"""已安装合成应用的真实 HTTP 切片。只使用明确交接、登记和租约，不生成/重装/清库。
凭据由本机文件读入内存；结果只记录断言和响应状态，不记录令牌、Cookie或密码。
"""
from __future__ import annotations
import argparse
import base64
import io
import zipfile
import xml.etree.ElementTree as ET
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import time
import urllib.parse

PHP = '/opt/homebrew/bin/php'

class VerificationFailure(RuntimeError):
    pass

def require(condition: bool, code: str) -> None:
    if not condition:
        raise VerificationFailure(code)

def private_values(path: Path) -> dict[str, str]:
    st = path.lstat()
    require(path.is_file() and not path.is_symlink() and st.st_nlink == 1 and st.st_mode & 0o777 == 0o600, 'PRIVATE_FILE_PERMISSIONS')
    # 使用应用环境相同的 PHP INI 解释器，输出仅被父进程内存捕获，不打印或存证。
    p = subprocess.run([PHP, '-r', '$v=parse_ini_file($argv[1],false,INI_SCANNER_RAW);echo json_encode($v,JSON_THROW_ON_ERROR);', str(path)], capture_output=True, text=True, timeout=10)
    require(p.returncode == 0, 'PRIVATE_FILE_PARSE')
    return json.loads(p.stdout)

class Session:
    def __init__(self, port: int, host: str, events: list[dict]):
        self.port, self.host, self.events = port, host, events
        self.token = ''
        self.cookie = ''
        self.response_request_ids = ()

    def request(self, method: str, path: str, data=None, *, statuses=(200,), token=None, label=None, headers=None):
        self.response_request_ids = ()
        require(path.startswith('/') and not path.startswith('//'), 'HTTP_PATH')
        hs = {'Host': self.host, 'Accept': 'application/json', 'User-Agent': 'PeanutOwnedBusinessProbe/1.0', 'Origin': 'http://' + self.host, 'Sec-Fetch-Site': 'same-origin'}
        active = self.token if token is None else token
        if active:
            hs['Authorization'] = 'Bearer ' + active
        if self.cookie:
            hs['Cookie'] = self.cookie
        body = None
        if data is not None:
            body = data if isinstance(data, bytes) else json.dumps(data, ensure_ascii=False).encode()
            hs['Content-Type'] = 'application/json'
        hs.update(headers or {})
        connection = http.client.HTTPConnection('127.0.0.1', self.port, timeout=20)
        try:
            connection.request(method, path, body, hs)
            response = connection.getresponse()
            self.response_request_ids = tuple(value for name, value in response.getheaders()
                                              if name.lower() == 'x-request-id')
            raw = response.read(2_000_001)
            require(len(raw) <= 2_000_000, 'HTTP_RESPONSE_TOO_LARGE')
            cookie = response.getheader('Set-Cookie')
            if cookie:
                self.cookie = cookie.split(';', 1)[0]
            try:
                result = json.loads(raw) if raw else {}
            except json.JSONDecodeError:
                result = {'_non_json': True}
            event = {'case': label or path.split('?')[0], 'method': method, 'http_status': response.status, 'business_code': result.get('code'), 'error_code': result.get('error_code'), 'json': '_non_json' not in result}
            if isinstance(result.get('error'), dict):
                event['problem_code'] = result['error'].get('code')
            self.events.append(event)
            print(json.dumps(event, ensure_ascii=False), flush=True)
            require(response.status in statuses, 'HTTP_STATUS:' + event['case'] + ':' + str(response.status))
            require('_non_json' not in result, 'HTTP_EXPECTED_JSON:' + event['case'])
            if response.status < 300 and result.get('code') is not None:
                require(result['code'] == 20000, 'BUSINESS_REJECTED:' + event['case'] + ':' + str(result['code']))
            return result
        finally:
            connection.close()

    def upload(self, image: bytes):
        boundary = 'PeanutFixture' + secrets.token_hex(12)
        payload = ('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="fixture.png"\r\nContent-Type: image/png\r\n\r\n').encode() + image + ('\r\n--' + boundary + '--\r\n').encode()
        return self.data('POST', '/adminapi/official.file.upload.image', payload, label='synthetic-image-upload', headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})

    def download(self, url: str, label: str) -> bytes:
        parsed = urllib.parse.urlsplit(url)
        require(not parsed.scheme or parsed.scheme in ['http', 'https'], 'DELIVERY_SCHEME')
        require(not parsed.netloc or parsed.hostname in [self.host, '127.0.0.1', 'localhost'], 'DELIVERY_OTHER_HOST')
        path = parsed.path + ('?' + parsed.query if parsed.query else '')
        require(path.startswith('/api/storage/delivery'), 'EXPECTED_SIGNED_STORAGE_DELIVERY')
        c = http.client.HTTPConnection('127.0.0.1', self.port, timeout=20)
        try:
            c.request('GET', path, headers={'Host': self.host, 'User-Agent': 'PeanutOwnedBusinessProbe/1.0'})
            r = c.getresponse(); data = r.read(5_000_001)
            event = {'case': label, 'method': 'GET', 'http_status': r.status, 'bytes': len(data)}
            self.events.append(event); print(json.dumps(event), flush=True)
            require(r.status == 200 and len(data) <= 5_000_000, 'DELIVERY_FAILED:' + label + ':' + str(r.status))
            return data
        finally:
            c.close()

    def login(self, email: str, password: str, tenant_code='default'):
        r = self.request('POST', '/adminapi/tenant/session/login', {'email': email, 'password': password, 'tenant_code': tenant_code}, label='tenant-login')
        data = r.get('data', {})
        self.token = data.get('access_token', '')
        require(bool(self.token), 'TENANT_LOGIN_TOKEN_MISSING:keys=' + ','.join(data.keys()))
        return data

    def data(self, method, path, data=None, **kwargs):
        return self.request(method, path, data, **kwargs).get('data')

def article_chain(admin: Session, marker: str) -> dict:
    prefix = '/adminapi/official.article'
    def listing(recycle=False):
        return admin.data('GET', prefix + ('.recycle.list' if recycle else '.list') + '?' + urllib.parse.urlencode({'title': marker}), label='recycle-article-list' if recycle else 'ordinary-article-list')
    admin.request('POST', prefix + '.category.add', {'name': marker, 'is_show': 1, 'sort': 0, 'tenant_id': 999999}, statuses=(400, 422), label='protected-tenant-field-rejected')
    admin.request('POST', prefix + '.category.add', {'name': marker, 'is_show': 1, 'sort': 0}, label='category-create')
    cats = admin.data('GET', prefix + '.category.list?' + urllib.parse.urlencode({'name': marker}), label='category-count-and-list')
    require(cats['count'] == 1 and len(cats['lists']) == 1, 'CATEGORY_COUNT')
    cid = int(cats['lists'][0]['id'])
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
    uploaded = admin.upload(png)
    require(isinstance(uploaded, dict), 'UPLOAD_RESULT')
    reference = uploaded.get('uri') or uploaded.get('url') or uploaded.get('file_key')
    public_url = uploaded.get('url')
    require(bool(reference) and bool(public_url), 'UPLOAD_REFERENCE:keys=' + ','.join(uploaded))
    require(admin.download(public_url, 'uploaded-content-before-delete') == png, 'UPLOAD_BYTES')
    for suffix in ['-a', '-b']:
        admin.request('POST', prefix + '.add', {'title': marker + suffix, 'cid': cid, 'is_show': 1, 'sort': 0, 'image': reference, 'content': '<p>' + marker + suffix + '</p>'}, label='article-create' + suffix)
    rows = listing(); require(rows['count'] == 2 and len(rows['lists']) == 2, 'ARTICLE_COUNT')
    ids = {row['title'][-1]: int(row['id']) for row in rows['lists']}
    require(all(row['cate_name'] == marker for row in rows['lists']), 'ARTICLE_CATEGORY_JOIN')
    detail = admin.data('GET', prefix + '.detail?id=' + str(ids['a']), label='article-detail')
    require(detail['title'] == marker + '-a' and detail['image'], 'ARTICLE_DETAIL_CONTENT')
    def export(expected: set[str], recycle=False):
        route = prefix + ('.recycle.list' if recycle else '.list')
        common = {'title': marker, 'page_type': 0}
        meta = admin.data('GET', route + '?' + urllib.parse.urlencode({**common, 'export': 1}), label='export-metadata')
        require(int(meta['count']) == len(expected), 'EXPORT_COUNT')
        file = admin.data('GET', route + '?' + urllib.parse.urlencode({**common, 'export': 2, 'file_name': marker}), label='private-xlsx-create')
        payload = admin.download(file['url'], 'private-xlsx-download')
        with zipfile.ZipFile(io.BytesIO(payload)) as z:
            sheet = ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
        ns = {'x': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
        rs = sheet.findall('.//x:row', ns)
        require(len(rs) == len(expected) + 1, 'XLSX_ROW_COUNT')
        values = [''.join(r.itertext()) for r in rs[1:]]
        require(all(any(title in row for row in values) for title in expected), 'XLSX_ROWS')
        absent = {marker + '-a', marker + '-b'} - expected
        require(all(not any(title in row for row in values) for title in absent), 'XLSX_SOFT_DELETE_LEAK')
        require('tenant_id' not in ''.join(rs[0].itertext()), 'XLSX_INTERNAL_FIELDS')
    export({marker + '-a', marker + '-b'})
    admin.request('POST', prefix + '.category.delete', {'id': cid}, statuses=(409,), label='category-in-use-delete-rejected')
    admin.request('POST', prefix + '.delete', {'id': ids['a']}, label='article-soft-delete')
    admin.request('POST', prefix + '.delete', {'id': ids['a']}, label='article-repeat-soft-delete')
    rows = listing(); require(rows['count'] == 1 and rows['lists'][0]['id'] == ids['b'], 'SOFT_DELETE_VISIBLE_SCOPE')
    admin.request('GET', prefix + '.detail?id=' + str(ids['a']), statuses=(400, 404, 422), label='deleted-ordinary-detail-rejected')
    recycled = listing(True); require(recycled['count'] == 1 and recycled['lists'][0]['id'] == ids['a'], 'RECYCLE_SCOPE')
    admin.data('GET', prefix + '.recycle.detail?id=' + str(ids['a']), label='authorized-recycle-detail')
    export({marker + '-b'}); export({marker + '-a'}, True)
    require(admin.download(public_url, 'shared-file-survives-soft-delete') == png, 'REFERENCED_FILE_LOST')
    admin.request('POST', prefix + '.restore', {'id': ids['a']}, label='article-restore')
    admin.request('POST', prefix + '.restore', {'id': ids['a']}, label='article-repeat-restore')
    require(listing()['count'] == 2 and listing(True)['count'] == 0, 'RESTORE_SCOPE')
    require(admin.download(public_url, 'shared-file-survives-restore') == png, 'RESTORED_FILE_LOST')
    # 分类回收与文章恢复的真实关联冲突：200批次回执也必须检查逐项失败，不能误报成功。
    for key in ['a', 'b']:
        admin.request('POST', prefix + '.delete', {'id': ids[key]}, label='article-delete-before-category-recycle')
    admin.request('POST', prefix + '.category.delete', {'id': cid}, label='category-soft-delete')
    admin.request('POST', prefix + '.category.delete', {'id': cid}, label='category-repeat-soft-delete')
    query = urllib.parse.urlencode({'name': marker})
    require(admin.data('GET', prefix + '.category.list?' + query, label='deleted-category-ordinary-count')['count'] == 0, 'CATEGORY_SOFT_DELETE_SCOPE')
    require(admin.data('GET', prefix + '.category.recycle.list?' + query, label='category-recycle-list')['count'] == 1, 'CATEGORY_RECYCLE_COUNT')
    admin.data('GET', prefix + '.category.recycle.detail?id=' + str(cid), label='category-recycle-detail')
    conflict = admin.data('POST', prefix + '.restore', {'id': ids['a']}, label='article-restore-category-conflict')
    require(conflict['restored'] == [] and len(conflict['failed']) == 1 and conflict['failed'][0]['code'] == 'ARTICLE_CATEGORY_UNAVAILABLE', 'RESTORE_CONFLICT_NOT_REPORTED')
    require(listing()['count'] == 0 and listing(True)['count'] == 2, 'FAILED_RESTORE_MUTATED_DATA')
    restored = admin.data('POST', prefix + '.category.restore', {'id': cid}, label='category-restore')
    require(restored['restored'] == [cid] and restored['failed'] == [], 'CATEGORY_RESTORE_FAILED')
    repeated = admin.data('POST', prefix + '.category.restore', {'id': cid}, label='category-repeat-restore')
    require(repeated['already_active'] == [cid] and repeated['failed'] == [], 'CATEGORY_RESTORE_NOT_IDEMPOTENT')
    restored = admin.data('POST', prefix + '.restore', {'ids': [ids['a'], ids['b']]}, label='article-batch-restore')
    require(set(restored['restored']) == set(ids.values()) and restored['failed'] == [], 'ARTICLE_BATCH_RESTORE_FAILED')
    require(listing()['count'] == 2 and listing(True)['count'] == 0, 'FINAL_ARTICLE_SCOPE')
    require(admin.download(public_url, 'shared-file-survives-category-and-article-restore') == png, 'FINAL_REFERENCE_FILE_LOST')
    return {'category_id': cid, 'article_ids': ids, 'marker': marker, 'file_sha256': hashlib.sha256(png).hexdigest(), 'real_http': True, 'real_private_exports': 3, 'file_retained': True}

def member_chain(port: int, events: list, host: str, marker: str) -> dict:
    members = []
    for i in range(2):
        s = Session(port, host, events)
        account = marker.replace('-', '') + str(i)
        password = 'Synthetic!' + secrets.token_hex(12)
        s.request('POST', '/api/login/register', {'account': account, 'password': password}, label='member-register-' + str(i))
        login = s.data('POST', '/api/login/account', {'account': account, 'password': password, 'terminal': 1}, label='member-login-' + str(i))
        s.token = login.get('token') or login.get('access_token', '')
        require(bool(s.token), 'MEMBER_TOKEN_MISSING:keys=' + ','.join(login))
        info = s.data('GET', '/api/user/info', label='member-self-info-' + str(i))
        nickname = marker + str(i)
        s.request('POST', '/api/user/setInfo', {'field': 'nickname', 'value': nickname}, label='member-self-update-' + str(i))
        require(s.data('GET', '/api/user/info', label='member-profile-reload-' + str(i))['nickname'] == nickname, 'MEMBER_PROFILE_NOT_SAVED')
        s.request('POST', '/api/user/setInfo', {'field': 'user_money', 'value': 999999}, statuses=(400, 403, 422), label='member-protected-field-rejected')
        members.append((s, info['id'], nickname))
    require(members[0][1] != members[1][1], 'MEMBER_IDENTITIES_NOT_DISTINCT')
    require(members[0][0].data('GET', '/api/user/info')['nickname'] == members[0][2], 'MEMBER_CROSS_USER_STATE')
    old = members[0][0].token
    members[0][0].request('POST', '/api/login/logout', {}, label='member-logout')
    members[0][0].request('GET', '/api/user/info', token=old, statuses=(401, 403), label='member-old-session-denied')
    require(members[1][0].data('GET', '/api/user/info')['nickname'] == members[1][2], 'LOGOUT_AFFECTED_OTHER_MEMBER')
    members[1][0].request('POST', '/api/login/logout', {}, label='second-member-logout')
    return {'members': 2, 'profile_persisted': True, 'old_session_denied': True, 'independent_sessions': True}

def run_application(a: dict, output: Path) -> dict:
    app = Path(a['app']).resolve()
    require('/.local/tmp/' in str(app), 'ONLY_GENERATED_SYNTHETIC_APPLICATION')
    manifest = json.loads((app / '.peanut/application-manifest.json').read_text())
    require(manifest['template']['source_commit'] == a['source'] and manifest['generation_source']['commit'] == a['source'], 'APPLICATION_SOURCE_MISMATCH')
    require(hashlib.sha256((app / 'server/composer.lock').read_bytes()).hexdigest() == a['lock_sha256'], 'APPLICATION_LOCK_DRIFT')
    patch_path = Path('server/app/modules/official/article/src/Validation/ArticleValidate.php')
    current_root = Path(__file__).resolve().parents[2]
    patch_sha = hashlib.sha256((app / patch_path).read_bytes()).hexdigest()
    require(patch_sha == hashlib.sha256((current_root / patch_path).read_bytes()).hexdigest(), 'APPLICATION_RUNTIME_PATCH_MISMATCH')
    registry = json.loads((app / 'resources/project-resources.json').read_text())
    selected = [r for r in registry['resources']['local_listeners'] if r.get('stable_resource_id') == a['http_resource_id']]
    require(len(selected) == 1 and selected[0]['port'] == a['http_port'] and selected[0]['host'] == '127.0.0.1', 'HTTP_RESOURCE_MISMATCH')
    env_file = Path(a['backend_env'])
    private_values(env_file)  # 权限检查，不复制或打印环境。
    credentials = private_values(Path(a['installation_env']))
    tmp = Path(a['business_task_root']); tmp.mkdir(mode=0o700, parents=True, exist_ok=True)
    require(not tmp.is_symlink(), 'TMP_SYMLINK')
    env = {k: os.environ[k] for k in ['HOME', 'LANG'] if k in os.environ}
    env.update({'PATH': '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin', 'TMPDIR': str(tmp), 'PEANUT_SERVER_ENV_FILE': str(env_file)})
    proof = '''require $argv[1].'/server/bootstrap/environment.php';require $argv[1].'/server/database/environment-guard.php';$c=guardedDatabaseConfig();$d=guardedConnection($c);$i=$d->query('SELECT DATABASE() db,@@server_uuid uuid')->fetch(PDO::FETCH_ASSOC);$i['tables']=(int)$d->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();echo json_encode($i,JSON_THROW_ON_ERROR);'''
    p = subprocess.run([PHP, '-d', 'zend.exception_ignore_args=1', '-r', proof, str(app)], cwd=app, env=env, text=True, capture_output=True, timeout=25)
    require(p.returncode == 0, 'APPLICATION_RESOURCE_PREFLIGHT_FAILED')
    state = json.loads(p.stdout)
    expected_uuid = '1d4c182c-b838-11f1-bcc7-66bbf24c017a' if a['edition'] == 'standalone' else '1d67fb93-b838-11f1-b754-be0163be5292'
    require(state['uuid'] == expected_uuid and state['db'] == a['resource']['database'] and state['tables'] == 117, 'INSTALLED_DATABASE_IDENTITY_MISMATCH')
    port = a['http_port']
    with socket.socket() as s:
        s.bind(('127.0.0.1', port))
    events = []
    result = {'edition': a['edition'], 'base_source_commit': a['source'], 'runtime_patch': {'path': str(patch_path), 'sha256': patch_sha}, 'plugin_lock_sha256': hashlib.sha256((app / 'plugins.lock').read_bytes()).hexdigest(), 'database': state, 'events': events, 'browser_tested': False, 'two_tenants_tested': False}
    log_path = tmp / 'php-http-private.log'
    with log_path.open('w') as log:
        log_path.chmod(0o600)
        process = subprocess.Popen([PHP, '-d', 'zend.exception_ignore_args=1', '-d', 'display_errors=0', '-S', '127.0.0.1:' + str(port), '-t', 'public', 'public/router.php'], cwd=app / 'server', env=env, stdout=log, stderr=log)
        try:
            deadline = time.monotonic() + 15
            while True:
                require(process.poll() is None, 'PHP_HTTP_PROCESS_EXITED')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.5):
                        break
                except OSError:
                    require(time.monotonic() < deadline, 'PHP_HTTP_START_TIMEOUT')
                    time.sleep(0.1)
            admin = Session(port, 'admin.generated.test', events)
            admin.request('GET', '/adminapi/login/info', statuses=(401, 403), label='anonymous-admin-denied')
            identity = admin.login(credentials['ADMIN_INITIAL_EMAIL'], credentials['ADMIN_INITIAL_PASSWORD'])
            result['tenant_login_shape'] = list(identity)
            admin.data('POST', '/adminapi/user/info', {}, label='authenticated-admin-info')
            categories = admin.data('GET', '/adminapi/official.article.category.list', label='authorized-category-list')
            result['category_result_shape'] = list(categories) if isinstance(categories, dict) else type(categories).__name__
            marker = 'http-' + secrets.token_hex(6)
            result['business'] = article_chain(admin, marker)
            if a['edition'] == 'standalone':
                public = Session(port, 'member.generated.test', events)
                visible = public.data('GET', '/api/article/lists?' + urllib.parse.urlencode({'cid': result['business']['category_id']}), label='public-created-article-content')
                require(marker in json.dumps(visible, ensure_ascii=False), 'PUBLIC_ARTICLE_CONTENT_MISSING')
                result['members'] = member_chain(port, events, public.host, marker)
            before = admin.token
            refreshed = admin.data('POST', '/adminapi/tenant/session/refresh', {}, label='tenant-session-refresh')
            require(isinstance(refreshed, dict) and refreshed.get('access_token'), 'REFRESH_TOKEN_MISSING')
            admin.token = refreshed['access_token']
            require(admin.token != before, 'REFRESH_DID_NOT_ROTATE')
            admin.request('POST', '/adminapi/user/info', {}, token=before, statuses=(401, 403), label='old-access-after-refresh-denied')
            admin.request('POST', '/adminapi/user/info', {}, label='new-access-after-refresh')
            old = admin.token
            admin.request('POST', '/adminapi/tenant/session/logout', {}, statuses=(200, 204), label='tenant-logout')
            admin.request('POST', '/adminapi/user/info', {}, token=old, statuses=(401, 403), label='old-access-after-logout-denied')
            admin.request('POST', '/adminapi/tenant/session/refresh', {}, statuses=(401, 403), label='old-refresh-after-logout-denied')
            result['status'] = 'passed'
        except Exception as exc:
            result.update({'status': 'failed', 'error': str(exc) if isinstance(exc, VerificationFailure) else type(exc).__name__})
        finally:
            process.terminate()
            try:
                process.wait(timeout=8)
            except subprocess.TimeoutExpired:
                process.kill(); process.wait(timeout=3)
            result['owned_http_process_stopped'] = process.poll() is not None
    (output / (a['edition'] + '-http-result.json')).write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({k: v for k, v in result.items() if k != 'events'}, ensure_ascii=False), flush=True)
    return result

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--applications', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    require('/.local/tmp/' in str(args.output.resolve()), 'OWNED_TMP_OUTPUT_REQUIRED')
    args.output.mkdir(mode=0o700, parents=True, exist_ok=True)
    results = [run_application(a, args.output) for a in json.loads(args.applications.read_text())]
    raise SystemExit(0 if all(r['status'] == 'passed' for r in results) else 1)
