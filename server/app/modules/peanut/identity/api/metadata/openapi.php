<?php

declare(strict_types=1);

$contracts = json_decode(
    (string) file_get_contents(__DIR__ . '/../../resources/device-credential-contract.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$operation = $contracts['operation'];
$openApiSchema = static function (array $node) use (&$openApiSchema): array {
    foreach (['properties', 'required'] as $keyword) {
        if (($node[$keyword] ?? null) === []) {
            unset($node[$keyword]);
        }
    }
    if (array_key_exists('const', $node)) {
        $node['enum'] = [$node['const']];
        unset($node['const']);
    }
    if (($node['type'] ?? null) === 'null') {
        $node['type'] = 'object';
        $node['nullable'] = true;
        $node['enum'] = [null];
    }
    if (is_array($node['type'] ?? null)) {
        $types = array_values(array_diff($node['type'], ['null']));
        if (count($types) !== 1 || !in_array('null', $node['type'], true)) {
            throw new LogicException('Unsupported device credential OpenAPI type union.');
        }
        $node['type'] = $types[0];
        $node['nullable'] = true;
    }
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = $openApiSchema($value);
        }
    }
    return $node;
};
$response = [
    'description' => '设备凭据登记结果。',
    'content' => ['application/json' => ['schema' => [
        '$ref' => '#/components/schemas/IdentityEnrollDeviceCredentialResponse',
    ]]],
];

$metadata = [
    'paths' => [
        $operation['path'] => [
            'x-peanut-required-modules' => ['peanut.identity'],
            'post' => [
                'tags' => ['Identity'], 'operationId' => $operation['operation_id'], 'summary' => '登记设备公钥凭据',
                'requestBody' => [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => [
                        '$ref' => '#/components/schemas/IdentityEnrollDeviceCredentialRequest',
                    ]]],
                ],
                'responses' => [
                    '201' => $response, '202' => $response, '400' => $response, '404' => $response,
                    '409' => $response, '422' => $response,
                    '401' => ['$ref' => '#/components/responses/ErrorResponse'],
                    '403' => [
                        'description' => '当前办理身份或设备凭据登记权限不可用。',
                        'content' => ['application/json' => ['schema' => ['oneOf' => [
                            ['$ref' => '#/components/schemas/ErrorEnvelope'],
                            ['$ref' => '#/components/schemas/IdentityEnrollDeviceCredentialResponse'],
                        ]]]],
                    ],
                ],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'IdentityEnrollDeviceCredentialRequest' => $openApiSchema($operation['request_schema']),
        'IdentityEnrollDeviceCredentialResponse' => $openApiSchema($operation['response_schema']),
    ]],
];

$revocationContract = json_decode(
    (string) file_get_contents(__DIR__ . '/../../resources/device-credential-revocation-contract.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$revocation = $revocationContract['operation'];
$revocationResponse = [
    'description' => '设备凭据撤销结果。',
    'content' => ['application/json' => ['schema' => [
        '$ref' => '#/components/schemas/IdentityRevokeDeviceCredentialResponse',
    ]]],
];
$metadata['paths'][$revocation['path']] = [
    'x-peanut-required-modules' => ['peanut.identity'],
    'post' => [
        'tags' => ['Identity'], 'operationId' => $revocation['operation_id'], 'summary' => '撤销设备凭据',
        'parameters' => [[
            'name' => 'key', 'in' => 'path', 'required' => true,
            'schema' => $openApiSchema($revocation['request_schema']['properties']['command_key']),
        ]],
        'requestBody' => [
            'required' => true,
            'content' => ['application/json' => ['schema' => [
                '$ref' => '#/components/schemas/IdentityRevokeDeviceCredentialRequest',
            ]]],
        ],
        'responses' => [
            '200' => $revocationResponse, '202' => $revocationResponse, '400' => $revocationResponse,
            '404' => $revocationResponse, '409' => $revocationResponse, '422' => $revocationResponse,
            '401' => ['$ref' => '#/components/responses/ErrorResponse'],
            '403' => [
                'description' => '当前办理身份或设备凭据撤销权限不可用。',
                'content' => ['application/json' => ['schema' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/ErrorEnvelope'],
                    ['$ref' => '#/components/schemas/IdentityRevokeDeviceCredentialResponse'],
                ]]]],
            ],
        ],
    ],
];
$metadata['components']['schemas']['IdentityRevokeDeviceCredentialRequest'] = $openApiSchema($revocation['request_schema']);
$metadata['components']['schemas']['IdentityRevokeDeviceCredentialResponse'] = $openApiSchema($revocation['response_schema']);
return $metadata;
