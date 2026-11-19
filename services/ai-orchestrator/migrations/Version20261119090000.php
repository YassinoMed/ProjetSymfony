<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 4 — schéma de l'orchestrateur IA.
 *
 * Aucun statut `published` n'existe : la publication est structurellement
 * impossible dans ce schéma. Les clés étrangères restent internes au service.
 */
final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 4 : demandes IA, exécutions d’agents, propositions, RAG et file Messenger.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ai_requests (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                requested_by UUID DEFAULT NULL,
                prompt TEXT NOT NULL,
                status VARCHAR(32) NOT NULL,
                actor_role VARCHAR(16) NOT NULL DEFAULT 'member',
                idempotency_key VARCHAR(120) NOT NULL,
                correlation_id UUID NOT NULL,
                provider VARCHAR(64) DEFAULT NULL,
                attempts INT NOT NULL DEFAULT 0,
                error TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT chk_ai_requests_status CHECK (status IN (
                    'queued', 'analyzing', 'building', 'validating',
                    'awaiting_approval', 'approved', 'rejected', 'failed'
                )),
                CONSTRAINT chk_ai_requests_attempts CHECK (attempts >= 0)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_ai_request_idempotency ON ai_requests (tenant_id, idempotency_key)');
        $this->addSql('CREATE INDEX idx_ai_requests_tenant_status ON ai_requests (tenant_id, status, created_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE ai_runs (
                id UUID NOT NULL,
                request_id UUID NOT NULL,
                agent VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL,
                provider VARCHAR(64) DEFAULT NULL,
                model VARCHAR(120) DEFAULT NULL,
                latency_ms INT DEFAULT NULL,
                input_tokens INT DEFAULT NULL,
                output_tokens INT DEFAULT NULL,
                summary TEXT DEFAULT NULL,
                error TEXT DEFAULT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_ai_runs_request FOREIGN KEY (request_id) REFERENCES ai_requests (id) ON DELETE CASCADE,
                CONSTRAINT chk_ai_runs_status CHECK (status IN ('succeeded', 'failed')),
                CONSTRAINT chk_ai_runs_agent CHECK (agent IN ('architect', 'builder', 'auditor', 'governor', 'deployer'))
            )
            SQL);

        $this->addSql('CREATE INDEX idx_ai_runs_request ON ai_runs (request_id, started_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE ai_proposals (
                id UUID NOT NULL,
                request_id UUID NOT NULL,
                status VARCHAR(32) NOT NULL,
                payload JSONB NOT NULL,
                rationale JSONB NOT NULL,
                risks JSONB NOT NULL,
                citations JSONB NOT NULL,
                policy JSONB NOT NULL,
                agent_trace JSONB NOT NULL,
                provider_meta JSONB NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                decided_by UUID DEFAULT NULL,
                decision_reason TEXT DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT uniq_ai_proposal_request UNIQUE (request_id),
                CONSTRAINT fk_ai_proposals_request FOREIGN KEY (request_id) REFERENCES ai_requests (id) ON DELETE CASCADE,
                CONSTRAINT chk_ai_proposals_status CHECK (status IN ('awaiting_approval', 'approved', 'rejected'))
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE rag_documents (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                title VARCHAR(180) NOT NULL,
                source VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                allowed_roles JSONB NOT NULL,
                checksum VARCHAR(64) NOT NULL,
                created_by UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT uniq_rag_document_checksum UNIQUE (tenant_id, checksum)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_rag_documents_tenant ON rag_documents (tenant_id, created_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE rag_chunks (
                id UUID NOT NULL,
                document_id UUID NOT NULL,
                ordinal INT NOT NULL,
                content TEXT NOT NULL,
                embedding JSONB NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT uniq_rag_chunk_ordinal UNIQUE (document_id, ordinal),
                CONSTRAINT fk_rag_chunks_document FOREIGN KEY (document_id) REFERENCES rag_documents (id) ON DELETE CASCADE,
                CONSTRAINT chk_rag_chunks_ordinal CHECK (ordinal >= 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS rag_chunks');
        $this->addSql('DROP TABLE IF EXISTS rag_documents');
        $this->addSql('DROP TABLE IF EXISTS ai_proposals');
        $this->addSql('DROP TABLE IF EXISTS ai_runs');
        $this->addSql('DROP TABLE IF EXISTS ai_requests');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}
