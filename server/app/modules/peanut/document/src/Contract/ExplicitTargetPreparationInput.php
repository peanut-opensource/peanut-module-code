<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;

/** Click-time immutable content, before waiting for a committed covering capture. */
final readonly class ExplicitTargetPreparationInput
{
    public string $binary;
    public RichTextProjection $projection;

    public function __construct(public array $tuple)
    {
        $fields = ['protocol','connection_key','request_id','target_binary_sha256','target_projection_sha256',
            'binary_base64','structured_content','projector_version','reference_digest','target_progress'];
        if (count($tuple) !== count($fields) || array_diff(array_keys($tuple), $fields) !== [] || $tuple['protocol'] !== 2
            || !is_int($tuple['target_progress']) || $tuple['target_progress'] < 0
            || !is_string($tuple['request_id']) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $tuple['request_id']) !== 1
            || !is_string($tuple['projector_version']) || preg_match('/^[A-Za-z0-9_.:\/-]{1,64}$/D', $tuple['projector_version']) !== 1
            || !is_array($tuple['structured_content'])) {
            throw new \InvalidArgumentException('DOCUMENT_TARGET_PREPARATION_INVALID');
        }
        foreach (['connection_key','target_binary_sha256','target_projection_sha256','reference_digest'] as $key) {
            if (!is_string($tuple[$key]) || preg_match('/^[0-9a-f]{64}$/D', $tuple[$key]) !== 1) {
                throw new \InvalidArgumentException('DOCUMENT_TARGET_PREPARATION_INVALID');
            }
        }
        $binary = is_string($tuple['binary_base64']) && strlen($tuple['binary_base64']) <= 5592408
            ? base64_decode($tuple['binary_base64'], true) : false;
        if (!is_string($binary) || $binary === '' || strlen($binary) > 4194304 || base64_encode($binary) !== $tuple['binary_base64']) {
            throw new \InvalidArgumentException('DOCUMENT_TARGET_PREPARATION_INVALID');
        }
        $this->binary = $binary;
        $this->projection = RichTextProjection::fromStructured($tuple['structured_content']);
        if (!hash_equals(hash('sha256', $binary), $tuple['target_binary_sha256'])
            || !hash_equals(hash('sha256', $this->projection->json), $tuple['target_projection_sha256'])
            || !hash_equals(AcceptedStoreInput::referencesDigest($this->projection->fileReferences), $tuple['reference_digest'])) {
            throw new \InvalidArgumentException('DOCUMENT_TARGET_PREPARATION_INVALID');
        }
    }

    public function hash(): string
    {
        $business = $this->tuple;
        unset($business['connection_key']);
        return hash('sha256', json_encode(RichTextProjection::canonical($business), JSON_THROW_ON_ERROR));
    }

    public function operationKey(): string
    {
        return 'target:' . hash('sha256', $this->tuple['request_id']);
    }
}
