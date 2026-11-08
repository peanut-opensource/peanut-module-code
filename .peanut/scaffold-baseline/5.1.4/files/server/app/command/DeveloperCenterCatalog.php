<?php

declare(strict_types=1);

namespace app\command;

use app\platform\services\developer\DeveloperCenterCatalogService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/** Emits the same read-only developer catalog consumed by the Platform API. */
final class DeveloperCenterCatalog extends Command
{
    protected function configure()
    {
        $this->setName('developer:center')
            ->setDescription('Print the read-only Module developer catalog')
            ->addOption('module', null, Option::VALUE_REQUIRED, 'Optional exact Module key', '');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $module = trim((string) $input->getOption('module'));
            $snapshot = $this->app->make(DeveloperCenterCatalogService::class)
                ->snapshot($module === '' ? null : $module);
            $output->writeln((string) json_encode(
                $snapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            return 0;
        } catch (\Throwable $exception) {
            $output->writeln((string) json_encode([
                'status' => 'blocked',
                'code' => 'DEVELOPER_CENTER_CATALOG_FAILED',
                'reason' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return 1;
        }
    }
}
