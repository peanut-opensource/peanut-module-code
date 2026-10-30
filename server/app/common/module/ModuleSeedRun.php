<?php

declare(strict_types=1);

namespace app\common\module;

use app\platform\exception\plugin\PluginLifecycleException;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use think\migration\command\seed\Run;

/** Selects one Module's declared seeds; the native runner owns execution and transactions. */
final class ModuleSeedRun extends Run
{
    public function __construct(private readonly ManifestDocument $manifest)
    {
        parent::__construct();
    }

    protected function getPath()
    {
        $path = $this->manifest->data['backend']['seeds'] ?? null;
        $root = realpath($this->manifest->root);
        $directory = $path === 'database/seeds' ? realpath($this->manifest->root . '/' . $path) : false;
        if ($root === false || $directory === false || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
            throw new PluginLifecycleException('MODULE_SEED_PATH_INVALID', 'Module seeds must use its own database/seeds directory.');
        }
        return $directory;
    }
}
