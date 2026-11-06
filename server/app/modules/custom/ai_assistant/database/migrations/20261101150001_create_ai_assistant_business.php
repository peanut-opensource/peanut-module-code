<?php
declare(strict_types=1);
use think\migration\Migrator;
use Phinx\Migration\IrreversibleMigrationException;
final class CreateAiAssistantBusiness extends Migrator
{
    public function up():void
    {
        $common="id bigint unsigned NOT NULL AUTO_INCREMENT,tenant_id bigint unsigned NOT NULL,title varchar(200) NOT NULL,status varchar(40) NOT NULL,version bigint unsigned NOT NULL DEFAULT 1,created_by bigint unsigned NOT NULL,details json NOT NULL,request_id varchar(100) NOT NULL,created_at bigint unsigned NOT NULL,updated_at bigint unsigned NOT NULL";
        $this->execute("CREATE TABLE pa_ai_assistant_config ($common,kind varchar(30) NOT NULL,secret_payload json DEFAULT NULL,deleted_at bigint unsigned NOT NULL DEFAULT 0,PRIMARY KEY(id),UNIQUE KEY tenant_request(tenant_id,request_id),KEY tenant_kind(tenant_id,kind,status,deleted_at),KEY tenant_actor(tenant_id,created_by)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE pa_ai_assistant_task ($common,source_snapshot json NOT NULL,sealed_config json NOT NULL,actor_binding json NOT NULL,job_key varchar(64) DEFAULT NULL,request_hash char(64) NOT NULL,PRIMARY KEY(id),UNIQUE KEY tenant_request(tenant_id,request_id),UNIQUE KEY tenant_job(tenant_id,job_key),KEY tenant_status(tenant_id,status,id),KEY tenant_actor(tenant_id,created_by)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE pa_ai_assistant_event ($common,parent_id bigint unsigned NOT NULL,actor_type varchar(30) NOT NULL,PRIMARY KEY(id),UNIQUE KEY tenant_request(tenant_id,request_id),KEY parent_history(tenant_id,parent_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE pa_ai_assistant_quota ($common,connection_id bigint unsigned NOT NULL,period char(10) NOT NULL,reserved_requests bigint unsigned NOT NULL DEFAULT 0,confirmed_requests bigint unsigned NOT NULL DEFAULT 0,unknown_requests bigint unsigned NOT NULL DEFAULT 0,PRIMARY KEY(id),UNIQUE KEY tenant_period(tenant_id,connection_id,period),KEY tenant_actor(tenant_id,created_by)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down():void{throw new IrreversibleMigrationException('Retain configuration, usage, uncertain provider outcomes and human decisions.');}
}
