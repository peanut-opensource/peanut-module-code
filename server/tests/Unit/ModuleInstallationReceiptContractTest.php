<?php

declare(strict_types=1);

namespace tests\Unit;

use PDO;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** A read-only Identity projection used by Ops; no lease, tenant grant or real database. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ModuleInstallationReceiptContractTest extends TestCase
{
    public function testPackageSmokeProjectionIsNarrowAndDoesNotGrantAccess(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        require_once $root . '/server/tests/Support/ThinkPhpTestConnection.php';
        new App($root . '/.local/tmp/installation-receipt-' . bin2hex(random_bytes(6)));
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($pdo);
        $pdo->exec('CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY, status TEXT, installed_version TEXT, artifact_sha256 TEXT, lock_digest TEXT, last_error_code TEXT, manifest_digest TEXT)');
        $digest = hash('sha256', 'synthetic-package');
        $statement = $pdo->prepare('INSERT INTO pa_module_installation VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute(['peanut.ops', 'active', '1.0.32', $digest, 'not-public', null, hash('sha256', 'ops-manifest')]);
        $statement->execute(['peanut.workflow', 'uninstalled', '4.0.0-rc.48', $digest, 'not-public', null, hash('sha256', 'workflow-manifest')]);
        $queries = new TenantModuleStateQueries();
        self::assertNull($queries->operationInstallationReceipt(''));
        self::assertNull($queries->operationInstallationReceipt('peanut.missing'));
        self::assertSame([
            'status' => 'active',
            'installed_version' => '1.0.32',
            'artifact_sha256' => $digest,
            'lock_digest' => 'not-public',
            'last_error_code' => null,
            'manifest_digest' => hash('sha256', 'ops-manifest'),
        ], $queries->operationInstallationReceipt('peanut.ops'));
        self::assertSame('uninstalled', $queries->operationInstallationReceipt('peanut.workflow')['status']);
        self::assertSame(hash('sha256', 'workflow-manifest'), $queries->operationInstallationReceipt('peanut.workflow')['manifest_digest']);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM pa_module_installation')->fetchColumn());

        // Exercise the production Ops postflight against the Identity-owned projection.
        $worker = (new \ReflectionClass(\PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpModuleOperationTaskExecutionService::class))
            ->newInstanceWithoutConstructor();
        $smoke = new \ReflectionMethod($worker, 'smoke');
        self::assertTrue($smoke->invoke($worker,
            ['package_key' => 'peanut.ops', 'operation' => 'update'],
            ['package_key' => 'peanut.ops', 'version' => '1.0.32', 'artifact_sha256' => $digest],
        ));
        self::assertFalse($smoke->invoke($worker,
            ['package_key' => 'peanut.ops', 'operation' => 'update'],
            ['package_key' => 'peanut.ops', 'version' => '1.0.32', 'artifact_sha256' => str_repeat('0', 64)],
        ));
        self::assertTrue($smoke->invoke($worker,
            ['package_key' => 'peanut.workflow', 'operation' => 'retire'],
            ['package_key' => 'peanut.workflow'],
        ));
        $purgeResult = ['package_key' => 'peanut.workflow', 'operation' => 'purged',
            'affected_modules' => [['module_key' => 'peanut.workflow', 'version' => '4.0.0-rc.48',
                'manifest_digest' => hash('sha256', 'workflow-manifest')]]];
        self::assertTrue($smoke->invoke($worker,
            ['package_key' => 'peanut.workflow', 'operation' => 'purge'], $purgeResult,
        ));
        $purgeResult['affected_modules'][0]['manifest_digest'] = str_repeat('0', 64);
        self::assertFalse($smoke->invoke($worker,
            ['package_key' => 'peanut.workflow', 'operation' => 'purge'], $purgeResult,
        ));
        $pdo->exec("UPDATE pa_module_installation SET last_error_code='PLUGIN_LIFECYCLE_FAILED' WHERE module_key='peanut.workflow'");
        self::assertFalse($smoke->invoke($worker,
            ['package_key' => 'peanut.workflow', 'operation' => 'purge'],
            ['package_key' => 'peanut.workflow', 'operation' => 'unchanged', 'removed' => []],
        ));
    }
}
