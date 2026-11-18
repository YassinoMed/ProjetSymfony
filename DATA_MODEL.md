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
