<?php

declare(strict_types=1);

return ['paths' => [
    '/adminapi/custom.ai-access.catalog' => ['get' => [
        'operationId' => 'aiAccessCatalog',
        'tags' => ['AiAccess'],
        'description' => '读取当前租户的 AI 服务商、可用模型和令牌配置状态。',
        'x-peanut-permissions' => ['custom.ai-access.view'],
        'responses' => ['200' => ['description' => 'AI 接入配置目录']],
    ]],
    '/adminapi/custom.ai-access.save' => ['post' => [
        'operationId' => 'aiAccessSave',
        'tags' => ['AiAccess'],
        'description' => '新增或更新租户 AI 服务商、令牌或模型。同一服务商的地址和 API 前缀在登记后固定。',
        'x-peanut-permissions' => ['custom.ai-access.configure'],
        'responses' => ['200' => ['description' => '已保存的配置，不含令牌值']],
    ]],
]];
