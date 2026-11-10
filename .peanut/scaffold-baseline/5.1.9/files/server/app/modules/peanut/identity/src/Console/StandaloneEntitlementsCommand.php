<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Console;

use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use PeanutAdmin\Modules\Identity\Platform\Application\PlanLicenseAdminService;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** Local deployment owner only; never registered with the tenant scheduler or HTTP. */
final class StandaloneEntitlementsCommand extends ContextualCommand
{
    public static function __make(\think\App $app): self
    {
        return new self($app->make(PlanLicenseAdminService::class),$app->make(ExecutionContextStore::class),$app->make(CurrentExecutionContext::class));
    }

    public function __construct(
        private readonly ?PlanLicenseAdminService $licenses = null,
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $execution = null,
    ) {
        parent::__construct($contexts,$execution);
    }

    protected function configure(): void
    {
        $this->setName('identity:standalone-entitlements')
            ->setDescription('Replace explicit default-tenant plan selections without changing the license state or term')
            ->addOption('application',null,Option::VALUE_REQUIRED,'Exact native application slug')
            ->addOption('selection-file',null,Option::VALUE_REQUIRED,'JSON containing explicit revisions, module_keys, permission_keys and change_reason');
    }

    protected function handle(Input $input, Output $output): int
    {
        if (PHP_SAPI !== 'cli' || !$this->establishedInstanceContext()) {
            throw new \DomainException('STANDALONE_ENTITLEMENTS_ROOT_REQUIRED');
        }
        $serverRoot = dirname(__DIR__,6);
        $owner = fileowner($serverRoot);
        if (!is_int($owner) || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')
            || !in_array(posix_geteuid(),[0,$owner],true) || !is_array(posix_getpwuid(posix_geteuid()))) {
            throw new \DomainException('STANDALONE_ENTITLEMENTS_DEPLOYMENT_OWNER_REQUIRED');
        }
        $identity = is_file($serverRoot . '/.peanut/release-identity.json')
            ? ServerReleaseIdentity::resolve($serverRoot) : ApplicationSourceIdentity::load($serverRoot);
        if (($identity->applicationIdentity()['edition'] ?? null) !== 'standalone'
            || $identity->applicationSlug() !== $input->getOption('application')) {
            throw new \DomainException('STANDALONE_ENTITLEMENTS_APPLICATION_MISMATCH');
        }
        $path = (string) $input->getOption('selection-file');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('STANDALONE_ENTITLEMENTS_SELECTION_FILE_REQUIRED');
        }
        $selection = json_decode((string) file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($selection) || array_is_list($selection)) {
            throw new \InvalidArgumentException('STANDALONE_ENTITLEMENTS_SELECTION_INVALID');
        }
        $result = ($this->licenses ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED'))->saveStandaloneEntitlements($selection);
        $output->writeln(json_encode($result,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return 0;
    }
}
