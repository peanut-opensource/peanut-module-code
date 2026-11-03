#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/WorkbenchDemoData.php';

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if ($arguments === ['--plan']) {
    echo json_encode(WorkbenchDemoData::plan(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}
fwrite(STDERR, "Demo data is selected at application creation (--demo=workbench) and written only during fresh installation. Existing applications are never seeded by this command. Use --plan to inspect the data.\n");
exit(64);
