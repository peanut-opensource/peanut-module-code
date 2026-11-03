<?php

declare(strict_types=1);

$common = require dirname(__DIR__, 5) . '/api/metadata/application/common.php';
extract($common, EXTR_SKIP);
$applicationError = $responseRef('ErrorResponse');
$legacyPaths = json_decode(<<<'JSON'
{
    "/api/sms/sendCode": {
        "x-peanut-required-modules": [
            "peanut.member",
            "peanut.notification"
        ],
        "post": {
            "tags": [
                "Notice"
            ],
            "operationId": "sendVerificationCode",
            "requestBody": {
                "required": true,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": [
                                "scene"
                            ],
                            "properties": {
                                "scene": {
                                    "type": "string"
                                },
                                "mobile": {
                                    "type": "string"
                                }
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {
                    "description": "验证码发送请求已受理",
                    "content": {
                        "application/json": {
                            "schema": {
                                "$ref": "#/components/schemas/NotificationMutationResponse"
                            }
                        }
                    }
                },
                "400": {
                    "$ref": "#/components/responses/ErrorResponse"
                },
                "403": {
                    "$ref": "#/components/responses/ErrorResponse"
                },
                "409": {
                    "$ref": "#/components/responses/ErrorResponse"
                }
            },
            "x-peanut-errors": [
                "VERIFICATION_CODE_SEND_REJECTED"
            ]
        }
    }
}
JSON, true, 512, JSON_THROW_ON_ERROR);

return [
    'paths' => [
        ...$legacyPaths,
        '/api/login/logout' => ['x-peanut-required-modules' => ['peanut.member'], 'post' => $operation(
            'logoutMemberSession',
            'Auth',
            ['200' => $emptySuccess, '401' => $applicationError],
        )],
    ],
];
