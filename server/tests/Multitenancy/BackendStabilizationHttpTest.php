<?php

declare(strict_types=1);

/** D01–D03：每次请求独立进程走真实 ThinkPHP 路由、认证及模块边界，避免容器状态串线。 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';

use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Notification\Delivery\Application\VerificationCodeSecret;

$serverRoot = dirname(__DIR__, 2);
if (($argv[1] ?? '') === '--request') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    // MultiApp 读取全局入口文件名；CLI 测试文件不能被误认作应用名。
    $_SERVER['SCRIPT_FILENAME'] = $serverRoot . '/public/index.php';
    $app = new think\App($serverRoot);
    $app->setRuntimePath($input['runtime']);
    $headers = ['host' => $input['host'], 'authorization' => $input['token'] === '' ? '' : 'Bearer ' . $input['token'], 'accept' => 'application/json', 'x-request-id' => $input['request_id']];
    if ($input['user_agent'] !== null) {
        $headers['user-agent'] = $input['user_agent'];
    }
    $request = new app\Request();
    $request->setMethod($input['method'])->setPathinfo($input['path'])
        ->setUrl('/' . $input['path'])->setBaseUrl('/' . $input['path'])
        ->withServer(['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => $input['method'], 'HTTP_HOST' => $input['host'], 'SCRIPT_NAME' => '/index.php'])
        ->withHeader($headers)
        ->withGet($input['method'] === 'GET' ? $input['params'] : [])
        ->withPost($input['method'] === 'GET' ? [] : $input['params']);
    $response = $app->http->run($request);
    echo json_encode(['status' => $response->getCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
    $app->http->end($response);
    exit;
}

$assertions = 0;
$requests = 0;
$failures = [];
$slice = $argv[1] ?? 'all';
if (!in_array($slice, ['all', 'd01', 'd02', 'd03'], true)) {
    throw new InvalidArgumentException('Expected all, d01, d02 or d03 slice');
}
function httpExpect(bool $condition, string $message): void
{
    global $assertions, $failures;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
        echo 'FAIL ' . $message . "\n";
    }
}
function stabilizationHttp(
    string $method,
    string $path,
    array $params = [],
    string $token = '',
    string $host = 'alpha.d03.test',
    ?string $userAgent = 'Peanut Backend Stabilization HTTP Fixture',
): array {
    global $requests, $runtime;
    $requests++;
    $environment = getenv();
    foreach (peanutBackendEnvironmentKeys() as $key) {
        unset($environment[$key], $environment['PHP_' . $key]);
    }
    $environment['PEANUT_SERVER_ENV_FILE'] = IsolatedBackendEnvironment::required('PEANUT_SERVER_ENV_FILE');
    $process = proc_open([PHP_BINARY, __FILE__, '--request'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('HTTP worker could not start');
    }
    fwrite($pipes[0], json_encode(['method' => $method, 'path' => $path, 'params' => $params, 'token' => $token, 'host' => $host, 'user_agent' => $userAgent, 'request_id' => 'stabilization-http-' . $requests, 'runtime' => $runtime], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $result = json_decode($stdout, true);
    if ($code !== 0 || !is_array($result)) {
        throw new RuntimeException('HTTP worker failed for ' . $path . ': ' . substr($stderr, 0, 1200));
    }
    return $result;
}
function httpSuccess(array $response, string $label): void
{
    httpExpect($response['status'] === 200 && ($response['body']['code'] ?? null) === 20000, $label . ': ' . json_encode($response, JSON_UNESCAPED_UNICODE));
}
function httpError(array $response, string $error, string $label): void
{
    httpExpect(($response['body']['data']['error_code'] ?? null) === $error, $label . ': ' . json_encode($response, JSON_UNESCAPED_UNICODE));
}
function seedHttpCode(PDO $pdo, string $scene, string $mobile, string $code): int
{
    $query = $pdo->prepare('SELECT id FROM pa_notice_scene WHERE tenant_id=101 AND code=?');
    $query->execute([$scene]);
    $sceneId = (int) $query->fetchColumn();
    if ($sceneId < 1) {
        throw new RuntimeException('HTTP verification scene missing: ' . $scene);
    }
    $insert = $pdo->prepare('INSERT INTO pa_notice_log (tenant_id,scene_id,channel,receiver,status,verify_code_hash,check_count,is_verified,send_time,create_time) VALUES (101,?,1,?,1,?,0,0,?,?)');
    $insert->execute([$sceneId, $mobile, VerificationCodeSecret::hash($code), time(), time()]);
    return (int) $pdo->lastInsertId();
}

// An explicit isolated application environment is required; never inherit the development APP.
IsolatedBackendEnvironment::required('PEANUT_SERVER_ENV_FILE');
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$database = RegisteredMysqlTestResource::configuredDatabaseName();
$runtime = sys_get_temp_dir() . '/peanut-stabilization-http-' . bin2hex(random_bytes(8)) . '/';
mkdir($runtime, 0700);
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App($serverRoot);
    $app->setRuntimePath($runtime);
    $app->initialize();
    IdentityMysqlFixture::install($pdo);
    $pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $registry = $app->make(app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider::class)->registry();
    $migrationDirectories = [];
    foreach ($registry->compiled()->modules as $manifest) {
        if ($manifest->data['key'] !== 'peanut.identity' && isset($manifest->data['backend']['migrations'])) {
            $migrationDirectories[] = $manifest->root . '/' . $manifest->data['backend']['migrations'];
        }
    }
    IdentityMysqlFixture::install($pdo, $migrationDirectories);
    $app->make(PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer::class)->synchronize();
    $app->make(app\platform\infrastructure\module\ModuleCatalogApplier::class)->apply($registry->compiled());
    $pdo->exec("INSERT INTO pa_tenant_entry_binding (tenant_id,host,client_key) VALUES (101,'alpha.d03.test','member-api'),(202,'beta.d03.test','member-api'),(101,'alpha.d03.test','admin-web'),(202,'beta.d03.test','admin-web')");
    foreach ($registry->compiled()->modules as $manifest) {
        $insert = $pdo->prepare("INSERT INTO pa_module_installation (module_key,installed_version,manifest_schema_version,manifest_digest,status,created_at,updated_at) VALUES (?,?,?,?,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $insert->execute([$manifest->data['key'], $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
        $insert = $pdo->prepare("INSERT INTO pa_tenant_module (tenant_id,module_key,status,created_at,updated_at) VALUES (101,?,'enabled',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,?,'enabled',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $insert->execute([$manifest->data['key'], $manifest->data['key']]);
    }
    $hash = app(PeanutAdmin\Kernel\Identity\PasswordHasher::class)->hash('D03HttpPassword2026');
    foreach ([[1501,501,101,'alpha'], [1502,502,202,'beta']] as [$account, $member, $tenant, $code]) {
        $pdo->exec("INSERT INTO pa_account (id,display_name,created_at,updated_at) VALUES ({$account},'HTTP Operator',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $insert = $pdo->prepare("INSERT INTO pa_credential (account_id,kind,identifier_type,identifier_normalized,secret_hash,verified_at,secret_changed_at,created_at,updated_at) VALUES (?,'email_password','email',?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $insert->execute([$account, $code . '-http@example.test', $hash]);
        $pdo->exec("INSERT INTO pa_tenant_member (id,tenant_id,account_id,member_no,display_name,status,joined_at,created_at,updated_at) VALUES ({$member},{$tenant},{$account},'http-{$code}','HTTP Operator','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO pa_role (id,tenant_id,`key`,name,is_builtin,status,created_at,updated_at) VALUES ({$member},{$tenant},'http.operator','HTTP Operator',0,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO pa_member_role (tenant_id,tenant_member_id,role_id,assigned_at) VALUES ({$tenant},{$member},{$member},UTC_TIMESTAMP(3))");
    }
    $permissions = ['core.role.read','core.role.create','core.role.update','core.role.archive','core.department.read','core.department.create','core.department.update','core.department.archive','core.member.create','core.member.update','core.member.leave'];
    foreach ($permissions as $key) {
        $select = $pdo->prepare('SELECT id FROM pa_permission WHERE `key`=?');
        $select->execute([$key]);
        $permission = (int) $select->fetchColumn();
        if ($permission < 1) {
            throw new RuntimeException('Current HTTP route permission missing: ' . $key);
        }
        $pdo->exec("INSERT INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (101,501,{$permission},UTC_TIMESTAMP(3)),(202,502,{$permission},UTC_TIMESTAMP(3))");
    }
    if (in_array($slice, ['all', 'd03'], true)) {
        $auth = app(TenantAuthService::class);
        $alpha = $auth->login('alpha-http@example.test', 'D03HttpPassword2026', 'alpha', '127.0.0.1', 'D03 HTTP', 'd03-http-login-a')->tokens->access->expose();
        $beta = $auth->login('beta-http@example.test', 'D03HttpPassword2026', 'beta', '127.0.0.1', 'D03 HTTP', 'd03-http-login-b')->tokens->access->expose();
        httpExpect(stabilizationHttp('GET', 'adminapi/role/lists')['status'] === 401, 'D03 missing token did not fail in login middleware');
        httpExpect(stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'forged','tenant_id' => 101], 'pa_tat_forged')['status'] === 403, 'D03 forged session did not fail');
        httpSuccess(stabilizationHttp('GET', 'adminapi/role/lists', [], $alpha), 'D03 original role query');
        httpSuccess(stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'HTTP Alpha','tenant_id' => 202], $alpha), 'D03 role create');
        httpSuccess(stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'HTTP Beta'], $beta, 'beta.d03.test'), 'D03 same permissions beta create');
        $roleA = (int) $pdo->query("SELECT id FROM pa_role WHERE tenant_id=101 AND name='HTTP Alpha'")->fetchColumn();
        $roleB = (int) $pdo->query("SELECT id FROM pa_role WHERE tenant_id=202 AND name='HTTP Beta'")->fetchColumn();
        httpExpect($roleA > 0 && $roleB > 0, 'D03 trusted session tenant not used for role writes');
        if ($roleA > 0 && $roleB > 0) {
            httpSuccess(stabilizationHttp('POST', 'adminapi/role/edit', ['id' => $roleA,'name' => 'HTTP Alpha Edited','menu_id' => []], $alpha), 'D03 role edit');
            httpExpect(stabilizationHttp('POST', 'adminapi/role/edit', ['id' => $roleB,'name' => 'Cross tenant'], $alpha)['status'] === 404, 'D03 same-permission cross-tenant role write accepted');
            httpExpect($pdo->query("SELECT name FROM pa_role WHERE id={$roleB}")->fetchColumn() === 'HTTP Beta', 'D03 cross-tenant role mutated');
            httpSuccess(stabilizationHttp('POST', 'adminapi/role/delete', ['id' => $roleA], $alpha), 'D03 role archive');
        }
        httpSuccess(stabilizationHttp('POST', 'adminapi/dept/add', ['name' => 'HTTP Dept','pid' => 0,'status' => 1], $alpha), 'D03 department create');
        $dept = (int) $pdo->query("SELECT id FROM pa_department WHERE tenant_id=101 AND name='HTTP Dept'")->fetchColumn();
        if ($dept > 0) {
            httpSuccess(stabilizationHttp('POST', 'adminapi/dept/edit', ['id' => $dept,'name' => 'HTTP Dept Edited','pid' => 0,'status' => 1], $alpha), 'D03 department edit');
            httpSuccess(stabilizationHttp('POST', 'adminapi/dept/status', ['id' => $dept,'status' => 0], $alpha), 'D03 department status');
            httpSuccess(stabilizationHttp('POST', 'adminapi/dept/delete', ['id' => $dept], $alpha), 'D03 department archive');
        }
        $memberParams = ['account' => 'http-created@example.test','name' => 'HTTP Created','password' => 'D03MemberPassword2026','password_confirm' => 'D03MemberPassword2026','role_id' => [501],'disable' => 0,'multipoint_login' => 1];
        httpSuccess(stabilizationHttp('POST', 'adminapi/admin/add', $memberParams, $alpha), 'D03 member create');
        $member = (int) $pdo->query("SELECT id FROM pa_tenant_member WHERE tenant_id=101 AND display_name='HTTP Created'")->fetchColumn();
        if ($member > 0) {
            unset($memberParams['password'],$memberParams['password_confirm']);
            httpSuccess(stabilizationHttp('POST', 'adminapi/admin/edit', $memberParams + ['id' => $member], $alpha), 'D03 member edit');
            httpSuccess(stabilizationHttp('POST', 'adminapi/admin/status', ['id' => $member,'disable' => 1], $alpha), 'D03 member suspend');
            httpSuccess(stabilizationHttp('POST', 'adminapi/admin/status', ['id' => $member,'disable' => 0], $alpha), 'D03 member activate');
            httpSuccess(stabilizationHttp('POST', 'adminapi/admin/delete', ['id' => $member], $alpha), 'D03 member leave');
        }
        httpSuccess(stabilizationHttp('GET', 'adminapi/admin/self', [], $alpha), 'D03 self query');
        httpSuccess(stabilizationHttp('POST', 'adminapi/admin/editSelf', ['nickname' => 'HTTP Self No UA','password_old' => 'D03HttpPassword2026','password' => 'D03HttpChangedNoUa2026','password_confirm' => 'D03HttpChangedNoUa2026'], $alpha, 'alpha.d03.test', null), 'D03 self profile and password without User-Agent');
        httpExpect(stabilizationHttp('GET', 'adminapi/role/lists', [], $alpha)['status'] === 403, 'D03 HTTP password change without User-Agent did not invalidate session');
        $alphaWithUserAgent = $auth->login('alpha-http@example.test', 'D03HttpChangedNoUa2026', 'alpha', '127.0.0.1', 'D03 HTTP', 'd03-http-login-a-with-ua')->tokens->access->expose();
        httpSuccess(stabilizationHttp('POST', 'adminapi/admin/editSelf', ['nickname' => 'HTTP Self','password_old' => 'D03HttpChangedNoUa2026','password' => 'D03HttpChanged2026','password_confirm' => 'D03HttpChanged2026'], $alphaWithUserAgent), 'D03 self profile and password with User-Agent');
        httpExpect(stabilizationHttp('GET', 'adminapi/role/lists', [], $alphaWithUserAgent)['status'] === 403, 'D03 HTTP password change with User-Agent did not invalidate session');
        httpExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE actor_tenant_member_id=501 AND actor_account_id=1501 AND event_type='tenant.role.created'")->fetchColumn() > 0, 'D03 HTTP write lost trusted audit identity');
        // 取消 beta 的单项权限，原会话必须经授权修订重新判定，不可绕过权限中间件。
        $pdo->exec("DELETE rp FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE rp.tenant_id=202 AND p.`key`='core.role.create'");
        $pdo->exec('UPDATE pa_tenant SET authorization_revision=authorization_revision+1 WHERE id=202');
        httpExpect(stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'Permission denied'], $beta, 'beta.d03.test')['status'] === 403, 'D03 permission middleware did not deny removed grant');
    }

    // D02：真实 JWT → CheckToken → 官方会员模块 → 应用服务 → MySQL。
    $pdo->exec("INSERT INTO pa_member (id,tenant_id,sn,nickname,mobile,status,create_time,update_time) VALUES (701,101,'HTTP701','HTTP Member','13800000701',1,1,1),(702,202,'HTTP702','Other Tenant','13800000702',1,1,1)");
    $token = app(PeanutAdmin\Modules\Member\Contract\MemberAccessTokens::class)->createToken(701);
    if (in_array($slice, ['all', 'd02'], true)) {
        httpExpect(stabilizationHttp('POST', 'api/user/setInfo', ['field' => 'nickname','value' => 'Missing'])['status'] === 401, 'D02 profile missing token not denied');
        httpSuccess(stabilizationHttp('POST', 'api/user/setInfo', ['field' => 'nickname','value' => 'Updated HTTP Member','id' => 702,'tenant_id' => 202], $token), 'D02 valid profile field and forged payload identity');
        httpExpect($pdo->query('SELECT nickname FROM pa_member WHERE id=701')->fetchColumn() === 'Updated HTTP Member' && $pdo->query('SELECT nickname FROM pa_member WHERE id=702')->fetchColumn() === 'Other Tenant', 'D02 token subject did not constrain write');
        foreach ([['nickname',['bad']],['avatar',['bad']],['sex','3'],['birthday','2025-02-29'],['email','bad-email']] as [$field,$value]) {
            httpError(stabilizationHttp('POST', 'api/user/setInfo', compact('field', 'value'), $token), 'MEMBER_PROFILE_VALUE_INVALID', 'D02 invalid ' . $field);
        }
        httpSuccess(stabilizationHttp('POST', 'api/user/setInfo', ['field' => 'birthday','value' => ''], $token), 'D02 empty birthday compatibility');
        httpSuccess(stabilizationHttp('POST', 'api/user/setInfo', ['field' => 'birthday','value' => '2000-02-29'], $token), 'D02 valid birthday');
        httpSuccess(stabilizationHttp('POST', 'api/user/setInfo', ['field' => 'birthday','value' => ''], $token), 'D02 clear existing birthday');
        httpExpect($pdo->query('SELECT birthday FROM pa_member WHERE id=701')->fetchColumn() === null, 'D02 cleared birthday did not persist NULL');
    }

    // D01：种入合成的“已发送”验证码，不调用真实短信提供方。
    if (in_array($slice, ['all', 'd01'], true)) {
        foreach (['login_code','reset_password'] as $scene) {
            $insert = $pdo->prepare("INSERT INTO pa_notice_scene (tenant_id,code,name,sms_status,sms_template_id) VALUES (101,?,?,1,'fixture') ON DUPLICATE KEY UPDATE sms_status=1,sms_template_id='fixture'");
            $insert->execute([$scene,$scene]);
        }
        foreach (['login/mobile' => 'login_code','login/resetPassword' => 'reset_password'] as $route => $scene) {
            $logId = seedHttpCode($pdo, $scene, '13800000701', '6172');
            $params = ['mobile' => '13800000701','code' => 'wrong','password' => 'D01HttpChanged2026'];
            httpError(stabilizationHttp('POST', 'api/' . $route, $params), 'MEMBER_VERIFICATION_REJECTED', 'D01 incorrect code ' . $route);
            httpExpect((int) $pdo->query("SELECT check_count FROM pa_notice_log WHERE id={$logId}")->fetchColumn() === 1, 'D01 HTTP failure counter not persisted: ' . $route);
            $params['code'] = '6172';
            httpSuccess(stabilizationHttp('POST', 'api/' . $route, $params), 'D01 correct code ' . $route);
            httpError(stabilizationHttp('POST', 'api/' . $route, $params), 'MEMBER_VERIFICATION_REJECTED', 'D01 replay ' . $route);
        }
        $logId = seedHttpCode($pdo, 'login_code', '13800000701', '9182');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            stabilizationHttp('POST', 'api/login/mobile', ['mobile' => '13800000701','code' => 'wrong']);
        }
        httpError(stabilizationHttp('POST', 'api/login/mobile', ['mobile' => '13800000701','code' => '9182']), 'MEMBER_VERIFICATION_REJECTED', 'D01 exhausted code rejects correct value');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            stabilizationHttp('POST', 'api/login/mobile', ['mobile' => '13800000701','code' => 'wrong']);
        }
        httpError(stabilizationHttp('POST', 'api/login/mobile', ['mobile' => '13800000701','code' => '9182']), 'MEMBER_VERIFICATION_RATE_LIMITED', 'D01 entry repeated-failure throttle');
        httpExpect((int) $pdo->query("SELECT check_count FROM pa_notice_log WHERE id={$logId}")->fetchColumn() === 5, 'D01 exhausted code must stop at exactly its failure budget');
        httpExpect(stabilizationHttp('POST', 'api/login/mobile', ['mobile' => '13800000701','code' => '918273'], '', 'unbound.d03.test')['status'] === 503, 'D01 unbound public Host was accepted');
    }
    $pdo->exec("UPDATE pa_tenant_module SET status='disabled' WHERE tenant_id=101 AND module_key='peanut.member'");
    if (in_array($slice, ['all', 'd02'], true)) {
        httpExpect(stabilizationHttp('POST', 'api/user/setInfo', ['field' => 'nickname','value' => 'Disabled'], $token)['status'] === 403, 'D02 disabled member module was accepted');
    }
    if (in_array($slice, ['all', 'd01'], true)) {
        httpExpect(stabilizationHttp('POST', 'api/login/mobile', ['mobile' => '13800000701','code' => '918273'])['status'] === 403, 'D01 disabled member module was accepted');
    }
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
    IsolatedBackendEnvironment::cleanup();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($runtime);
}
echo 'BACKEND-STABILIZATION-HTTP-001 slice=' . $slice . '; requests=' . $requests . '; assertions=' . $assertions . '; failures=' . count($failures) . "\n";
foreach ($failures as $failure) {
    echo $failure . "\n";
}
exit($failures === [] ? 0 : 1);
