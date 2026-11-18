# Dossier de conception — Plateforme Symfony auto-évolutive

## Objectif
Construire une plateforme multi-tenant capable de créer des fonctionnalités à la demande à partir de descriptions en langage naturel. Le socle privilégie la génération de métadonnées (JSON Schema, formulaires, vues et workflows) et réserve la génération de code PHP aux cas avancés, dans une sandbox isolée.

## Décisions de référence
- Architecture : hybride modulaire + microservices ciblés (Core Symfony + AI Orchestrator + Generation Service + Sandbox Runner + API Gateway).
- Communication : REST synchrone + RabbitMQ asynchrone.
- Usage : environ 100 utilisateurs simultanés comme cible initiale de dimensionnement, à confirmer par tests de charge.
- Hébergement : hybride Mac de développement + cloud de production/préproduction.
- IA : hybride, avec fournisseurs externes et modèles locaux facultatifs.
- Base : PostgreSQL 17 + JSONB + pgvector.
- Files : Symfony Messenger avec RabbitMQ pour les tâches asynchrones.
- Cache/verrous : Redis.
- Temps réel : Mercure.
- Génération par défaut : métadonnées validées par JSON Schema.
- Génération PHP : exceptionnelle, versionnée, auditée et exécutée dans une sandbox dédiée.
- Sécurité : isolation tenant, RBAC, validation serveur, audit append-only, approbation humaine des opérations sensibles.
- Conteneurs : Docker (Compose en dev/staging, VMs ou services managés en prod ; pas de Kubernetes au démarrage).
- Dépôt : monorepo avec un dossier `services/` par application déployable.

## Documents
| Fichier | Contenu |
|---|---|
| `PLAN.md` | Plan général, périmètre, principes et plan de développement en 9 phases |
| `PHASES.md` | Phases, lots et critères de sortie |
| `REQUIREMENTS.md` | Exigences fonctionnelles et non fonctionnelles |
| `ARCHITECTURE.md` | Architecture logique, composants, frontières et contrats d'événements |
| `MICROSERVICES.md` | Présentation détaillée des 4 microservices et de leurs responsabilités |
| `API_CONTRACTS.md` | Contrats REST et schémas d'échange |
| `EVENTS.md` | Événements versionnés et Transactional Outbox |
| `DOCKER_ARCHITECTURE.md` | Conteneurisation, Dockerfiles et docker-compose |
| `SCALABILITY.md` | Dimensionnement, montée en charge et isolation |
| `INFRASTRUCTURE.md` | Ressources matérielles, services et environnements |
| `SECURITY.md` | Modèle de sécurité et menaces |
| `DATA_MODEL.md` | Modèle de données initial |
| `AI_AGENTS.md` | Agents, outils et orchestration |
| `TEST_PLAN.md` | Stratégie et scénarios de tests |
| `DEPLOYMENT.md` | Déploiement, sauvegardes et rollback |
| `DECISIONS.md` | Registre des décisions architecturales |
| `BACKLOG.md` | Backlog initial priorisé |

## Statut
Ce dossier constitue une base de cadrage et de conception. Les versions exactes des dépendances, les coûts cloud, la capacité réelle et la conformité sécurité doivent être confirmés lors du lot Infrastructure et par des tests reproductibles.

## Démarrage technique

Le squelette du monorepo (services Symfony, Docker Compose, contrats, frontend) est décrit dans [`GETTING_STARTED.md`](GETTING_STARTED.md).
