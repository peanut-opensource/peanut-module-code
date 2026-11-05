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
        $pdo->exec('CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY, status TEXT, installed_version TEXT, artifact_sha256 TEXT, lock_digest TEXT)');
        $digest = hash('sha256', 'synthetic-package');
        $statement = $pdo->prepare('INSERT INTO pa_module_installation VALUES (?, ?, ?, ?, ?)');
        $statement->execute(['peanut.ops', 'active', '1.0.32', $digest, 'not-public']);
        $statement->execute(['peanut.workflow', 'uninstalled', '4.0.0-rc.48', $digest, 'not-public']);
        $queries = new TenantModuleStateQueries();
        self::assertNull($queries->operationInstallationReceipt(''));
        self::assertNull($queries->operationInstallationReceipt('peanut.missing'));
        self::assertSame([
            'status' => 'active',
            'installed_version' => '1.0.32',
            'artifact_sha256' => $digest,
        ], $queries->operationInstallationReceipt('peanut.ops'));
        self::assertSame('uninstalled', $queries->operationInstallationReceipt('peanut.workflow')['status']);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM pa_module_installation')->fetchColumn());
    }
}
