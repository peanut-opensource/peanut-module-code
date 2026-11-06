<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblemMapper;
use app\common\model\TenantOwnedModel;
use app\common\tenancy\MultiTenantDataScopePolicy;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Modules\File\Service\FileAdministrationService;
use PeanutAdmin\Modules\File\Service\FileAuthorizationService;
use PeanutAdmin\Modules\File\Service\FileDocumentReferenceRegistry;
use PeanutAdmin\Modules\File\Service\FileService;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Model;

final class FileRenameTenantBoundaryTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRenameCannotDistinguishAnotherTenantsFileFromAMissingFile(): void
    {
        require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        $app = new App(sys_get_temp_dir() . '/file-rename-' . bin2hex(random_bytes(6)));
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($pdo);
        $pdo->exec("CREATE TABLE pa_file (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, update_time INTEGER, delete_time INTEGER);
            INSERT INTO pa_file (id, tenant_id, name) VALUES (1, 101, 'alpha'), (2, 202, 'beta')");
        $store = new ExecutionContextStore();
        $current = new CurrentExecutionContext($store);
        $scope = new MultiTenantDataScopePolicy($current);
        Model::maker(static function (Model $model) use ($scope): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($scope);
            }
        });

        $sessions = $this->createStub(TenantSessionAccess::class);
        $sessions->method('revalidateSession')->willReturnCallback(static fn() => $current->tenantAdmin());
        $authorization = $this->createStub(AdminAuthorizationQuery::class);
        $authorization->method('principal')->willReturnCallback(static fn(TenantContext $actor) => AdminPrincipal::fromArray([
            'id' => $actor->memberId, 'tenant_id' => $actor->tenantId, 'account_id' => $actor->accountId, 'authorization_revision' => 1,
        ]));
        $authorization->method('decide')->willReturnCallback(static fn($actor, $principal, string $key) =>
            in_array($key, ['peanut.file.manage', 'peanut.file.rename'], true)
                ? PermissionDecision::allow($key) : PermissionDecision::deny($key, 'NOT_GRANTED'));
        $modules = $this->createStub(ModuleRuntimeRepository::class);
        $modules->method('installation')->willReturn(new ModuleInstallationRecord('peanut.file', '1.0', 'active', 1, str_repeat('a', 64)));
        $modules->method('tenantModule')->willReturnCallback(static fn(int $tenant, string $key) => new TenantModuleRecord($tenant, $key, 'enabled', null, null, 1));
        $access = new FileAuthorizationService($authorization, $sessions, $modules, $store, new FileDocumentReferenceRegistry($app, null));
        $storage = (new \ReflectionClass(StorageService::class))->newInstanceWithoutConstructor();
        $files = new FileAdministrationService($storage, $current, new FileService($storage, 'https://test.invalid'), $access);
        $rename = static function (int $tenant, int $id, string $name) use ($store, $files): void {
            $actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(
                $tenant, 'file-test-' . $tenant, $tenant, $tenant, $tenant, 'admin-web', new \DateTimeImmutable('2031-01-01T00:00:00Z'), 1, 1,
            ), 'rename-test-' . $tenant);
            $store->run(new AdminExecutionContext($actor, 'file.rename.test'), fn() => $files->rename($id, $name));
        };

        $rename(101, 1, 'alpha-owned');
        $rename(202, 2, 'beta-owned');
        foreach ([1, 999] as $target) {
            try {
                $rename(202, $target, 'forbidden');
                self::fail('A file outside the current tenant scope was renamed');
            } catch (BusinessException|\InvalidArgumentException $error) {
                $problem = (new ApiProblemMapper())->map($error);
                self::assertSame([400, 'INVALID_ARGUMENT', '素材不存在'], [$problem?->httpStatus, $problem?->data()['error_code'], $problem?->getMessage()]);
            }
        }
        self::assertSame(['alpha-owned', 'beta-owned'], $pdo->query('SELECT name FROM pa_file ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
