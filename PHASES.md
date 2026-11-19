# PHASES — Découpage en lots (architecture hybride)

Les durées sont indicatives et doivent être réestimées après audit technique et définition détaillée des cas d'usage.

## Phase 1 — Infrastructure et Core Symfony
**Objectif :** poser le socle applicatif et l'infrastructure de base.
- Symfony, PHP, Composer et CI.
- Docker Compose local.
- PostgreSQL, Redis, RabbitMQ et Mercure.
- Authentification, RBAC et tenant context.
- Journal d'audit initial.
- Health checks et logs structurés.

**Critères de sortie :** installation reproductible, migrations testées, tests de base verts, secrets hors dépôt.

## Phase 2 — Multi-tenant et sécurité
**Statut :** terminée le 2026-11-18 — preuves et réserves dans `PHASE2_REPORT.md`.
**Objectif :** isoler complètement les données par tenant.
- Isolation tenant vérifiée à chaque accès.
- RBAC et politiques déterministes.
- Protection CSRF, validation, rate limiting.
- Chiffrement TLS et gestion de secrets.
- Scans des dépendances.

**Critères de sortie :** aucun lecture inter-tenant dans les tests négatifs ; actions interdites refusées côté serveur.

## Phase 3 — Moteur dynamique
**Statut :** terminée le 2026-11-19 — preuves et réserves dans `PHASE3_REPORT.md`.
**Objectif :** créer des fonctionnalités sans nouveau fichier PHP.
- JSON Schema versionné.
- Validation stricte des définitions.
- Générateur de formulaires.
- Moteur de rendu de listes et détails.
- CRUD générique.
- Filtrage, pagination et tri sur champs autorisés.
- Workflow configurable avec gardes déterministes.

**Critères de sortie :** une fonctionnalité CRUD complète est créée sans nouveau fichier PHP, avec permissions et tests.

## Phase 4 — AI Orchestrator
**Objectif :** orchestrer les agents IA de manière asynchrone.
- Contrat de fournisseur IA.
- Agent Architecte, Builder limité, Auditeur, Gouverneur, Déployeur.
- File asynchrone, retries, timeouts et idempotence.
- RAG avec filtrage des documents par tenant et permissions.
- Fournisseurs cloud et Ollama local (développement).

**Critères de sortie :** une demande produit une proposition structurée, explicable et non publiée automatiquement.

## Phase 5 — Generation Service
**Objectif :** transformer les propositions en fonctionnalités exploitables.
- Générateur de schémas JSON, formulaires et workflows.
- Générateur AST/PHP (mécanisme avancé, contrôles stricts).
- Gestion d'artefacts versionnés.
- Validation des métadonnées.

**Critères de sortie :** génération de schémas et de code PHP testée et signée.

## Phase 6 — Sandbox Runner
**Objectif :** exécuter et valider le code généré isolément.
- Workspace temporaire, utilisateur non-root.
- `php -l`, PHPStan, PHPUnit, scan de dépendances.
- Limites CPU, mémoire, disque et durée.
- Réseau sortant bloqué par défaut.
- Artefacts signés et promotion vers staging.

**Critères de sortie :** aucun code généré n'est exécuté sur le serveur web ; publication uniquement après homologation.

## Phase 7 — Messaging et Mercure
**Objectif :** fiabiliser la communication asynchrone et le temps réel.
- RabbitMQ durable et supervisé, dead-letter queue.
- Transactional Outbox.
- Mercure pour l'état d'avancement (sans fuite inter-tenant).
- Webhooks et notifications.

**Critères de sortie :** tâches idempotentes et récupérables ; temps réel isolé par tenant.

## Phase 8 — Catalogue global
**Objectif :** réutiliser les fonctionnalités entre tenants.
- Proposition d'une fonctionnalité tenant au catalogue global.
- Revue, tests de compatibilité, approbation, suppression des données propres au tenant.
- Versionnement immuable et rollback.

**Critères de sortie :** publication traçable, réversible et protégée par autorisation.

## Phase 9 — Cloud et homologation
**Objectif :** valider en production.
- Déploiement progressif.
- Tests de charge et de résilience (100 utilisateurs virtuels).
- Tests d'isolation multi-tenant.
- Tests de restauration.
- Tests de panne IA, RabbitMQ, Redis et PostgreSQL.
- Revue sécurité indépendante.
- Monitoring, alertes, sauvegardes vérifiées.

**Critères de sortie :** SLO vérifiés, procédures opérationnelles et rollback testés, verdict d'homologation.

## Critères AUTO-PASS (toutes phases)
Chaque phase doit produire un audit initial, la liste des fichiers créés/modifiés, des tests unitaires et d'intégration, des tests de sécurité, des preuves reproductibles, un rapport de performance si nécessaire, et un verdict `PASS`, `PASS_WITH_RESERVATIONS` ou `FAIL`.
