# API_CONTRACTS — Contrats REST et schémas d'échange

Ce document décrit les contrats d'échange entre les microservices et l'API Gateway. Les contrats sont versionnés pour éviter le dérèglement d'interface entre consommateur et fournisseur.

## 1. Communication synchrone (REST)

Endpoints internes principaux :

| Méthode | Chemin | Service appelant | Service cible | Objet |
|---|---|---|---|---|
| `POST` | `/internal/auth/verify` | Gateway, services | Core Platform | Vérification d'un jeton interne |
| `GET` | `/internal/tenants/{id}` | Services | Core Platform | Contexte tenant |
| `GET` | `/internal/features` | Services | Core Platform | Catalogue des fonctionnalités |
| `GET` | `/internal/features/{id}/status` | Services | Core Platform | Statut d'une version |
| `POST` | `/internal/features/{id}/activate` | Core Platform | Core Platform | Activation d'une version |
| `GET` | `/internal/ai/requests/{id}` | Core Platform | AI Orchestrator | Statut d'une demande IA |
| `GET` | `/internal/generation/artifacts/{id}` | Core Platform | Generation Service | Récupération d'un artefact |

Toutes les réponses internes retournent un payload JSON minimal, sans données sensibles inutiles.

## 2. Communication asynchrone (RabbitMQ)

Les payloads d'événements suivent le schéma versionné défini dans `EVENTS.md`. Les files utilisent un exchange thématique par domaine (`feature`, `ai`, `generation`, `sandbox`, `governance`).

## 3. Schémas JSON Schema

Les définitions dynamiques respectent un JSON Schema versionné et une liste blanche de types, opérateurs et composants. Les schémas sont stockés dans `contracts/json-schemas/`.

Exemple de structure de définition :
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "properties": {
    "slug": { "type": "string" },
    "name": { "type": "string" },
    "fields": { "type": "array" }
  },
  "required": ["slug", "name", "fields"]
}
```

## 4. Règles de contrat

- Un contrat publié n'est pas modifié en place ; une nouvelle version est créée.
- Les changements rompus nécessitent une période de coexistence des versions.
- Les consommateurs ne doivent pas s'appuyer sur des champs non documentés.
- La validation serveur est obligatoire, même si l'interface valide déjà.
