<?php

declare(strict_types=1);

use app\adminapi\services\OperationLogService;
use app\common\contract\audit\AuditEvent;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\audit\OperationLogProjection;
use app\common\model\log\OperationLog;
use think\facade\Db;

require __DIR__ . '/../support/NativeAccessFixture.php';

Db::execute('CREATE TABLE pa_operation_log (id INTEGER PRIMARY KEY, tenant_id INTEGER, admin_id INTEGER,
    username TEXT, ip TEXT, uri TEXT, method TEXT, request_id TEXT, params TEXT, create_time INTEGER)');
$actor = businessActor();
$contexts = new ExecutionContextStore();
$projection = new OperationLogProjection(new CurrentExecutionContext($contexts));
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks): void {
    businessCheck($passed, $message);
    ++$checks;
};
$sensitive = [
    'credential_public_key', 'credentialPublicKey', 'public-key', 'PUBLIC_KEY',
    'device_fingerprint_hash', 'deviceFingerprintHash', 'key_fingerprint',
    'credential_signature', 'credential_key', 'Authorization',
];
$params = ['client_key' => 'dcs-pos', 'command_key' => businessKey(24001), 'reason' => 'synthetic retirement'];
foreach ($sensitive as $key) {
    $params[$key] = 'synthetic-private-value-' . $key;
}
$params['nested'] = [['publicKey' => 'synthetic-nested-key', 'fingerprint' => 'synthetic-nested-fingerprint']];
$expected = array_replace($params, array_fill_keys($sensitive, '******'));
$expected['nested'] = [['publicKey' => '******', 'fingerprint' => '******']];

$contexts->run(new AdminExecutionContext($actor, 'http.admin.device-credential'), static function () use ($projection, $actor, $params): void {
    $projection->append(AuditEvent::operationLog(
        $actor, $actor->memberId, 'synthetic admin', '127.0.0.1',
        '/adminapi/api/v1/identity/device-credentials/enroll', 'POST', $params,
    ));
});
$row = Db::table('pa_operation_log')->where('tenant_id', $actor->tenantId)->find();
$stored = json_decode($row['params'], true, flags: JSON_THROW_ON_ERROR);
foreach ($sensitive as $key) {
    $check($stored[$key] === '******', 'The native operation-log projection redacts ' . $key);
}
$check($stored === $expected, 'Nested secrets are redacted and ordinary operation identifiers remain useful.');
$check((int) $row['tenant_id'] === $actor->tenantId && $row['request_id'] === $actor->requestId,
    'The persisted operation keeps its actual tenant and request identity.');
$check(OperationLogService::serializeParams($params) === $row['params'], 'The host facade uses the same redaction policy.');
$check(!str_contains($row['params'], 'synthetic-private-value') && !str_contains($row['params'], 'synthetic-nested'),
    'The persisted JSON contains none of the raw credential values.');

// Old rows stay intact in storage while the normal model read applies the current policy.
$legacy = json_encode($params, JSON_THROW_ON_ERROR);
Db::table('pa_operation_log')->where('id', $row['id'])->update(['params' => $legacy]);
$read = OperationLog::find($row['id']);
$check(json_decode($read->params, true, flags: JSON_THROW_ON_ERROR) === $expected,
    'The actual operation-log model redacts historic credential fields when read.');
$check(Db::table('pa_operation_log')->where('id', $row['id'])->value('params') === $legacy,
    'Reading a historical log does not rewrite its original stored evidence.');
$check($contexts->isEmpty() && !Db::connect()->getPdo()->inTransaction(), 'The projection releases its execution context.');

echo json_encode(['suite' => 'DeviceCredentialAuditRedaction', 'checks' => $checks,
    'scope' => 'native host operation-log projection and historical model reads on isolated SQLite'], JSON_THROW_ON_ERROR) . PHP_EOL;
