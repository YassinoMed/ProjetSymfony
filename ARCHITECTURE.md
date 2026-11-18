# ARCHITECTURE — Architecture logique et technique

## 1. Style

Architecture hybride modulaire + microservices ciblés.

- Un **Core Symfony central** gère le métier : utilisateurs, tenants, permissions, fonctionnalités, workflows, gouvernance et audit.
- Quatre applications déployables indépendamment : **Core Platform**, **AI Orchestrator**, **Generation Service** et **Sandbox Runner**.
- Une **API Gateway** centralise les accès (authentification, routage, rate limiting).
- Communication **asynchrone via RabbitMQ** pour les longues tâches ; **REST API synchrone** pour les réponses immédiates.
- **PostgreSQL** pour les données métier, chaque service propriétaire de sa base (pas de jointures inter-services, pas de modification directe des tables d'un autre service).
- **Redis** (cache/verrous), **Mercure** (temps réel), **Stockage objet S3** (fichiers et artefacts).
- **Docker** pour l'isolation ; production sur VMs dédiées ou services managés (Kubernetes non recommandé au démarrage).

Les agents IA sont des **composants logiques** à l'intérieur de l'AI Orchestrator, et non des microservices distincts.

Le Core reste capable de fonctionner lorsque les services IA sont indisponibles.

## 2. Domaines (Core Platform)
- Identity & Access : utilisateurs, rôles, permissions.
- Tenant Management : tenants, adhésions, contexte tenant.
- Feature Registry : catalogue, versions, activation.
- Dynamic Engine : schémas, formulaires, vues, données et workflows.
- AI Orchestration : demandes, agents, fournisseurs et outils (dans le service AI Orchestrator).
- Governance : approbations, politiques, publications.
- Audit : événements immuables et preuves d'intégrité.
- Sandbox : génération et validation de code isolé (dans le service Sandbox Runner).

## 3. Frontières de confiance
1. Navigateur → API Gateway : TLS, rate limiting.
2. API Gateway → Core Platform : réseau privé, JWT/OIDC.
3. Core Platform → bases/queues : identités de service à privilèges minimaux.
4. AI Orchestrator → fournisseur IA : données minimisées, secrets protégés.
5. Generation Service → Sandbox Runner : artefacts et tâches bornés, aucun accès privilégié.
6. Sandbox Runner → staging : promotion d'artefacts validés uniquement.

## 4. Règles de conception
- Le Core ne dépend pas des modules dynamiques.
- Les modules dynamiques dépendent de contrats stables.
- Aucun `eval()` et aucune exécution de PHP dans PHP-FPM.
- Les définitions dynamiques sont validées avant stockage et avant activation.
- Les permissions sont évaluées côté serveur.
- Les requêtes de données dynamiques sont construites avec des identifiants autorisés et des paramètres liés.
- Les événements métier sont publiés après commit.
- Les traitements asynchrones sont idempotents.
- Aucun microservice ne modifie directement les tables d'un autre.
- Pas de jointures SQL entre les bases de deux services ; les échanges passent par API ou événements.
- Les identifiants de tenant sont vérifiés par chaque service.
- Les données RAG sont filtrées par tenant et par permissions.

## 5. Génération dynamique
Pipeline : demande → proposition JSON → validation de schéma → revue → version candidate → tests → approbation → activation tenant.

Les formulaires et vues sont rendus par des composants génériques. Les types de champs, filtres, règles et actions sont limités à un catalogue explicite.

## 6. Génération PHP
Réservée aux extensions non exprimables par le moteur de métadonnées. Le code est produit par le Generation Service, produit dans une sandbox éphémère, testé, signé et stocké comme artefact versionné. L'exécution éventuelle se fait dans un runtime séparé, avec permissions minimales.

## 7. Microservices recommandés

| Service | Technologie | Responsabilité |
|---|---|---|
| Core Platform | Symfony 8 + PHP 8.5 | Utilisateurs, tenants, permissions, feature registry, dynamic engine, workflows, gouvernance, audit |
| AI Orchestrator | Symfony 8 | Agents IA, prompts, RAG, fournisseurs, registre d'outils, politiques, messagerie |
| Generation Service | Symfony 8 + PHP Parser | Schémas JSON, formulaires, workflows, CRUD, génération AST/PHP, gestion d'artefacts, validation |
| Sandbox Runner | Docker isolé | Lint PHP, PHPStan, PHPUnit, scan de sécurité, validation d'artefacts, quotas stricts |

### Communication

**Synchrone (REST API)** : authentification interne, consultation du statut d'une génération, vérification d'une version, consultation du catalogue.

**Asynchrone (RabbitMQ)** : génération d'une fonctionnalité, appel des agents IA, construction de code PHP, exécution PHPUnit, analyse de sécurité, publication et notifications.

### Contrats d'événements (versionnés)

| Événement | Producteur | Consommateur |
|---|---|---|
| `feature.generation.requested` | Core | IA |
| `feature.specification.created` | IA | Générateur |
| `feature.artifact.created` | Générateur | QA / Sandbox |
| `feature.validation.completed` | QA / Sandbox | Gouverneur |
| `feature.approval.requested` | Gouverneur | Core |
| `feature.publication.requested` | Core | Déployeur |
| `feature.published` | Core | Notification |

Chaque événement contient un identifiant, un tenant, une version de contrat, un identifiant de corrélation et une référence à l'artefact, sans transmettre inutilement des données sensibles.

Un **Transactional Outbox** garantit la cohérence entre l'enregistrement en base et l'envoi des événements RabbitMQ.

## 8. Organisation des bases de données

**Core Database — PostgreSQL**
Utilisateurs · Tenants · Permissions · Fonctionnalités · Workflows · Audit

**AI Database — PostgreSQL**
Demandes IA · Exécutions · Modèles · Conversations · Embeddings · RAG

**Generation Storage**
Artefacts · Versions candidates · Résultats de génération

**Object Storage**
Documents · Exports · Packages · Rapports de tests

Pour commencer, les deux bases PostgreSQL peuvent être hébergées sur le même serveur, avec des bases et des utilisateurs distincts. Elles pourront être séparées physiquement plus tard.

## 9. Disponibilité et évolutivité

- Chaque service est déployable, testé, versionné et mis à jour indépendamment.
- PHP-FPM (Core) horizontalement réplicable.
- Workers Messenger scalables séparément.
- PostgreSQL avec sauvegardes, supervision et stratégie HA selon budget.
- Redis non utilisé comme unique stockage durable des tâches.
- RabbitMQ durable et supervisé.
- Fichiers dans un stockage objet, pas dans le conteneur applicatif.
- La sandbox devra avoir des quotas stricts (CPU, mémoire, disque, durée, réseau désactivé par défaut).
