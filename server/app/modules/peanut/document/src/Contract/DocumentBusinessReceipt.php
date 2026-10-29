<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Exact business result data; the provider must still prove the staged/committed original operation. */
final readonly class DocumentBusinessReceipt
{
    public function __construct(public BusinessBarrierPlan $plan, public array $data)
    {
        $required = ['document_id','content_kind','schema_version','status','state_seq','auth_revision','operation_id','phase','lease_epoch','binary_sha256','store_id','target_progress'];
        $version = ['revision_id','revision_no','source_state_seq','digest'];
        if (array_diff($required, array_keys($data)) !== [] || array_diff(array_keys($data), [...$required, ...$version]) !== []
            || $data['document_id'] !== $plan->data['binding']['document_id'] || $data['operation_id'] !== $plan->data['operation_id']
            || $data['phase'] !== 'completed' || $data['content_kind'] !== 'rich_text' || !is_string($data['schema_version']) || $data['schema_version'] === ''
            || $data['lease_epoch'] !== $plan->data['lease_epoch'] + 1 || !is_int($data['target_progress']) || $data['target_progress'] < 0
            || $data['store_id'] !== null && (!is_string($data['store_id']) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $data['store_id']) !== 1)
            || !is_int($data['state_seq']) || $data['state_seq'] < $plan->data['base_state_seq']
            || $data['auth_revision'] !== $plan->data['base_auth_revision'] + 1
            || $data['status'] !== ($plan->data['action'] === 'archive' ? 'archived' : 'active')
            || !is_string($data['binary_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $data['binary_sha256']) !== 1) {
            throw new \InvalidArgumentException('DOCUMENT_BUSINESS_RECEIPT_INVALID');
        }
        if (str_starts_with($plan->data['action'], 'version.')) {
            if (array_diff($version, array_keys($data)) !== [] || !is_int($data['revision_id']) || $data['revision_id'] < 1
                || !is_int($data['revision_no']) || $data['revision_no'] < 1 || $data['source_state_seq'] !== $data['state_seq']
                || !is_string($data['digest']) || preg_match('/^[a-f0-9]{64}$/D', $data['digest']) !== 1) {
                throw new \InvalidArgumentException('DOCUMENT_BUSINESS_RECEIPT_INVALID');
            }
        } elseif (array_intersect($version, array_keys($data)) !== []) {
            throw new \InvalidArgumentException('DOCUMENT_BUSINESS_RECEIPT_INVALID');
        }
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(\PeanutAdmin\Modules\RichText\Contract\RichTextProjection::canonical($this->data), JSON_THROW_ON_ERROR));
    }
}
