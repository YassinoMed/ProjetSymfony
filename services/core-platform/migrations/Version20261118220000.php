<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 : journal d\'audit append-only et chaîne de hachage par tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_logs (
                id UUID NOT NULL,
                tenant_id UUID DEFAULT NULL,
                actor_id UUID DEFAULT NULL,
                actor_email VARCHAR(180) NOT NULL,
                action VARCHAR(60) NOT NULL,
                result VARCHAR(20) NOT NULL,
                resource_type VARCHAR(80) NOT NULL,
                resource_id VARCHAR(64) DEFAULT NULL,
                context JSON NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                previous_hash VARCHAR(64) DEFAULT NULL,
                hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_audit_tenant_created ON audit_logs (tenant_id, created_at)');
        $this->addSql('CREATE INDEX idx_audit_actor ON audit_logs (actor_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_audit_hash ON audit_logs (hash)');

        $this->addSql(<<<'SQL'
            ALTER TABLE audit_logs
                ADD CONSTRAINT fk_audit_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE audit_logs
                ADD CONSTRAINT fk_audit_actor
                FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        // Le journal est append-only : même un compte applicatif disposant des
        // droits DML ne peut ni modifier ni supprimer une entrée.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION aep_audit_logs_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs est append-only : opération % interdite', TG_OP;
            END;
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_audit_logs_no_update_delete
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION aep_audit_logs_append_only()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_audit_logs_no_truncate
            BEFORE TRUNCATE ON audit_logs
            FOR EACH STATEMENT EXECUTE FUNCTION aep_audit_logs_append_only()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_audit_logs_no_truncate ON audit_logs');
        $this->addSql('DROP TRIGGER IF EXISTS trg_audit_logs_no_update_delete ON audit_logs');
        $this->addSql('DROP FUNCTION IF EXISTS aep_audit_logs_append_only()');
        $this->addSql('DROP TABLE audit_logs');
    }
}
