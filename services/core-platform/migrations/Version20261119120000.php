<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3 : définitions de fonctionnalités versionnées et enregistrements JSONB.
 *
 * Les versions de définition sont immuables au niveau PostgreSQL : ni UPDATE,
 * ni DELETE, ni TRUNCATE ne sont autorisés. Les enregistrements dynamiques
 * restent isolés par tenant via `tenant_id` et le filtre Doctrine.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3 : définitions versionnées, versions immuables et enregistrements JSONB';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE feature_definitions (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                feature_key VARCHAR(50) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(500) DEFAULT NULL,
                current_version INT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_feature_def_tenant_key ON feature_definitions (tenant_id, feature_key)');
        $this->addSql('CREATE INDEX idx_feature_def_tenant ON feature_definitions (tenant_id, name)');

        $this->addSql(<<<'SQL'
            ALTER TABLE feature_definitions
                ADD CONSTRAINT fk_feature_def_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE feature_definition_versions (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                feature_definition_id UUID NOT NULL,
                version INT NOT NULL,
                record_schema JSONB NOT NULL,
                ui JSONB NOT NULL,
                workflow JSONB NOT NULL,
                hash VARCHAR(64) NOT NULL,
                created_by UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_feature_version ON feature_definition_versions (feature_definition_id, version)');
        $this->addSql('CREATE INDEX idx_feature_version_definition ON feature_definition_versions (feature_definition_id, version DESC)');
        $this->addSql('CREATE INDEX idx_feature_version_hash ON feature_definition_versions (feature_definition_id, hash)');

        $this->addSql(<<<'SQL'
            ALTER TABLE feature_definition_versions
                ADD CONSTRAINT fk_feature_version_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE feature_definition_versions
                ADD CONSTRAINT fk_feature_version_definition
                FOREIGN KEY (feature_definition_id) REFERENCES feature_definitions (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE feature_definition_versions
                ADD CONSTRAINT fk_feature_version_author
                FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE dynamic_records (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                feature_definition_id UUID NOT NULL,
                schema_version INT NOT NULL,
                data JSONB NOT NULL,
                status VARCHAR(30) NOT NULL,
                created_by UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_dynamic_records_feature ON dynamic_records (tenant_id, feature_definition_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_dynamic_records_status ON dynamic_records (tenant_id, feature_definition_id, status)');
        $this->addSql('CREATE INDEX idx_dynamic_records_data ON dynamic_records USING GIN (data jsonb_path_ops)');

        $this->addSql(<<<'SQL'
            ALTER TABLE dynamic_records
                ADD CONSTRAINT fk_dynamic_record_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE dynamic_records
                ADD CONSTRAINT fk_dynamic_record_definition
                FOREIGN KEY (feature_definition_id) REFERENCES feature_definitions (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE dynamic_records
                ADD CONSTRAINT fk_dynamic_record_version
                FOREIGN KEY (feature_definition_id, schema_version)
                REFERENCES feature_definition_versions (feature_definition_id, version)
                ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE dynamic_records
                ADD CONSTRAINT fk_dynamic_record_author
                FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        // Une version publiée ne peut jamais être altérée : ni UPDATE, ni DELETE,
        // ni TRUNCATE, même avec un compte applicatif disposant des droits DML.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION aep_feature_versions_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'feature_definition_versions est immuable : opération % interdite', TG_OP;
            END;
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_feature_versions_no_update_delete
            BEFORE UPDATE OR DELETE ON feature_definition_versions
            FOR EACH ROW EXECUTE FUNCTION aep_feature_versions_immutable()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_feature_versions_no_truncate
            BEFORE TRUNCATE ON feature_definition_versions
            FOR EACH STATEMENT EXECUTE FUNCTION aep_feature_versions_immutable()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_feature_versions_no_truncate ON feature_definition_versions');
        $this->addSql('DROP TRIGGER IF EXISTS trg_feature_versions_no_update_delete ON feature_definition_versions');
        $this->addSql('DROP FUNCTION IF EXISTS aep_feature_versions_immutable()');
        $this->addSql('DROP TABLE dynamic_records');
        $this->addSql('DROP TABLE feature_definition_versions');
        $this->addSql('DROP TABLE feature_definitions');
    }
}
