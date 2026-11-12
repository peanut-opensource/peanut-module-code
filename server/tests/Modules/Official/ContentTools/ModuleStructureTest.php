<?php

declare(strict_types=1);

// This executable verifies generated declarations only, never claims tenant/business qualification.
$root = dirname(__DIR__, 5);
$backend = $root . '/' . "server/app/modules/peanut/content_tools";
$manifest = json_decode((string) file_get_contents($backend . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$composer = json_decode((string) file_get_contents($backend . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$prefix = "PeanutAdmin\\Modules\\ContentTools\\";
if (($manifest['key'] ?? null) !== "peanut.content-tools"
    || ($composer['autoload']['psr-4'][$prefix] ?? null) !== 'src/'
    || ($manifest['backend']['provider'] ?? null) !== $prefix . 'ModuleProvider'
    || !is_file($backend . '/src/ModuleProvider.php')) {
    throw new RuntimeException('GENERATED_MODULE_STRUCTURE_INVALID');
}
$frontend = "web/src/modules/peanut-content-tools/contribution.ts";
if (($manifest['frontend']['entry'] ?? null) !== $frontend
    || ($frontend !== null && !is_file($root . '/' . $frontend))) {
    throw new RuntimeException('GENERATED_MODULE_FRONTEND_INVALID');
}
echo 'MODULE-STRUCTURE-001 passed; business/security qualification not executed' . PHP_EOL;
