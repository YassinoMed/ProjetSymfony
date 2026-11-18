<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1 : tenants, utilisateurs et adhésions multi-tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tenants (
                id UUID NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_tenants_slug ON tenants (slug)');

        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                roles JSON NOT NULL,
                password VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_memberships (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                user_id UUID NOT NULL,
                role VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_membership_user ON tenant_memberships (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_membership ON tenant_memberships (tenant_id, user_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_memberships
                ADD CONSTRAINT fk_membership_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_memberships
                ADD CONSTRAINT fk_membership_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_memberships');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE tenants');
    }
}
