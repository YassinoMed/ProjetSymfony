# MICROSERVICES — Présentation détaillée des services

L'architecture hybride repose sur **quatre applications déployables indépendamment** au sein d'un monorepo, plus une API Gateway. Les agents IA sont des composants logiques à l'intérieur de l'AI Orchestrator, et non des microservices séparés.

## 1. Core Platform (point central)

**Technologie :** Symfony 8 + PHP 8.5, Doctrine ORM, PostgreSQL, Symfony Security, Symfony Workflow, Symfony UX.

**Responsabilité :** Utilisateurs, tenants, permissions, feature registry, dynamic engine, workflows, gouvernance, audit, notifications.

**Structure :**
```
services/core-platform/
├── src/
│   ├── Identity/
│   ├── Authentication/
│   ├── Authorization/
│   ├── Tenant/
│   ├── FeatureRegistry/
│   ├── DynamicEngine/
│   ├── Workflow/
│   ├── Governance/
│   ├── Audit/
│   ├── Billing/
│   └── Notification/
├── config/
├── migrations/
└── tests/
```

**Règle :** ce service ne doit pas dépendre directement des agents IA. Il reste capable de fonctionner lorsque les services IA sont indisponibles.

## 2. AI Orchestrator

**Technologie :** Symfony 8 + adaptateurs fournisseurs (OpenAI, Ollama, autres).

**Responsabilité :** Coordination des agents, prompts, RAG, routage des modèles, génération des spécifications JSON, suivi des coûts et résultats des appels IA.

**Structure :**
```
services/ai-orchestrator/
├── src/
│   ├── Agents/
│   │   ├── ArchitectAgent/
│   │   ├── BuilderAgent/
│   │   ├── AuditorAgent/
│   │   ├── GovernorAgent/
│   │   └── DeployerAgent/
│   ├── Providers/
│   │   ├── OpenAI/
│   │   ├── Ollama/
│   │   └── OtherProviders/
│   ├── RAG/
│   ├── PromptManagement/
│   ├── ToolRegistry/
│   ├── Policies/
│   └── Messaging/
├── tests/
```

**Règle :** les cinq agents sont des composants indépendants au sein de l'Orchestrateur, pas cinq microservices.

## 3. Generation Service

**Technologie :** Symfony 8 + PHP Parser + JSON Schema.

**Responsabilité :** Transformer les propositions de l'IA en fonctionnalités exploitables. Génère schémas JSON, formulaires, workflows, CRUD, classes PHP via AST, et gère les artefacts versionnés.

**Deux moteurs :**
1. **Dynamic Generator (normal) :** schémas JSON, formulaires et workflows.
2. **Code Generator (avancé) :** classes PHP, services et extensions via AST, soumis à des contrôles plus stricts.

**Structure :**
```
services/generation-service/
├── src/
│   ├── SchemaGenerator/
│   ├── FormGenerator/
│   ├── WorkflowGenerator/
│   ├── CrudGenerator/
│   ├── AstGenerator/
│   ├── MigrationGenerator/
│   ├── ArtifactManager/
│   └── Validation/
├── tests/
```

## 4. Sandbox Runner

**Technologie :** Docker isolé, utilisateur non-root.

**Responsabilité :** Reçoit des artefacts et exécute des instructions de tests limitées dans un environnement temporaire et borné. Aucun accès direct aux données de production.

**Structure :**
```
services/sandbox-runner/
├── docker/
├── runners/
│   ├── PHPLint/
│   ├── PHPStan/
│   ├── PHPUnit/
│   └── SecurityScanner/
├── ResourceLimits/
├── ArtifactValidation/
└── tests/
```

**Contraintes par exécution :**
- CPU et mémoire limités.
- Durée maximale.
- Utilisateur non-root.
- Réseau désactivé par défaut.
- Fichiers isolés.
- Aucun secret de production.

## 5. API Gateway

**Technologie :** Nginx ou Traefik.

**Responsabilité :** Authentification, routage, rate limiting, JWT/OIDC, centralisation des accès vers le Core Platform.

## 6. Règles d'isolation des données

- Chaque service est propriétaire de sa base de données.
- Aucun microservice ne modifie directement les tables d'un autre.
- Pas de jointures SQL entre les bases de deux services.
- Les échanges de données passent par API ou événements.
- Les identifiants de tenant sont vérifiés par chaque service.
- Les données RAG sont filtrées par tenant et par permissions.

## 7. Déploiement indépendant

Chaque service possède son propre Dockerfile, ses dépendances et ses tests. Un service peut être construit, testé, versionné et déployé séparément sans impacter les autres.
