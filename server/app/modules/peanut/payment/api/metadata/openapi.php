<?php

declare(strict_types=1);

$schema = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$error = ['$ref' => '#/components/responses/ErrorResponse'];
$body = static fn(string $name, string $media = 'application/json'): array => [
    'required' => true,
    'content' => [$media => ['schema' => ['$ref' => '#/components/schemas/' . $name]]],
];
$ok = static fn(string $name, string $description): array => [
    'description' => $description,
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $name]]],
];
$query = static fn(string $name, array $value, bool $required = false): array => [
    'in' => 'query', 'name' => $name, 'required' => $required, 'schema' => $value,
];
$operation = static function (
    string $id,
    string $summary,
    string $responseSchema,
    array $parameters = [],
    ?string $requestSchema = null,
    array $errors = [],
    array $statuses = [],
    ?string $description = null,
) use ($body, $ok, $error): array {
    $value = [
        'tags' => ['Payment'],
        'operationId' => $id,
        'summary' => $summary,
        'parameters' => $parameters,
        'responses' => ['200' => $ok($responseSchema, $summary . '成功')],
    ];
    if ($requestSchema !== null) {
        $value['requestBody'] = $body($requestSchema);
    }
    foreach ($statuses as $status) {
        $value['responses'][(string) $status] = $error;
    }
    if ($errors !== []) {
        $value['x-peanut-errors'] = $errors;
    }
    if ($description !== null) {
        $value['description'] = $description;
    }
    return $value;
};

$positiveInteger = [
    'oneOf' => [
        ['type' => 'integer', 'minimum' => 1],
        ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
    ],
];
$flagInput = [
    'oneOf' => [
        ['type' => 'integer', 'enum' => [0, 1]],
        ['type' => 'string', 'enum' => ['0', '1']],
    ],
];
$terminalInput = [
    'oneOf' => [
        ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6]],
        ['type' => 'string', 'enum' => ['1', '2', '3', '4', '5', '6']],
    ],
];
$providerInput = [
    'oneOf' => [
        ['type' => 'integer', 'enum' => [2, 3]],
        ['type' => 'string', 'enum' => ['2', '3']],
    ],
];
$moneyInput = [
    'oneOf' => [
        ['type' => 'number', 'minimum' => 0.01, 'maximum' => 99999999.99],
        ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$'],
    ],
];
$page = [
    $query('page_no', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
    $query('page_size', ['type' => 'integer', 'minimum' => 1]),
];

return [
    'paths' => [
        '/adminapi/peanut.payment.settings.detail' => ['get' => $operation(
            'getPaymentSettings',
            '查询支付渠道配置',
            'PaymentSettingsResponse',
            statuses: [401, 403],
        )],
        '/adminapi/peanut.payment.settings.save' => ['post' => $operation(
            'savePaymentSettings',
            '保存支付渠道配置',
            'PaymentMutationResponse',
            requestSchema: 'PaymentSettingsSaveRequest',
            errors: [
                'PAYMENT_WECHAT_CONFIG_INCOMPLETE', 'PAYMENT_WECHAT_SECRET_INVALID',
                'PAYMENT_ALIPAY_CONFIG_INCOMPLETE',
            ],
            statuses: [400, 401, 403, 422],
        )],
        '/adminapi/peanut.payment.recharge-settings.detail' => ['get' => $operation(
            'getPaymentRechargeSettings',
            '查询充值配置',
            'PaymentRechargeSettingsResponse',
            statuses: [401, 403],
        )],
        '/adminapi/peanut.payment.recharge-settings.save' => ['post' => $operation(
            'savePaymentRechargeSettings',
            '保存充值配置',
            'PaymentMutationResponse',
            requestSchema: 'PaymentRechargeSettingsSaveRequest',
            errors: ['RECHARGE_CHANNEL_DISABLED'],
            statuses: [400, 401, 403, 409, 422],
        )],
        '/adminapi/peanut.payment.recharge.list' => ['get' => $operation(
            'listPaymentRecharges',
            '查询充值订单',
            'PaymentAdminRechargeListResponse',
            [
                ...$page,
                $query('sn', ['type' => 'string', 'maxLength' => 64]),
                $query('user_info', ['type' => 'string'], false),
                $query('pay_way', ['type' => 'integer', 'enum' => [1, 2, 3]]),
                $query('pay_status', ['type' => 'integer', 'enum' => [0, 1]]),
                $query('start_time', ['type' => 'string']),
                $query('end_time', ['type' => 'string']),
                $query('page_type', ['type' => 'integer', 'enum' => [0, 1]]),
                $query('page_start', ['type' => 'integer', 'minimum' => 1]),
                $query('page_end', ['type' => 'integer', 'minimum' => 1]),
                $query('export', ['type' => 'integer', 'enum' => [1, 2]]),
                $query('file_name', ['type' => 'string', 'maxLength' => 100]),
            ],
            statuses: [401, 403, 422],
        )],
        '/adminapi/peanut.payment.recharge.refund' => ['post' => $operation(
            'refundPaymentRecharge',
            '发起充值退款',
            'PaymentMutationResponse',
            [[
                'in' => 'header', 'name' => 'Idempotency-Key', 'required' => true,
                'description' => '退款幂等键；服务按租户和请求摘要登记。',
                'schema' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128, 'pattern' => '^[!-~]+$'],
            ]],
            'PaymentRechargeRefundRequest',
            [
                'RECHARGE_ORDER_NOT_FOUND', 'RECHARGE_ORDER_NOT_REFUNDABLE',
                'REFUND_AMOUNT_EXHAUSTED', 'REFUND_AMOUNT_INVALID',
                'REFUND_MEMBER_BALANCE_INSUFFICIENT', 'REFUND_REPLAY_REJECTED',
                'REFUND_IN_PROGRESS', 'PAYMENT_CHANNEL_UNSUPPORTED',
            ],
            [400, 401, 403, 404, 409, 422, 500],
            description: '渠道明确成功时退款记录进入成功；渠道明确失败时接口返回错误。若 gateway 报告 ERROR_RESULT_UNKNOWN，现实现保留 refund_status=0（退款中）并等待 refund:reconcile 收敛，但本接口仍返回成功消息；该消息不表示退款资金已到达终态。',
        )],
        '/adminapi/peanut.payment.refund.retry' => ['post' => $operation(
            'retryPaymentRefund',
            '重试失败退款',
            'PaymentMutationResponse',
            requestSchema: 'PaymentRefundRetryRequest',
            errors: [
                'REFUND_RECORD_NOT_FOUND', 'REFUND_ALREADY_SUCCEEDED', 'REFUND_IN_PROGRESS',
                'RECHARGE_ORDER_NOT_FOUND', 'REFUND_RESULT_STATE_INVALID', 'PAYMENT_CHANNEL_UNSUPPORTED',
            ],
            statuses: [400, 401, 403, 404, 409, 422, 500],
            description: '重试会先把失败记录恢复为 refund_status=0（退款中）。若 gateway 报告 ERROR_RESULT_UNKNOWN，记录继续保持退款中并等待 refund:reconcile 收敛，但本接口仍返回成功消息；该消息不表示退款资金已到达终态。',
        )],
        '/adminapi/peanut.payment.refund.stat' => ['get' => $operation(
            'getPaymentRefundStatistics',
            '查询退款统计',
            'PaymentRefundStatisticsResponse',
            statuses: [401, 403],
        )],
        '/adminapi/peanut.payment.refund.list' => ['get' => $operation(
            'listPaymentRefunds',
            '查询退款记录',
            'PaymentRefundListResponse',
            [
                ...$page,
                $query('sn', ['type' => 'string', 'maxLength' => 32]),
                $query('order_sn', ['type' => 'string', 'maxLength' => 64]),
                $query('user_info', ['type' => 'string']),
                $query('refund_type', ['type' => 'integer', 'enum' => [1]]),
                $query('refund_status', ['type' => 'integer', 'enum' => [0, 1, 2]]),
                $query('start_time', ['type' => 'string']),
                $query('end_time', ['type' => 'string']),
                $query('page_type', ['type' => 'integer', 'enum' => [0, 1]]),
                $query('export', ['type' => 'integer', 'enum' => [1, 2]]),
            ],
            errors: ['REFUND_EXPORT_UNSUPPORTED'],
            statuses: [400, 401, 403, 422],
        )],
        '/adminapi/peanut.payment.refund.log' => ['get' => $operation(
            'listPaymentRefundLogs',
            '查询退款操作日志',
            'PaymentRefundLogResponse',
            [$query('record_id', $positiveInteger, true)],
            statuses: [401, 403, 422],
        )],

        '/api/payment/notify/wechat/{binding}' => ['post' => [
            'tags' => ['Payment'], 'operationId' => 'receiveWechatPaymentCallback',
            'summary' => '接收微信支付回调',
            'description' => '先用原始请求体和 Wechatpay-* 签名头完成绑定解析、验签和解密；仅 success 事件入账。已验签的 failed/pending（含未知渠道状态）不入账，但同样返回渠道确认。',
            'parameters' => [
                ['in' => 'path', 'name' => 'binding', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$']],
                ['in' => 'header', 'name' => 'Wechatpay-Timestamp', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[0-9]+$']],
                ['in' => 'header', 'name' => 'Wechatpay-Nonce', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1]],
                ['in' => 'header', 'name' => 'Wechatpay-Serial', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1]],
                ['in' => 'header', 'name' => 'Wechatpay-Signature', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1]],
                ['in' => 'header', 'name' => 'Wechatpay-Signature-Type', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['WECHATPAY2-SHA256-RSA2048']]],
            ],
            'requestBody' => $body('PaymentWechatCallbackRequest'),
            'responses' => [
                '200' => $ok('PaymentWechatCallbackAcknowledgement', '微信渠道确认'),
                '400' => $error, '403' => $error, '404' => $error, '409' => $error, '500' => $error,
            ],
            'x-peanut-errors' => [
                'RECHARGE_ORDER_NOT_FOUND', 'PAYMENT_AMOUNT_MISMATCH', 'PAYMENT_CHANNEL_MISMATCH',
                'PAYMENT_GRANT_MISMATCH', 'PAYMENT_TRANSACTION_CONFLICT', 'PAYMENT_TRANSACTION_IN_USE',
                'PAYMENT_CURRENCY_MISMATCH', 'PAYMENT_TENANT_INVALID',
            ],
        ]],
        '/api/payment/notify/alipay/{binding}' => ['post' => [
            'tags' => ['Payment'], 'operationId' => 'receiveAlipayPaymentCallback',
            'summary' => '接收支付宝支付回调',
            'description' => '按 application/x-www-form-urlencoded 表单原值进行 RSA2 验签和绑定解析；仅 success 事件入账。已验签的 failed/pending（含未知渠道状态）不入账，但同样返回 success 文本。',
            'parameters' => [[
                'in' => 'path', 'name' => 'binding', 'required' => true,
                'schema' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            ]],
            'requestBody' => $body('PaymentAlipayCallbackRequest', 'application/x-www-form-urlencoded'),
            'responses' => [
                '200' => [
                    'description' => '支付宝渠道确认文本',
                    'content' => ['text/plain' => ['schema' => ['type' => 'string', 'enum' => ['success']]]],
                ],
                '400' => $error, '403' => $error, '404' => $error, '409' => $error, '500' => $error,
            ],
            'x-peanut-errors' => [
                'RECHARGE_ORDER_NOT_FOUND', 'PAYMENT_AMOUNT_MISMATCH', 'PAYMENT_CHANNEL_MISMATCH',
                'PAYMENT_GRANT_MISMATCH', 'PAYMENT_TRANSACTION_CONFLICT', 'PAYMENT_TRANSACTION_IN_USE',
                'PAYMENT_CURRENCY_MISMATCH', 'PAYMENT_TENANT_INVALID',
            ],
        ]],

        '/api/recharge/config' => ['get' => $operation(
            'getMemberRechargeConfig',
            '查询会员充值配置',
            'PaymentMemberRechargeConfigResponse',
            [$query('terminal', ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6]], true)],
            errors: ['MEMBER_NOT_FOUND'],
            statuses: [401, 403, 404, 422],
        )],
        '/api/recharge/create' => ['post' => $operation(
            'createMemberRecharge',
            '创建会员充值订单',
            'PaymentRechargeOrderResponse',
            requestSchema: 'PaymentMemberRechargeCreateRequest',
            errors: [
                'RECHARGE_DISABLED', 'RECHARGE_AMOUNT_BELOW_MINIMUM', 'RECHARGE_AMOUNT_ABOVE_MAXIMUM',
                'MEMBER_NOT_FOUND', 'RECHARGE_CHANNEL_UNAVAILABLE',
            ],
            statuses: [400, 401, 403, 404, 409, 422],
        )],
        '/api/recharge/prepay' => ['post' => $operation(
            'prepayMemberRecharge',
            '创建充值预支付参数',
            'PaymentRechargePrepayResponse',
            requestSchema: 'PaymentMemberRechargePrepayRequest',
            errors: [
                'RECHARGE_ORDER_NOT_FOUND', 'RECHARGE_ORDER_ALREADY_PAID', 'RECHARGE_PAY_WAY_DISABLED',
                'PAYMENT_CHANNEL_UNAVAILABLE', 'PAYMENT_WECHAT_IDENTITY_REQUIRED', 'PAYMENT_CHANNEL_UNSUPPORTED',
            ],
            statuses: [400, 401, 403, 404, 409, 422, 500],
        )],
        '/api/recharge/detail' => ['get' => $operation(
            'getMemberRecharge',
            '查询本人充值订单',
            'PaymentRechargeOrderResponse',
            [$query('order_id', $positiveInteger, true)],
            errors: ['RECHARGE_ORDER_NOT_FOUND'],
            statuses: [401, 403, 404, 422],
        )],
        '/api/recharge/lists' => ['get' => $operation(
            'listMemberRecharges',
            '查询本人充值订单列表',
            'PaymentMemberRechargeListResponse',
            [
                $query('page_no', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                $query('page_size', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 15]),
            ],
            statuses: [401, 403, 422],
        )],
    ],
    'components' => ['schemas' => [
        'PaymentMutationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]],
                'msg' => ['type' => 'string'],
                'data' => ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']],
            ],
        ],
        'PaymentSettingsData' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'wx_pay_status', 'wx_pay_appid', 'wx_pay_mch_id', 'wx_pay_secret',
                'wx_pay_cert_path', 'wx_pay_cert_key_path', 'wx_pay_platform_cert_path',
                'ali_pay_status', 'ali_pay_app_id', 'ali_pay_private_key', 'ali_pay_public_key',
                'ali_pay_seller_id', 'wx_pay_secret_configured', 'ali_pay_private_key_configured',
            ],
            'properties' => [
                'wx_pay_status' => ['type' => 'integer', 'enum' => [0, 1]],
                'wx_pay_appid' => ['type' => 'string'], 'wx_pay_mch_id' => ['type' => 'string'],
                'wx_pay_secret' => ['type' => 'string', 'enum' => ['', '******'], 'description' => '只返回空串或固定掩码，不返回已存密钥。'],
                'wx_pay_secret_configured' => ['type' => 'boolean'],
                'wx_pay_cert_path' => ['type' => 'string'], 'wx_pay_cert_key_path' => ['type' => 'string'],
                'wx_pay_platform_cert_path' => ['type' => 'string'],
                'ali_pay_status' => ['type' => 'integer', 'enum' => [0, 1]],
                'ali_pay_app_id' => ['type' => 'string'],
                'ali_pay_private_key' => ['type' => 'string', 'enum' => ['', '******'], 'description' => '只返回空串或固定掩码，不返回已存私钥。'],
                'ali_pay_private_key_configured' => ['type' => 'boolean'],
                'ali_pay_public_key' => ['type' => 'string'], 'ali_pay_seller_id' => ['type' => 'string'],
            ],
        ],
        'PaymentSettingsSaveRequest' => [
            'type' => 'object',
            'description' => '当前服务只读取列出的支付字段；legacy 校验器未拒绝的其他字段会被忽略且不会持久化。',
            'additionalProperties' => $schema('PaymentIgnoredInput'),
            'required' => ['wx_pay_status', 'ali_pay_status'],
            'properties' => [
                'wx_pay_status' => $flagInput, 'wx_pay_appid' => ['type' => 'string', 'maxLength' => 128],
                'wx_pay_mch_id' => ['type' => 'string', 'maxLength' => 64],
                'wx_pay_secret' => ['type' => 'string', 'maxLength' => 1000, 'writeOnly' => true, 'description' => '****** 表示保留原密钥；其他值覆盖。'],
                'wx_pay_cert_path' => ['type' => 'string', 'maxLength' => 500],
                'wx_pay_cert_key_path' => ['type' => 'string', 'maxLength' => 500],
                'wx_pay_platform_cert_path' => ['type' => 'string', 'maxLength' => 500],
                'ali_pay_status' => $flagInput, 'ali_pay_app_id' => ['type' => 'string', 'maxLength' => 128],
                'ali_pay_private_key' => ['type' => 'string', 'maxLength' => 10000, 'writeOnly' => true, 'description' => '****** 表示保留原私钥；其他值覆盖。'],
                'ali_pay_public_key' => ['type' => 'string', 'maxLength' => 10000],
                'ali_pay_seller_id' => ['type' => 'string', 'maxLength' => 64],
            ],
        ],
        'PaymentSettingsResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('PaymentSettingsData')],
        ],
        'PaymentRechargeScene' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['terminal', 'pay_way', 'status', 'is_default'],
            'properties' => [
                'terminal' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6]],
                'pay_way' => ['type' => 'integer', 'enum' => [2, 3]],
                'status' => ['type' => 'integer', 'enum' => [0, 1]], 'is_default' => ['type' => 'integer', 'enum' => [0, 1]],
            ],
        ],
        'PaymentRechargeSceneInput' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['terminal', 'pay_way', 'status', 'is_default'],
            'properties' => ['terminal' => $terminalInput, 'pay_way' => $providerInput, 'status' => $flagInput, 'is_default' => $flagInput],
        ],
        'PaymentRechargeSettingsData' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['status', 'min_amount', 'max_amount', 'scenes'],
            'properties' => [
                'status' => ['type' => 'integer', 'enum' => [0, 1]],
                'min_amount' => ['type' => 'string', 'pattern' => '^[0-9]+\.[0-9]{2}$'],
                'max_amount' => ['type' => 'string', 'pattern' => '^[0-9]+\.[0-9]{2}$'],
                'scenes' => ['type' => 'array', 'items' => $schema('PaymentRechargeScene')],
            ],
        ],
        'PaymentRechargeSettingsSaveRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['status', 'min_amount', 'max_amount', 'scenes'],
            'properties' => [
                'status' => $flagInput, 'min_amount' => $moneyInput, 'max_amount' => $moneyInput,
                'scenes' => [
                    'type' => 'array', 'minItems' => 11, 'maxItems' => 11,
                    'description' => '必须一次提交 PaymentScene 当前登记的 11 个终端/渠道组合，组合不可重复；启用充值时每个终端恰有一个已启用默认渠道。',
                    'items' => $schema('PaymentRechargeSceneInput'),
                ],
            ],
        ],
        'PaymentRechargeSettingsResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('PaymentRechargeSettingsData')],
        ],
        'PaymentRechargeOrder' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'sn', 'pay_way', 'pay_way_text', 'pay_status', 'pay_status_text', 'order_amount', 'order_terminal', 'terminal_text', 'transaction_id', 'pay_time', 'create_time'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1], 'sn' => ['type' => 'string', 'maxLength' => 64],
                'pay_way' => ['type' => 'integer', 'enum' => [1, 2, 3]], 'pay_way_text' => ['type' => 'string'],
                'pay_status' => ['type' => 'integer', 'enum' => [0, 1]], 'pay_status_text' => ['type' => 'string', 'enum' => ['未支付', '已支付']],
                'order_amount' => ['type' => 'string', 'pattern' => '^[0-9]+\.[0-9]{2}$'],
                'order_terminal' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6]], 'terminal_text' => ['type' => 'string'],
                'transaction_id' => ['type' => 'string'], 'pay_time' => ['type' => 'string'], 'create_time' => ['type' => 'string'],
            ],
        ],
        'PaymentAdminRechargeOrder' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'sn', 'order_amount', 'pay_way', 'pay_time', 'pay_status', 'create_time', 'refund_status', 'avatar', 'nickname', 'account', 'refunded_amount', 'refundable_amount', 'pay_way_text', 'pay_status_text'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1], 'sn' => ['type' => 'string'],
                'order_amount' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]],
                'pay_way' => ['type' => 'integer', 'enum' => [1, 2, 3]], 'pay_time' => ['type' => 'string'],
                'pay_status' => ['type' => 'integer', 'enum' => [0, 1]], 'create_time' => ['type' => 'string'],
                'refund_status' => [
                    'type' => 'integer', 'enum' => [0, 1],
                    'description' => '充值订单级标记：0 未发起退款，1 已发起退款；1 不表示渠道退款已经成功。',
                ],
                'avatar' => ['type' => 'string'],
                'nickname' => ['type' => 'string'], 'account' => ['type' => 'string'],
                'refunded_amount' => ['type' => 'string', 'pattern' => '^[0-9]+\.[0-9]{2}$'],
                'refundable_amount' => ['type' => 'string', 'pattern' => '^[0-9]+\.[0-9]{2}$'],
                'pay_way_text' => ['type' => 'string'], 'pay_status_text' => ['type' => 'string'],
            ],
        ],
        'PaymentPageData' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => $schema('PaymentAdminRechargeOrder')],
                'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1], 'extend' => ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']],
            ],
        ],
        'PaymentExportInfo' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['count', 'page_size', 'sum_page', 'max_page', 'all_max_size', 'page_start', 'page_end', 'file_name'],
            'properties' => [
                'count' => ['type' => 'integer', 'minimum' => 0], 'page_size' => ['type' => 'integer', 'minimum' => 1],
                'sum_page' => ['type' => 'integer', 'minimum' => 1], 'max_page' => ['type' => 'integer', 'minimum' => 0],
                'all_max_size' => ['type' => 'integer', 'enum' => [25000]], 'page_start' => ['type' => 'integer', 'minimum' => 1],
                'page_end' => ['type' => 'integer', 'minimum' => 1], 'file_name' => ['type' => 'string'],
            ],
        ],
        'PaymentExportFile' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['url', 'file_name'],
            'properties' => ['url' => ['type' => 'string'], 'file_name' => ['type' => 'string']],
        ],
        'PaymentAdminRechargeListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                'data' => ['oneOf' => [$schema('PaymentPageData'), $schema('PaymentExportInfo'), $schema('PaymentExportFile')]],
            ],
        ],
        'PaymentRechargeRefundRequest' => [
            'type' => 'object',
            'description' => '当前服务只消费 recharge_id/refund_amount；legacy 校验器未拒绝的其他字段会被忽略。',
            'additionalProperties' => $schema('PaymentIgnoredInput'),
            'required' => ['recharge_id'],
            'properties' => ['recharge_id' => $positiveInteger, 'refund_amount' => $moneyInput],
        ],
        'PaymentRefundRetryRequest' => [
            'type' => 'object',
            'description' => '当前控制器只消费 record_id；legacy 校验器未拒绝的其他字段会被忽略。',
            'additionalProperties' => $schema('PaymentIgnoredInput'),
            'required' => ['record_id'],
            'properties' => ['record_id' => $positiveInteger],
        ],
        'PaymentRefundStatistics' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['total', 'ing', 'success', 'error'],
            'properties' => [
                'total' => ['type' => 'number', 'minimum' => 0], 'ing' => ['type' => 'number', 'minimum' => 0],
                'success' => ['type' => 'number', 'minimum' => 0], 'error' => ['type' => 'number', 'minimum' => 0],
            ],
        ],
        'PaymentRefundStatisticsResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('PaymentRefundStatistics')],
        ],
        'PaymentRefundRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'order_type', 'order_amount', 'refund_amount', 'transaction_id', 'refund_way', 'refund_type', 'refund_status', 'create_time', 'update_time', 'sn', 'order_sn', 'user_id', 'order_id', 'nickname', 'avatar', 'refund_type_text', 'refund_status_text', 'refund_way_text'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1], 'order_type' => ['type' => 'string', 'enum' => ['order', 'recharge']],
                'order_amount' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]], 'refund_amount' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]],
                'transaction_id' => ['type' => 'string', 'nullable' => true], 'refund_way' => ['type' => 'integer', 'enum' => [1, 2]],
                'refund_type' => ['type' => 'integer', 'enum' => [1]],
                'refund_status' => [
                    'type' => 'integer', 'enum' => [0, 1, 2],
                    'description' => '0 退款中、1 退款成功、2 退款失败。gateway 结果未知时保持 0，由 refund:reconcile 后续收敛，不推定终态。',
                ],
                'create_time' => ['type' => 'string'], 'update_time' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']], 'nullable' => true],
                'sn' => ['type' => 'string'], 'order_sn' => ['type' => 'string'], 'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                'user_id' => ['type' => 'integer', 'minimum' => 1], 'order_id' => ['type' => 'integer', 'minimum' => 1],
                'nickname' => ['type' => 'string'], 'avatar' => ['type' => 'string'], 'refund_type_text' => ['type' => 'string'],
                'refund_status_text' => ['type' => 'string'], 'refund_way_text' => ['type' => 'string'],
            ],
        ],
        'PaymentRefundSummary' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['total', 'ing', 'success', 'error'],
            'properties' => [
                'total' => ['type' => 'integer', 'minimum' => 0], 'ing' => ['type' => 'integer', 'minimum' => 0],
                'success' => ['type' => 'integer', 'minimum' => 0], 'error' => ['type' => 'integer', 'minimum' => 0],
            ],
        ],
        'PaymentRefundPageData' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize', 'extend'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => $schema('PaymentRefundRecord')],
                'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1], 'extend' => $schema('PaymentRefundSummary'),
            ],
        ],
        'PaymentRefundListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('PaymentRefundPageData')],
        ],
        'PaymentRefundLog' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'sn', 'record_id', 'handle_id', 'order_amount', 'refund_amount', 'refund_status', 'create_time', 'update_time', 'user_id', 'handler', 'refund_status_text'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1], 'sn' => ['type' => 'string', 'nullable' => true],
                'record_id' => ['type' => 'integer', 'minimum' => 1], 'handle_id' => ['type' => 'integer', 'minimum' => 0],
                'order_amount' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]], 'refund_amount' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]],
                'refund_status' => [
                    'type' => 'integer', 'enum' => [0, 1, 2],
                    'description' => '0 退款中、1 退款成功、2 退款失败。本次 gateway 结果未知时日志保持 0，等待 refund:reconcile 收敛。',
                ],
                'create_time' => ['type' => 'string'],
                'update_time' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']], 'nullable' => true],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1], 'user_id' => ['type' => 'integer', 'minimum' => 1],
                'handler' => ['type' => 'string'], 'refund_status_text' => ['type' => 'string'],
            ],
        ],
        'PaymentRefundLogResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => $schema('PaymentRefundLog')],
            ],
        ],
        'PaymentWechatCallbackRequest' => [
            'type' => 'object',
            'description' => '完整原始 JSON 会参与微信签名校验；解析器读取列出的标准字段，并保留对渠道新增签名字段的接收能力。',
            'additionalProperties' => $schema('PaymentIgnoredInput'),
            'required' => ['resource'],
            'properties' => [
                'id' => ['type' => 'string'], 'create_time' => ['type' => 'string'], 'event_type' => ['type' => 'string'],
                'resource_type' => ['type' => 'string'], 'summary' => ['type' => 'string'],
                'resource' => [
                    'type' => 'object', 'additionalProperties' => $schema('PaymentIgnoredInput'),
                    'required' => ['algorithm', 'ciphertext', 'nonce'],
                    'properties' => [
                        'algorithm' => ['type' => 'string', 'enum' => ['AEAD_AES_256_GCM']],
                        'ciphertext' => ['type' => 'string', 'minLength' => 1],
                        'nonce' => ['type' => 'string', 'minLength' => 1], 'associated_data' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        'PaymentWechatCallbackAcknowledgement' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'message'],
            'properties' => ['code' => ['type' => 'string', 'enum' => ['SUCCESS']], 'message' => ['type' => 'string', 'enum' => ['成功']]],
        ],
        'PaymentAlipayCallbackRequest' => [
            'type' => 'object',
            'description' => '支付宝异步通知表单；列出的字段由当前解析器消费，其他渠道扩展字段仍作为字符串参与 RSA2 验签。',
            'additionalProperties' => ['type' => 'string'],
            'required' => ['sign', 'sign_type', 'app_id', 'seller_id', 'out_trade_no', 'trade_no', 'total_amount', 'trade_status'],
            'properties' => [
                'sign' => ['type' => 'string', 'minLength' => 1], 'sign_type' => ['type' => 'string', 'enum' => ['RSA2']],
                'app_id' => ['type' => 'string', 'minLength' => 1], 'seller_id' => ['type' => 'string', 'minLength' => 1],
                'out_trade_no' => ['type' => 'string', 'minLength' => 1], 'trade_no' => ['type' => 'string', 'minLength' => 1],
                'total_amount' => ['type' => 'string', 'pattern' => '^[0-9]+(?:\.[0-9]{1,2})?$'],
                'trade_status' => ['type' => 'string'], 'notify_time' => ['type' => 'string'], 'notify_type' => ['type' => 'string'],
                'notify_id' => ['type' => 'string'], 'buyer_id' => ['type' => 'string'], 'buyer_logon_id' => ['type' => 'string'],
                'receipt_amount' => ['type' => 'string'], 'invoice_amount' => ['type' => 'string'], 'buyer_pay_amount' => ['type' => 'string'],
                'point_amount' => ['type' => 'string'], 'gmt_create' => ['type' => 'string'], 'gmt_payment' => ['type' => 'string'],
                'subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'charset' => ['type' => 'string'], 'version' => ['type' => 'string'],
            ],
        ],
        'PaymentMemberRechargeConfigData' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['status', 'min_amount', 'balance', 'terminal', 'channels'],
            'properties' => [
                'status' => ['type' => 'integer', 'enum' => [0, 1]], 'min_amount' => ['type' => 'string', 'pattern' => '^[0-9]+\.[0-9]{2}$'],
                'balance' => ['type' => 'string', 'pattern' => '^[0-9]+\.[0-9]{2}$'], 'terminal' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6]],
                'channels' => ['type' => 'array', 'items' => $schema('PaymentMemberRechargeChannel')],
            ],
        ],
        'PaymentMemberRechargeChannel' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['pay_way', 'name', 'is_default'],
            'properties' => ['pay_way' => ['type' => 'integer', 'enum' => [2, 3]], 'name' => ['type' => 'string'], 'is_default' => ['type' => 'integer', 'enum' => [0, 1]]],
        ],
        'PaymentMemberRechargeConfigResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('PaymentMemberRechargeConfigData')],
        ],
        'PaymentMemberRechargeCreateRequest' => [
            'type' => 'object',
            'description' => '当前服务只消费 amount/terminal；legacy 校验器未拒绝的其他字段会被忽略。',
            'additionalProperties' => $schema('PaymentIgnoredInput'),
            'required' => ['amount', 'terminal'],
            'properties' => [
                'amount' => ['oneOf' => [['type' => 'number', 'minimum' => 0], ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$']]],
                'terminal' => $terminalInput,
            ],
        ],
        'PaymentMemberRechargePrepayRequest' => [
            'type' => 'object',
            'description' => '当前控制器只消费 order_id/pay_way；legacy 校验器未拒绝的其他字段会被忽略。',
            'additionalProperties' => $schema('PaymentIgnoredInput'),
            'required' => ['order_id', 'pay_way'],
            'properties' => ['order_id' => $positiveInteger, 'pay_way' => $providerInput],
        ],
        'PaymentRechargeOrderResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('PaymentRechargeOrder')],
        ],
        'PaymentWechatJsapiPayload' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['appId', 'timeStamp', 'nonceStr', 'package', 'signType', 'paySign'],
            'properties' => ['appId' => ['type' => 'string'], 'timeStamp' => ['type' => 'string'], 'nonceStr' => ['type' => 'string'], 'package' => ['type' => 'string'], 'signType' => ['type' => 'string', 'enum' => ['RSA']], 'paySign' => ['type' => 'string']],
        ],
        'PaymentWechatAppPayload' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['appid', 'partnerid', 'prepayid', 'package', 'noncestr', 'timestamp', 'sign'],
            'properties' => ['appid' => ['type' => 'string'], 'partnerid' => ['type' => 'string'], 'prepayid' => ['type' => 'string'], 'package' => ['type' => 'string', 'enum' => ['Sign=WXPay']], 'noncestr' => ['type' => 'string'], 'timestamp' => ['type' => 'string'], 'sign' => ['type' => 'string']],
        ],
        'PaymentWechatNativePayload' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code_url'],
            'properties' => ['code_url' => ['type' => 'string']],
        ],
        'PaymentWechatH5Payload' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['h5_url'],
            'properties' => ['h5_url' => ['type' => 'string']],
        ],
        'PaymentAlipayAppPayload' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['order_string'],
            'properties' => ['order_string' => ['type' => 'string', 'description' => '支付宝 SDK 所需完整签名订单字符串。']],
        ],
        'PaymentAlipayRedirectPayload' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['gateway_url'],
            'properties' => ['gateway_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'WAP/PAGE 场景由客户端跳转的支付宝网关 URL。']],
        ],
        'PaymentPrepayPayment' => [
            'oneOf' => [
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['channel', 'scene', 'payload'], 'properties' => ['channel' => ['type' => 'string', 'enum' => ['wechat']], 'scene' => ['type' => 'string', 'enum' => ['JSAPI']], 'payload' => $schema('PaymentWechatJsapiPayload')]],
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['channel', 'scene', 'payload'], 'properties' => ['channel' => ['type' => 'string', 'enum' => ['wechat']], 'scene' => ['type' => 'string', 'enum' => ['MWEB']], 'payload' => $schema('PaymentWechatH5Payload')]],
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['channel', 'scene', 'payload'], 'properties' => ['channel' => ['type' => 'string', 'enum' => ['wechat']], 'scene' => ['type' => 'string', 'enum' => ['NATIVE']], 'payload' => $schema('PaymentWechatNativePayload')]],
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['channel', 'scene', 'payload'], 'properties' => ['channel' => ['type' => 'string', 'enum' => ['wechat']], 'scene' => ['type' => 'string', 'enum' => ['APP']], 'payload' => $schema('PaymentWechatAppPayload')]],
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['channel', 'scene', 'payload'], 'properties' => ['channel' => ['type' => 'string', 'enum' => ['alipay']], 'scene' => ['type' => 'string', 'enum' => ['WAP', 'PAGE']], 'payload' => $schema('PaymentAlipayRedirectPayload')]],
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['channel', 'scene', 'payload'], 'properties' => ['channel' => ['type' => 'string', 'enum' => ['alipay']], 'scene' => ['type' => 'string', 'enum' => ['APP']], 'payload' => $schema('PaymentAlipayAppPayload')]],
            ],
        ],
        'PaymentRechargePrepayData' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['order', 'payment'],
            'properties' => ['order' => $schema('PaymentRechargeOrder'), 'payment' => $schema('PaymentPrepayPayment')],
        ],
        'PaymentRechargePrepayResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('PaymentRechargePrepayData')],
        ],
        'PaymentMemberRechargePageData' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => $schema('PaymentRechargeOrder')],
                'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1], 'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ],
        'PaymentMemberRechargeListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('PaymentMemberRechargePageData')],
        ],
        'PaymentIgnoredInput' => [
            'description' => '运行时可接收但 Payment 业务不会读取或持久化的扩展输入值。',
            'nullable' => true,
            'anyOf' => [
                ['type' => 'string'], ['type' => 'number'], ['type' => 'boolean'],
                ['type' => 'array', 'items' => $schema('PaymentIgnoredInput')],
                ['type' => 'object', 'additionalProperties' => $schema('PaymentIgnoredInput')],
            ],
        ],
    ]],
];
