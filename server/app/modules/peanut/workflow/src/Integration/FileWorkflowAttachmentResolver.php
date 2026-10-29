<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Integration;

use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\File\Contract\FileAccess;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachment;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachmentResolver;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

/** File owns current authorization and source metadata; Workflow persists the historical label. */
final readonly class FileWorkflowAttachmentResolver implements WorkflowAttachmentResolver
{
    public function __construct(private FileAccess $files) {}

    public function snapshot(AuthorizedOperationContext $context, string $fileKey): WorkflowAttachment
    {
        try {
            $snapshot = $this->files->attachmentSnapshot($context->tenantContext, $fileKey);
        } catch (BusinessException) {
            throw WorkflowException::attachmentUnavailable();
        }
        return new WorkflowAttachment($snapshot['file_key'], $snapshot['original_name'], $snapshot['verified_mime_type'], $snapshot['size_bytes'], $snapshot['sha256']);
    }
}
