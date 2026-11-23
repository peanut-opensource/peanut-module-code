<?php

declare(strict_types=1);

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\contract\authorization\AuthorizedOperationFactory;
use app\common\services\authorization\AdminAuthorizationService;

return [
    AdminAuthorizationQuery::class => AdminAuthorizationService::class,
    AuthorizedOperationFactory::class => AdminAuthorizationService::class,
];
