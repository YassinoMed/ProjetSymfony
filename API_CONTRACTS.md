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
| `POST` | `/internal/ai/requests` | Core Platform | AI Orchestrator | Création idempotente d'une demande IA |
| `GET` | `/internal/ai/proposals/{id}` | Core Platform | AI Orchestrator | Proposition structurée |
| `POST` | `/internal/ai/proposals/{id}/decision` | Core Platform | AI Orchestrator | Approbation ou rejet humain |
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

## 5. API interne AI Orchestrator

Contrat complet : `contracts/api/ai-orchestrator.openapi.yaml`.

### En-têtes obligatoires

| En-tête | Portée | Contenu |
|---|---|---|
| `X-Internal-Token` | toutes les routes `/internal/*` | Jeton partagé, échec fermé |
| `X-Tenant-Id` | toutes les routes internes | UUID du tenant |
| `X-User-Id` | décision | UUID de l'acteur |
| `X-Actor-Role` | toutes les routes internes | `owner`, `admin`, `member`, `auditor` |

`GET /health` est public et retourne `{"status":"ok","service":"ai-orchestrator","publication":"forbidden"}`.

### Créer une demande

`POST /internal/ai/requests`

```json
{
  "prompt": "Créer une gestion des tâches avec priorités et échéances.",
  "idempotency_key": "core:01a1f2cd-4881-79a0-aaaa-be0aab162eb7"
}
```

- `202` : demande créée, pipeline planifié.
- `200` : clé d'idempotence déjà vue pour ce tenant, en-tête `Idempotent-Replay: true`.
- `400` : en-tête, corps ou rôle invalide.
- `401` : jeton absent ou invalide.
- Clés inconnues refusées, y compris `published`.

Réponse :

```json
{
  "request_id": "…",
  "status": "awaiting_approval",
  "proposal": {
    "id": "…",
    "status": "awaiting_approval",
    "published": false,
    "publication": "forbidden",
    "payload": { "type": "feature_definition", "key": "…", "definition": { } },
    "rationale": { "summary": "…", "decisions": [], "assumptions": [], "open_questions": [] },
    "risks": [],
    "citations": [],
    "policy": {
      "status": "awaiting_approval",
      "requires_human_approval": true,
      "publication": "forbidden",
      "risk_level": "low",
      "checks": []
    },
    "agent_trace": [],
    "provider": { "provider": "stub", "model": "deterministic-v1" }
  }
}
```

### Lire une demande ou une proposition

`GET /internal/ai/requests/{id}` et `GET /internal/ai/proposals/{id}`.

- `200` : objet du tenant courant.
- `404` : identifiant inconnu **ou** appartenant à un autre tenant (pas de révélation d'existence).

### Décider

`POST /internal/ai/proposals/{id}/decision`

```json
{
  "decision": "approve",
  "reason": "Validé par le comité produit."
}
```

- `decision` : `approve` ou `reject`.
- `reason` : 10 à 1000 caractères.
- `200` : décision enregistrée, `published: false`, `publication: forbidden`.
- `400` : décision inconnue, motif absent ou hors bornes.
- `404` : proposition hors tenant.
- `409` : une décision a déjà été prise.

Aucune route de publication n'existe ; `POST /internal/ai/proposals/{id}/publish` renvoie `404`.
