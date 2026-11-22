<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property string $created_at
 * @property int|string $created_by_member_id
 * @property int|string $document_id
 * @property int $end_offset
 * @property int|string $id
 * @property string $owner_sha256
 * @property string $quote_text
 * @property int|string $revision_id
 * @property int $start_offset
 * @property string $status
 * @property string $text_profile
 * @property string $text_sha256
 * @property int|string $thread_revision
 * @property string $updated_at
 * @property int|string $updated_by_member_id
 * @property string $version_digest
 * @property string|null $accept_request_id
 * @property string|null $candidate_sha256
 * @property string|null $decided_at
 * @property int|string|null $decided_by_member_id
 * @property string|null $decision
 * @property int|string|null $result_revision_id
 * @property int|string|null $result_state_seq
 */
final class DocumentCommentThread extends TenantOwnedModel
{
    protected $name = 'document_comment_thread';
    protected $autoWriteTimestamp = false;
}
