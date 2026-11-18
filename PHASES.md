# PHASES — Découpage en lots

Les durées sont indicatives et doivent être réestimées après audit technique et définition détaillée des cas d'usage.

## Phase 0 — Cadrage et audit
**Objectif :** figer les décisions et réduire les inconnues.
- Valider les personas, rôles et parcours.
- Définir les frontières tenant/global.
- Définir les types de fonctionnalités générables.
- Établir les menaces et contraintes réglementaires.
- Valider le budget cloud et les fournisseurs IA.

**Sortie :** exigences approuvées, architecture cible et backlog priorisé.

## Phase 1 — Socle et infrastructure
- Symfony, PHP, Composer et CI.
- Provisionnement des VM dédiées par service (architecture micro-services).
- Docker Compose local (développement uniquement).
- PostgreSQL, Redis, RabbitMQ et Mercure.
- Authentification, RBAC et tenant context.
- Journal d'audit initial.
- Health checks et logs structurés.

**Critères de sortie :** installation reproductible, migrations testées, tests de base verts, secrets hors dépôt.

## Phase 2 — Moteur dynamique
- JSON Schema versionné.
- Validation stricte des définitions.
- Générateur de formulaires.
- Moteur de rendu de listes et détails.
- CRUD générique.
- Filtrage, pagination et tri sur champs autorisés.
- Workflow configurable avec gardes déterministes.

**Critères de sortie :** une fonctionnalité CRUD complète est créée sans nouveau fichier PHP, avec permissions et tests.

## Phase 3 — Multi-agents IA
- Contrat de fournisseur IA.
- Agent Architecte.
- Agent Builder limité aux outils autorisés.
- Agent Auditeur.
- Agent Gouverneur.
- Agent Déployeur.
- File asynchrone, retries, timeouts et idempotence.
- RAG avec filtrage des documents par tenant et permissions.

**Critères de sortie :** une demande produit une proposition structurée, explicable et non publiée automatiquement.

## Phase 4 — Validation et publication
- Prévisualisation.
- Tests automatiques des métadonnées.
- Approbation humaine selon le niveau de risque.
- Activation progressive par tenant.
- Versionnement immuable.
- Rollback et audit.

**Critères de sortie :** publication traçable, réversible et protégée par autorisation.

## Phase 5 — Usine logicielle isolée
- Génération PHP AST dans un workspace temporaire.
- Analyse `php -l`, PHPStan, PHPUnit et scan de dépendances.
- Image sandbox non privilégiée.
- Réseau sortant bloqué par défaut.
- Limites CPU, mémoire, disque et durée.
- Artefacts signés et promotion vers staging.

**Critères de sortie :** aucun code généré n'est exécuté sur le serveur web; publication uniquement après homologation.

## Phase 6 — Préproduction et montée en charge
- Déploiement staging.
- Tests de charge et de résilience.
- Tests d'isolation multi-tenant.
- Tests de restauration.
- Tests de panne IA, RabbitMQ, Redis et PostgreSQL.
- Revue sécurité indépendante.

**Critères de sortie :** SLO vérifiés, procédures opérationnelles et rollback testés.

## Phase 7 — Production
- Déploiement progressif.
- Monitoring et alertes.
- Sauvegardes et restauration vérifiées.
- Gestion des incidents.
- Revue périodique des coûts et capacités.
