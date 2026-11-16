<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Document\Contract\AcceptedStoreInput;
use PeanutAdmin\Modules\Document\Contract\BusinessBarrierPlan;
use PeanutAdmin\Modules\Document\Contract\DocumentBusinessReceipt;
use PeanutAdmin\Modules\Document\Contract\SuggestionAppliedCapture;
use PeanutAdmin\Modules\Document\Contract\DrainedBusinessBarrier;
use PeanutAdmin\Modules\Collaboration\Service\NativeCollaborationProvider;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use PHPUnit\Framework\TestCase;

$server = dirname(__DIR__, 5);
require_once $server . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/rich_text/src/Contract/RichTextProjection.php';
foreach (['DocumentOwnerReference','BusinessBarrierPlan','AcceptedStoreInput','SuggestionAppliedCapture','DocumentBusinessReceipt','DrainedBusinessBarrier'] as $class) {
    require_once dirname(__DIR__, 2) . '/document/src/Contract/' . $class . '.php';
}
require_once dirname(__DIR__) . '/src/Service/NativeCollaborationProvider.php';

final class SuggestionCaptureContractTest extends TestCase
{
    private function projection(string $text): RichTextProjection
    {
        return RichTextProjection::fromStructured(['schemaVersion' => RichTextProjection::SCHEMA,'editorModel' => 'tiptap-prosemirror',
            'content' => ['type' => 'doc','content' => [['type' => 'paragraph','content' => [['type' => 'text','text' => $text]]]]],'annotations' => []]);
    }

    private function plan(): BusinessBarrierPlan
    {
        return new BusinessBarrierPlan(['protocol' => 2,'binding' => ['instance_id' => 'test','edition' => 'multi-tenant','tenant_id' => 1,
            'document_id' => 3,'owner' => ['module_key' => 'custom.content','object_type' => 'story','object_id' => '7','slot' => 'body']],
            'operation_id' => 'suggestion:1','action' => 'suggestion.accept','input_sha256' => hash('sha256', 'input'),
            'barrier_key' => hash('sha256', 'barrier'),'room_key' => hash('sha256', 'room'),'service_instance_id' => 'test-service',
            'control_mode' => 'native','holder_key' => '12345678-1234-4123-8123-123456789abc','lease_epoch' => 2,
            'base_state_seq' => 9,'base_auth_revision' => 4,'owner_revision' => hash('sha256', 'owner'),'deadline' => 9999999999999]);
    }

    private function capture(): array
    {
        $plan = $this->plan();
        $projection = $this->projection('建议😀');
        return array_intersect_key($plan->data, array_flip(['protocol','operation_id','input_sha256','room_key','holder_key','lease_epoch'])) + [
            'phase' => 'suggestion_applied','base_state_seq' => 9,'actor_member_id' => 4,
            'baseline_binary_sha256' => hash('sha256', 'baseline'),'baseline_projection_sha256' => hash('sha256', $this->projection('原文')->json),
            'binary_base64' => base64_encode('signed native capture bytes'),'binary_sha256' => hash('sha256', 'signed native capture bytes'),
            'snapshot_base64' => base64_encode('snapshot'),'structured_content' => json_decode($projection->json, true),
            'projection_sha256' => hash('sha256', $projection->json),'reference_digest' => AcceptedStoreInput::referencesDigest([]),
        ];
    }

    public function testCaptureBindsPlanBaselineBinaryProjectionAndSnapshot(): void
    {
        $capture = new SuggestionAppliedCapture($this->plan(), $this->capture());
        self::assertSame(9, $capture->stateSeq);
        self::assertSame('建议😀', $capture->projection->plainText());
        self::assertSame('signed native capture bytes', $capture->binary);
        self::assertSame(hash('sha256', $capture->binary), $capture->binarySha256);
        foreach (['operation_id','baseline_binary_sha256','binary_sha256','projection_sha256','reference_digest','snapshot_base64'] as $key) {
            $invalid = $this->capture();
            $invalid[$key] = 'invalid';
            try {
                new SuggestionAppliedCapture($this->plan(), $invalid);
                self::fail('Accepted changed capture field ' . $key);
            } catch (InvalidArgumentException $error) {
                self::assertSame('DOCUMENT_SUGGESTION_CAPTURE_INVALID', $error->getMessage());
            }
        }
    }

    public function testReceiptDistinguishesAppliedRevisionFromConfirmedUnappliedCancellation(): void
    {
        $plan = $this->plan();
        $base = ['document_id' => 3,'content_kind' => 'rich_text','schema_version' => RichTextProjection::SCHEMA,'status' => 'active',
            'state_seq' => 9,'auth_revision' => 5,'operation_id' => 'suggestion:1','phase' => 'completed','lease_epoch' => 3,
            'binary_sha256' => hash('sha256', 'baseline'),'store_id' => null,'target_progress' => 0];
        self::assertSame(true, (new DocumentBusinessReceipt($plan, $base + ['cancelled' => true,'conflict_code' => 'DOCUMENT_SUGGESTION_BASELINE_CONFLICT']))->data['cancelled']);
        $success = array_replace($base, ['state_seq' => 10]) + ['revision_id' => 12,'revision_no' => 2,'source_state_seq' => 10,'digest' => hash('sha256', 'revision')];
        self::assertSame(12, (new DocumentBusinessReceipt($plan, $success))->data['revision_id']);
        $this->expectException(InvalidArgumentException::class);
        new DocumentBusinessReceipt($plan, $base);
    }

    public function testAcceptedChangesMustChainToExactFinalProjectionAndText(): void
    {
        $plan = $this->plan();
        $before = $this->projection('原文');
        $after = $this->projection('修改😀');
        $source = ['connection_key' => hash('sha256', 'connection'),'authorization_revision' => hash('sha256', 'grant'),'authorized_at_ms' => 123456];
        $change = $source + ['progress' => 4,'update_sha256' => hash('sha256', 'update'),
            'before_projection_sha256' => hash('sha256', $before->json),'after_projection_sha256' => hash('sha256', $after->json),
            'before_text' => $before->plainText(),'after_text' => $after->plainText()];
        $tuple = $plan->data['binding'] + ['protocol' => 2,'room_key' => $plan->data['room_key'],'service_instance_id' => 'test-service',
            'lease_epoch' => 2,'base_state_seq' => 9,'store_id' => 'store:1','target_progress' => 4,
            'baseline_sha256' => hash('sha256', 'baseline'),'binary_sha256' => hash('sha256', 'native update'),
            'projector_version' => 'peanut.richtext-yjs/1','projection_sha256' => hash('sha256', $after->json),
            'reference_digest' => AcceptedStoreInput::referencesDigest([]),'participants' => [$source],'changes' => [$change],
            'binary_base64' => base64_encode('native update'),'structured_content' => json_decode($after->json, true)];
        self::assertSame('修改😀', (new AcceptedStoreInput($tuple))->projection->plainText());
        $tuple['changes'][0]['after_text'] = 'spoofed author text';
        $this->expectException(InvalidArgumentException::class);
        new AcceptedStoreInput($tuple);
    }

    public function testSentSuggestionRequiresItsExactSignedRejectionBeforeCancellation(): void
    {
        $plan = $this->plan();
        $binaryHash = hash('sha256', 'baseline');
        $proof = new DrainedBusinessBarrier($plan, 9, $binaryHash, null, 0);
        $result = ['document_id' => 3,'content_kind' => 'rich_text','schema_version' => RichTextProjection::SCHEMA,'status' => 'active',
            'state_seq' => 9,'auth_revision' => 5,'operation_id' => 'suggestion:1','phase' => 'completed','lease_epoch' => 3,
            'binary_sha256' => $binaryHash,'store_id' => null,'target_progress' => 0,'cancelled' => true,'conflict_code' => 'SUGGESTION_CONTENT_INVALID'];
        $receipt = new DocumentBusinessReceipt($plan, $result);
        // Exercise the production consume transition after it has loaded the original
        // durable barrier. No database, framework boot, service or model imitation.
        $assertResult = new ReflectionMethod(NativeCollaborationProvider::class, 'assertSuggestionResult');
        $command = hash('sha256', 'original signed command');
        $assertResult->invoke(null, $proof, $receipt, []); // Never sent: the PHP preflight may cancel.
        $assertResult->invoke(null, $proof, $receipt, ['suggestion_command_sha256' => $command,
            'suggestion_rejected' => ['command_sha256' => $command,'code' => 'SUGGESTION_CONTENT_INVALID']]);
        foreach ([
            ['suggestion_command_sha256' => $command], // UNKNOWN has no authenticated negative ACK.
            ['suggestion_command_sha256' => $command,'suggestion_rejected' => ['command_sha256' => hash('sha256', 'other'),'code' => 'SUGGESTION_CONTENT_INVALID']],
            ['suggestion_command_sha256' => $command,'suggestion_rejected' => ['command_sha256' => $command,'code' => 'SUGGESTION_BASELINE_INVALID']],
            ['suggestion_command_sha256' => $command,'suggestion_capture' => $this->capture(),
                'suggestion_rejected' => ['command_sha256' => $command,'code' => 'SUGGESTION_CONTENT_INVALID']],
        ] as $drained) {
            try {
                $assertResult->invoke(null, $proof, $receipt, $drained);
                self::fail('A sent, applied or unconfirmed command was canceled without its exact rejection');
            } catch (BusinessException $error) {
                self::assertSame('DOCUMENT_SUGGESTION_APPLICATION_UNCONFIRMED', $error->errorCode);
            }
        }
    }
}
