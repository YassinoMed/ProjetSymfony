# Dossier de conception — Plateforme Symfony auto-évolutive

## Objectif
Construire une plateforme multi-tenant capable de créer des fonctionnalités à la demande à partir de descriptions en langage naturel. Le socle privilégie la génération de métadonnées (JSON Schema, formulaires, vues et workflows) et réserve la génération de code PHP aux cas avancés, dans une sandbox isolée.

## Décisions de référence
- Architecture : micro-services, chaque service sur sa propre machine VM dédiée.
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

## Documents
| Fichier | Contenu |
|---|---|
| `PLAN.md` | Plan général et décisions |
| `PHASES.md` | Phases, lots et critères de sortie |
| `REQUIREMENTS.md` | Exigences fonctionnelles et non fonctionnelles |
| `ARCHITECTURE.md` | Architecture logique et composants |
| `INFRASTRUCTURE.md` | Ressources matérielles, services et Docker |
| `SECURITY.md` | Modèle de sécurité et menaces |
| `DATA_MODEL.md` | Modèle de données initial |
| `AI_AGENTS.md` | Agents, outils et orchestration |
| `TEST_PLAN.md` | Stratégie et scénarios de tests |
| `DEPLOYMENT.md` | Déploiement, sauvegardes et rollback |
| `DECISIONS.md` | Registre des décisions architecturales |
| `BACKLOG.md` | Backlog initial priorisé |

## Statut
Ce dossier constitue une base de cadrage et de conception. Les versions exactes des dépendances, les coûts cloud, la capacité réelle et la conformité sécurité doivent être confirmés lors du lot Infrastructure et par des tests reproductibles.
