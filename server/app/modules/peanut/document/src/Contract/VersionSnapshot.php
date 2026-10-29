<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** A committed immutable document version. Confirmed does not mean business approval or publication. */
final readonly class VersionSnapshot
{
    public function __construct(
        public int $tenantId,
        public int $documentId,
        public int $revisionId,
        public int $revisionNo,
        public int $sourceStateSeq,
        public string $contentKind,
        public string $digest,
        public ?array $structuredContent,
        public ?string $fileKey,
        public ?string $fileSha256,
        public ?string $verifiedMimeType,
        public array $references,
    ) {}
    public function toArray(): array
    {
        return ['tenant_id' => $this->tenantId,'document_id' => $this->documentId,'revision_id' => $this->revisionId,'revision_no' => $this->revisionNo,
            'source_state_seq' => $this->sourceStateSeq,'content_kind' => $this->contentKind,'digest' => $this->digest,'confirmed' => true,
            'structured_content' => $this->structuredContent,'file_key' => $this->fileKey,'file_sha256' => $this->fileSha256,
            'verified_mime_type' => $this->verifiedMimeType,'references' => $this->references,
            'file_keys' => array_values(array_unique(array_column($this->references, 'file_key')))];
    }
    /** Fixed business binding; does not authorize publication or turn draft ACK into Save. */
    public function binding(): array
    {
        return ['document_id' => $this->documentId,'revision_id' => $this->revisionId,'source_state_seq' => $this->sourceStateSeq,
            'snapshot_sha256' => hash('sha256', json_encode(\PeanutAdmin\Modules\RichText\Contract\RichTextProjection::canonical($this->toArray()), JSON_THROW_ON_ERROR))];
    }
}
