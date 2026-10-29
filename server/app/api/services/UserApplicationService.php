<?php

declare(strict_types=1);

namespace app\api\services;

use PeanutAdmin\Modules\Notification\Contract\VerificationCodeCommands;
use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use PeanutAdmin\Modules\Member\Contract\MemberIdentityCommands;
use PeanutAdmin\Modules\Member\Contract\MemberProfileCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use app\common\validate\MemberProfileSelfFieldValidate;
use app\common\exception\BusinessException;
use app\common\enum\notice\NoticeSceneEnum;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Kernel\Module\ModuleException;

class UserApplicationService
{
    public function __construct(
        private readonly MemberQueries $members,
        private readonly MemberIdentityCommands $memberIdentities,
        private readonly MemberProfileCommands $memberProfiles,
        private readonly FileReferences $files,
        private readonly ?VerificationCodeCommands $verificationCodes = null,
        private readonly ?PublicArticleQueries $articleCollections = null,
    ) {}

    /** 用户中心（首屏数据） */
    public function center(AuthenticatedMemberContext $context, int $memberId): array
    {
        $data = $this->members->memberFields(
            $context,
            $memberId,
            ['id', 'sn', 'nickname', 'avatar', 'mobile', 'user_money', 'points', 'create_time'],
        );
        if ($data === []) {
            return [];
        }
        $data['balance']    = $data['user_money'];
        unset($data['user_money']);
        $data['avatar']     = $this->files->getFileUrl((string) $data['avatar']);
        if ($this->articleCollections === null) {
            $data['collect_num'] = 0;
        } else {
            try {
                $data['collect_num'] = $this->articleCollections->countForMember($context, $memberId);
            } catch (ModuleException) {
                $data['collect_num'] = 0;
            }
        }

        return $data;
    }

    /** 个人信息 */
    public function info(AuthenticatedMemberContext $context, int $memberId): array
    {
        $data = $this->members->memberFields(
            $context,
            $memberId,
            ['id', 'sn', 'account', 'nickname', 'avatar', 'sex', 'birthday', 'mobile', 'email', 'user_money', 'points', 'create_time', 'password'],
        );
        if ($data === []) {
            return [];
        }
        $data['balance'] = $data['user_money'];
        unset($data['user_money']);
        $data['avatar'] = $this->files->getFileUrl((string) $data['avatar']);
        $data['has_password'] = $data['password'] !== '' && $data['password'] !== null;
        unset($data['password']);

        return $data;
    }

    /**
     * 更新用户信息
     * params: field(nickname|avatar|sex|birthday|email), value
     */
    public function setInfo(AuthenticatedMemberContext $context, int $memberId, array $params): bool
    {
        $field = $params['field'] ?? '';
        // 在调用文件服务前校验原始值，避免数组被字符串化后绕过头像类型合同。
        $value = MemberProfileSelfFieldValidate::normalize($field, $params['value'] ?? null, false);
        if ($field === 'avatar') {
            $value = $this->files->setTenantFileUrl($context, $value);
        }

        $this->memberProfiles->updateSelfField($context, $memberId, $field, $value);
        return true;
    }

    /** 修改密码（需要旧密码） */
    public function changePassword(AuthenticatedMemberContext $context, int $memberId, array $params): bool
    {
        $this->memberIdentities->changePassword(
            $context,
            $memberId,
            (string) $params['old_password'],
            (string) $params['password'],
        );
        return true;
    }

    /** 绑定手机号 */
    public function bindMobile(AuthenticatedMemberContext $context, int $memberId, array $params): bool
    {
        $mobile = $params['mobile'] ?? '';
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            throw BusinessException::invalid('MEMBER_MOBILE_INVALID', '手机号格式错误');
        }
        $member = $this->members->memberFields(
            $context,
            $memberId,
            ['id', 'mobile'],
        );
        if ($member === []) {
            throw BusinessException::notFound('MEMBER_NOT_FOUND', '用户不存在');
        }
        $this->memberIdentities->assertMobileAvailable($context, $memberId, $mobile);
        $scene = empty($member['mobile'])
            ? NoticeSceneEnum::BIND_MOBILE
            : NoticeSceneEnum::CHANGE_MOBILE;
        $result = ($this->verificationCodes ?? throw new \DomainException('NOTIFICATION_MODULE_UNAVAILABLE'))->verifyCode(
            $context,
            $scene,
            $mobile,
            (string) ($params['code'] ?? ''),
        );
        if (!$result->accepted) {
            throw BusinessException::invalid('MEMBER_VERIFICATION_REJECTED', $result->error);
        }
        $this->memberIdentities->bindVerifiedMobile($context, $memberId, $mobile);
        return true;
    }
}
