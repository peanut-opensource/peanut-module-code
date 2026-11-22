<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Model;

use app\common\model\TenantOwnedModel;

/**
 * 通知发送记录模型
 *
 * @property string $error
 * @property string|null $extra
 * @property string $provider
 * @property int|null $reservation_active
 * @property string|null $reservation_key
 * @property int $status
 * @property int $check_count
 * @property int $is_verified
 * @property string|null $request_digest
 * @property int|null $reservation_until
 * @property int $send_time
 * @property int $verified_time
 * @property string $verify_code_hash
 */
class NoticeLog extends TenantOwnedModel
{
    protected $name = 'notice_log';
    protected $updateTime = false;
    protected $hidden = ['verify_code_hash', 'extra'];

    /** 状态：待发送 */
    public const STATUS_PENDING = 0;
    /** 状态：发送成功 */
    public const STATUS_SUCCESS = 1;
    /** 状态：发送失败 */
    public const STATUS_FAIL = 2;
    /** 状态：Provider 结果未知，不得立即重试 */
    public const STATUS_UNKNOWN = 3;

    /** 渠道：短信 */
    public const CHANNEL_SMS = 1;
    /** 渠道：邮件 */
    public const CHANNEL_EMAIL = 2;
    /** 渠道：推送 */
    public const CHANNEL_PUSH = 3;

    /** 验证状态：未验证 */
    public const VERIFIED_NO = 0;
    /** 验证状态：已验证 */
    public const VERIFIED_YES = 1;

    /** 短信服务商：阿里云 */
    public const PROVIDER_ALIYUN = 'aliyun';
    /** 短信服务商：腾讯云 */
    public const PROVIDER_TENCENT = 'tencent';
}
