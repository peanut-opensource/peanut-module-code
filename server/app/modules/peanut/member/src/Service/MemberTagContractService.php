<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Service;

use PeanutAdmin\Modules\Member\Model\MemberTag;
use PeanutAdmin\Modules\Member\Model\MemberTagRelation;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Member\Contract\MemberTagCommands;
use PeanutAdmin\Kernel\Auth\TenantContext;

final class MemberTagContractService implements MemberTagCommands
{
    public function create(TenantContext $context, string $name, string $remark): void
    {
        if (MemberTag::where([])->where('name', $name)->count() > 0) {
            throw BusinessException::conflict('MEMBER_TAG_NAME_EXISTS', '标签名称已存在');
        }
        MemberTag::create(['name' => $name, 'remark' => $remark]);
    }

    public function update(TenantContext $context, int $tagId, string $name, ?string $remark): void
    {
        if (MemberTag::where([])->where('name', $name)->where('id', '<>', $tagId)->count() > 0) {
            throw BusinessException::conflict('MEMBER_TAG_NAME_EXISTS', '标签名称已存在');
        }
        $tag = MemberTag::where([])->where('id', $tagId)->findOrEmpty();
        if ($tag->isEmpty()) {
            throw BusinessException::notFound('MEMBER_TAG_NOT_FOUND', '标签不存在');
        }
        $data = ['name' => $name];
        if ($remark !== null) {
            $data['remark'] = $remark;
        }
        $tag->save($data);
    }

    public function delete(TenantContext $context, int $tagId): void
    {
        $tag = MemberTag::where([])->where('id', $tagId)->findOrEmpty();
        if ($tag->isEmpty()) {
            throw BusinessException::notFound('MEMBER_TAG_NOT_FOUND', '标签不存在');
        }
        MemberTagRelation::where([])->where('tag_id', $tagId)->delete();
        $tag->delete();
    }
}
