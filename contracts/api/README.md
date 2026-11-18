# Contrats API internes

Ce dossier contient les contrats REST échangés entre l'API Gateway, le Core Platform et les microservices.

## Règles

- Un contrat publié n'est pas modifié en place : créer une nouvelle version.
- Les changements rompus nécessitent une période de coexistence des versions.
- Les consommateurs ne s'appuient que sur les champs documentés.
- La validation serveur reste obligatoire.

## Endpoints prévus

| Méthode | Chemin | Cible | Objet |
|---|---|---|---|
| `POST` | `/internal/auth/verify` | Core Platform | Vérification d'un jeton interne |
| `GET` | `/internal/tenants/{id}` | Core Platform | Contexte tenant |
| `GET` | `/internal/features` | Core Platform | Catalogue des fonctionnalités |
| `GET` | `/internal/features/{id}/status` | Core Platform | Statut d'une version |
| `GET` | `/internal/ai/requests/{id}` | AI Orchestrator | Statut d'une demande IA |
| `GET` | `/internal/generation/artifacts/{id}` | Generation Service | Récupération d'un artefact |

Les schémas détaillés seront ajoutés au fil des phases (voir `PLAN.md`).
