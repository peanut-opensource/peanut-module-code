<?php

declare(strict_types=1);

use app\api\controller\UploadController;
use app\common\enum\FileEnum;
use app\common\execution\ConsumerExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;

function expectMemberUpload(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Installed-instance assertions. The native qualification owner must supply the
 * initialized application, selected PDO and host-authenticated member only after
 * write-ahead protection of the complete DB/storage write set. No seed or
 * installation is performed here. The standalone entry remains NotRun below.
 */
function memberUploadInstalledAssertions(
    think\App $app,
    PDO $pdo,
    AuthenticatedMemberContext $member,
    int $otherTenantId,
    array $expectedDatabaseIdentity,
    callable $guard,
): void {
    $installation = $app->make(app\common\services\installation\InstallationExecutionHost::class);
    $status = $installation->status();
    expectMemberUpload(($status['state'] ?? null) === 'installed' && ($status['deployment_mode'] ?? null) === 'multi-tenant', 'healthy installed multi-tenant Code is required');
    expectMemberUpload(app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity($pdo) === $expectedDatabaseIdentity, 'native database identity differs from the protected admission');
    $tenantId = $member->tenantId;
    $memberId = $member->memberId;
    $forgedMemberId = $memberId === 1 ? 2 : 1;
    expectMemberUpload($otherTenantId > 0 && $otherTenantId !== $tenantId, 'distinct installed Tenant is required');
    $otherTenant = $pdo->prepare("SELECT COUNT(*) FROM pa_tenant WHERE id=? AND status='active'");
    $otherTenant->execute([$otherTenantId]);
    expectMemberUpload((int) $otherTenant->fetchColumn() === 1, 'other installed Tenant is unavailable');
    $principal = $pdo->prepare("SELECT COUNT(*) FROM pa_member m JOIN pa_tenant t ON t.id=m.tenant_id WHERE m.id=? AND m.tenant_id=? AND m.status=1 AND m.delete_time IS NULL AND t.status='active'");
    $principal->execute([$memberId, $tenantId]);
    expectMemberUpload((int) $principal->fetchColumn() === 1, 'host member does not match the installed database');
    $serverRoot = rtrim($app->getRootPath(), '/');
    $routeSource = (string) file_get_contents($serverRoot . '/app/modules/peanut/file/route/app.php');
    $uploadRoute = "Route::post('upload/image', [ApiUploadController::class, 'image'])";
    expectMemberUpload(substr_count($routeSource, $uploadRoute) === 1, 'member upload route is missing or duplicated');
    // Indentation is formatting; the ordered identity and Module middleware remain mandatory.
    $middlewareRouteSource = preg_replace('/\R[ \t]+(?=->middleware\()/', "\n", $routeSource);
    expectMemberUpload(
        str_contains(
            $middlewareRouteSource,
            $uploadRoute . "\n->middleware(CheckTokenMiddleware::class)\n->middleware(OfficialModuleMiddleware::class",
        ),
        'member upload route is not protected by identity and Module middleware',
    );

    $fileCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM pa_file')->fetchColumn();
    $objectCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn();
    $storedObject = null;
    $temporaryUpload = tempnam(sys_get_temp_dir(), 'peanut-member-upload-');

    try {
        $request = $app->request;
        $request->withPost([
            'cid' => 0,
            'tenant_id' => $otherTenantId,
            'source_id' => $forgedMemberId,
            'source' => FileEnum::SOURCE_ADMIN,
            'member_id' => $forgedMemberId,
        ]);

        expectMemberUpload($temporaryUpload !== false, 'could not allocate upload fixture');
        file_put_contents(
            $temporaryUpload,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        );
        $request->withFiles([
            'file' => [
                'name' => 'member-avatar.png',
                'type' => 'image/png',
                'tmp_name' => $temporaryUpload,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($temporaryUpload),
            ],
        ]);
        $app->instance('request', $request);

        $contexts = $app->make(ExecutionContextStore::class);
        $guard();
        $response = $contexts->run(
            ConsumerExecutionContext::member($member, 'member.file.upload'),
            static function () use ($app): object {
                $controller = $app->make(UploadController::class);
                $controller->initialize();
                return $controller->image();
            },
        );
        expectMemberUpload($contexts->isEmpty(), 'member upload leaked its execution context');
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        expectMemberUpload(($body['code'] ?? null) === 20000, 'member upload failed: ' . ($body['msg'] ?? 'unknown error'));

        $fileKey = $body['data']['file_key'] ?? null;
        expectMemberUpload(is_string($fileKey) && preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) === 1, 'upload response has no canonical file identity');
        $query = $pdo->prepare(<<<'SQL'
    SELECT f.tenant_id, f.source_id, f.source, f.type, f.name, f.file_key,
           o.object_key, o.status, o.created_by_member_id, a.driver, s.local_path
    FROM pa_file f
    JOIN pa_file_object o ON o.file_key = f.file_key
    JOIN pa_storage_space s ON s.id = o.storage_space_id
    JOIN pa_storage_account a ON a.id = s.account_id
    WHERE f.file_key = ? AND f.tenant_id = ?
    SQL);
        $query->execute([$fileKey, $tenantId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        expectMemberUpload(is_array($row), 'member upload did not create a file row');
        expectMemberUpload((int) $row['tenant_id'] === $tenantId, 'payload forged uploaded file Tenant ownership');
        expectMemberUpload((int) $row['source_id'] === $memberId, 'payload forged uploaded file member owner');
        expectMemberUpload((int) $row['source'] === FileEnum::SOURCE_USER, 'member upload was stored as an admin upload');
        expectMemberUpload((int) $row['type'] === FileEnum::IMAGE, 'member upload file type changed');
        expectMemberUpload($row['name'] === 'member-avatar.png', 'member upload original name changed');
        expectMemberUpload(preg_match('/^file_[0-9a-f]{32}$/D', (string) $row['file_key']) === 1, 'member upload file identity changed');
        expectMemberUpload(str_starts_with((string) $row['object_key'], 'tenants/v1/' . $tenantId . '/material/image/'), 'member upload escaped its Tenant object namespace');
        expectMemberUpload(!str_contains((string) $row['object_key'], '/' . $otherTenantId . '/'), 'payload Tenant appeared in stored object namespace');
        expectMemberUpload($row['driver'] === 'local' && $row['local_path'] === 'public/storage', 'member upload did not use the registered local public storage route');
        expectMemberUpload($row['status'] === 'ready', 'member upload object was not made ready');
        expectMemberUpload((int) $row['created_by_member_id'] === $memberId, 'payload forged the storage object member owner');
        expectMemberUpload(($body['data']['file_key'] ?? null) === $row['file_key'], 'upload response did not return the canonical file identity');

        $storedObject = $serverRoot . '/public/storage/' . $row['object_key'];
        expectMemberUpload(is_file($storedObject), 'member upload object was not written to storage');
        expectMemberUpload((int) $pdo->query('SELECT COUNT(*) FROM pa_file')->fetchColumn() === $fileCountBefore + 1, 'member upload created an unexpected number of rows');
        expectMemberUpload((int) $pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn() === $objectCountBefore + 1, 'member upload created an unexpected number of object rows');

        echo "MT03-MEMBER-UPLOAD-TENANT-WIRING-001 passed\n";
    } finally {
        // Persisted objects and DB rows are retained, including on FAIL/UNKNOWN.
        // Only the process-local upload input is disposable; restoration is a native
        // owner decision after reconciling actual writes and any reopened writers.
        if (is_string($temporaryUpload) && is_file($temporaryUpload)) {
            unlink($temporaryUpload);
        }
    }
}

// These assertions require a separately authorized installed-instance owner.
// Loading this file must not manufacture a qualification frame or initialize data.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    fwrite(STDERR, "NOT_RUN: supply an authorized installed-instance harness before invoking these assertions.\n");
    exit(2);
}
