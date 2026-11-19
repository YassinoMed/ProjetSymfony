# DATA MODEL — Modèle de données initial

## 1. Entités principales

### tenants
- id (UUID)
- name
- slug
- status
- created_at

### users
- id
- email
- password_hash
- status
- created_at

### tenant_memberships
- id
- tenant_id
- user_id
- role
- status
- unique(tenant_id, user_id)

### feature_definitions
- id (UUID)
- owner_tenant_id nullable
- scope (`tenant` ou `global`)
- slug
- name
- description
- schema_json (JSONB)
- status
- created_by
- created_at

### feature_versions
- id
- feature_definition_id
- version
- schema_json
- schema_hash
- validation_status
- created_at
- published_at nullable
- unique(feature_definition_id, version)

### feature_instances
- id
- tenant_id
- feature_version_id
- status
- enabled_at
- unique(tenant_id, feature_version_id)

### dynamic_records
- id
- tenant_id
- feature_instance_id
- data_json (JSONB)
- created_by
- updated_by
- created_at
- updated_at

### workflow_definitions
- id
- feature_version_id
- definition_json
- version
- status

### generation_requests
- id
- tenant_id
- requested_by
- prompt
- status
- idempotency_key
- created_at
- completed_at nullable

### agent_executions
- id
- generation_request_id
- agent_name
- provider
- model
- status
- input_hash
- output_json
- started_at
- finished_at

### validation_results
- id
- generation_request_id
- check_name
- status
- details_json
- created_at

### approval_requests
- id
- tenant_id
- target_type
- target_id
- requested_by
- approved_by nullable
- status
- decision_reason
- created_at
- decided_at nullable

### deployment_history
- id
- tenant_id nullable
- feature_version_id
- environment
- action
- status
- artifact_hash
- deployed_by
- created_at

### audit_events
- id
- tenant_id nullable
- actor_type
- actor_id
- action
- resource_type
- resource_id
- payload_hash
- previous_hash
- event_hash
- occurred_at

## 1bis. Base `ai` — orchestrateur (Phase 4)

Chaque service possède sa propre base PostgreSQL. Le service AI utilise la base `ai` et ne référence jamais les tables du Core par clé étrangère : `tenant_id`, `requested_by` et `decided_by` sont des UUID opaques.

### ai_requests
- id (UUID)
- tenant_id (UUID)
- requested_by (UUID, nullable)
- prompt (TEXT)
- status (`queued`, `analyzing`, `building`, `validating`, `awaiting_approval`, `approved`, `rejected`, `failed`)
- actor_role (`owner`, `admin`, `member`, `auditor`)
- idempotency_key
- correlation_id (UUID)
- provider (nullable)
- attempts (INT ≥ 0)
- error (TEXT, nullable, borné à 2000)
- created_at, updated_at
- unique `(tenant_id, idempotency_key)`
- index `(tenant_id, status, created_at)`
- check SQL interdisant tout statut hors liste

### ai_runs
- id (UUID)
- request_id → `ai_requests` (CASCADE)
- agent (`architect`, `builder`, `auditor`, `governor`, `deployer`)
- status (`succeeded`, `failed`)
- provider, model (nullable)
- latency_ms, input_tokens, output_tokens (nullable)
- summary, error (nullable)
- started_at, finished_at (nullable)
- index `(request_id, started_at)`

### ai_proposals
- id (UUID)
- request_id → `ai_requests` (CASCADE), **unique** : une proposition par demande
- status (`awaiting_approval`, `approved`, `rejected`) — **aucun statut `published`**
- payload (JSONB)
- rationale (JSONB)
- risks (JSONB)
- citations (JSONB)
- policy (JSONB)
- agent_trace (JSONB)
- provider_meta (JSONB)
- created_at, decided_at (nullable), decided_by (nullable), decision_reason (nullable)
- check SQL interdisant tout statut hors liste

### rag_documents
- id (UUID)
- tenant_id (UUID)
- title
- source
- content (TEXT)
- allowed_roles (JSONB : `owner`, `admin`, `member`, `auditor`)
- checksum (SHA-256)
- created_by (nullable)
- created_at
- unique `(tenant_id, checksum)`
- index `(tenant_id, created_at)`

### rag_chunks
- id (UUID)
- document_id → `rag_documents` (CASCADE)
- ordinal (INT ≥ 0)
- content (TEXT)
- embedding (JSONB)
- created_at
- unique `(document_id, ordinal)`

### messenger_messages
- id, body, headers, queue_name, created_at, available_at, delivered_at
- index Messenger `(queue_name, available_at, delivered_at, id)`

### Règles Phase 4
- Aucune publication n'est représentable : ni statut, ni table, ni route.
- Les embeddings sont stockés en JSONB et comparés en PHP ; pgvector n'est pas encore indexé.
- Les prompts et contenus RAG sont en clair ; le chiffrement au repos reste à décider.
- Les décisions humaines sont conservées avec acteur, horodatage et motif.

## 2. Indexation
- B-tree sur tenant_id, status, created_at et clés étrangères.
- GIN sur JSONB uniquement pour les chemins réellement interrogés.
- Index uniques sur slug par périmètre et version.
- Index vectoriel pgvector après validation du modèle d'embedding et du volume.

## 3. Règles
- UUID pour les identifiants exposés.
- Clés étrangères et contraintes SQL.
- Transactions pour activation et publication.
- Données tenant obligatoirement filtrées.
- Les versions publiées sont immuables.
- Définir rétention, archivage et suppression selon les exigences légales.
