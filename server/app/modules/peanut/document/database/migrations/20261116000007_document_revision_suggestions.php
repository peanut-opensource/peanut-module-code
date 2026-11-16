<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Additive retained proposals, decisions and observed submission changes; no history is invented. */
final class DocumentRevisionSuggestions extends Migrator
{
    public function up(): void
    {
        $thread = $this->table('document_comment_thread');
        if (!$thread->hasColumn('candidate_json')) {
            $thread->addColumn('candidate_json', 'json', ['null' => true])
                ->addColumn('candidate_sha256', 'char', ['limit' => 64, 'null' => true])
                ->addColumn('decision', 'string', ['limit' => 16, 'null' => true])
                ->addColumn('decided_by_member_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('decided_at', 'datetime', ['precision' => 3, 'null' => true])
                ->addColumn('result_revision_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('result_state_seq', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('accept_request_id', 'string', ['limit' => 128, 'null' => true])->update();
            $this->execute("ALTER TABLE pa_document_comment_thread ADD CONSTRAINT chk_document_suggestion_decision CHECK (decision IS NULL OR decision IN ('pending','accepted','rejected'))");
        }
        $this->execute("ALTER TABLE pa_document_comment_message DROP CHECK chk_document_comment_message_action, ADD CONSTRAINT chk_document_comment_message_action CHECK ((action IN ('create','reply','suggest') AND body IS NOT NULL) OR (action IN ('resolve','reopen','accept','reject') AND body IS NULL))");
        $this->execute("ALTER TABLE pa_document_comment_thread DROP CHECK chk_document_comment_range, ADD CONSTRAINT chk_document_comment_range CHECK (end_offset > start_offset OR (candidate_json IS NOT NULL AND start_offset = 0 AND end_offset = 0 AND quote_text = ''))");
        if (!$this->hasTable('document_change')) {
            $this->execute(<<<'SQL'
CREATE TABLE pa_document_change (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 tenant_id bigint unsigned NOT NULL,
 document_id bigint unsigned NOT NULL,
 state_seq bigint unsigned NOT NULL,
 kind varchar(32) NOT NULL,
 actor_member_id bigint unsigned DEFAULT NULL,
 source_label varchar(255) NOT NULL,
 from_projection_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 to_projection_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 before_text mediumtext NOT NULL,
 after_text mediumtext NOT NULL,
 operation_id varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_progress bigint unsigned DEFAULT NULL,
 created_at datetime(3) NOT NULL,
 PRIMARY KEY (id),
 KEY idx_document_change (tenant_id,document_id,id),
 CONSTRAINT fk_document_change_document FOREIGN KEY (tenant_id,document_id) REFERENCES pa_document (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Suggestion decisions and observed changes are permanent history.');
    }
}
