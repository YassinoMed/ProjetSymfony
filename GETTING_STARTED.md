# GETTING_STARTED — Démarrage du monorepo

## 1. Prérequis

- PHP 8.5+ avec `ext-intl`, `ext-pdo_pgsql`, `ext-zip`
- Composer 2
- Symfony CLI 5+
- Docker Desktop (ou Docker Engine + Compose)
- Node.js 22+ (frontend)

## 2. Structure du dépôt

```
.
├── services/
│   ├── core-platform/        # Symfony 8 — cœur métier
│   ├── ai-orchestrator/      # Symfony 8 — agents IA
│   ├── generation-service/   # Symfony 8 — génération JSON/PHP
│   └── sandbox-runner/       # PHP CLI + Docker isolé
├── frontend/web-app/         # Vite + Tailwind CSS
├── infrastructure/           # PostgreSQL, Redis, nginx
├── contracts/                # événements, API, JSON Schemas
├── docker-compose.yml
├── compose.staging.yaml
└── *.md                      # dossier de conception
```

## 3. Démarrer l'infrastructure

```bash
docker compose up -d
```

| Service | Adresse | Identifiants |
|---|---|---|
| PostgreSQL | `localhost:5432` | `aep` / `aep_dev_password` |
| Redis | `localhost:6379` | — |
| RabbitMQ | `localhost:5672` — UI `http://localhost:15672` | `aep` / `aep_dev_password` |
| Mercure | `http://localhost:8080/.well-known/mercure` | JWT de dev |

Bases créées automatiquement au premier démarrage : `core`, `ai`, `generation` (extension `pgvector` activée).

Si le volume PostgreSQL existe déjà sans ces bases :

```bash
docker compose exec postgres psql -U aep -d core \
  -c "CREATE DATABASE ai;" -c "CREATE DATABASE generation;"
```

## 4. Lancer le Core Platform

```bash
cd services/core-platform
composer install
php bin/console messenger:setup-transports
symfony serve -d        # http://localhost:8000
```

Vérification rapide :

```bash
php bin/console about
php bin/console lint:container
```

## 5. Lancer les workers

```bash
cd services/ai-orchestrator
php bin/console messenger:setup-transports
php bin/console messenger:consume async -vv

cd services/generation-service
php bin/console messenger:setup-transports
php bin/console messenger:consume async -vv
```

**Note transport :** en développement, le transport Messenger utilise Doctrine (chaque service a sa propre base) pour rester exécutable sans `ext-amqp`. RabbitMQ est déjà provisionné ; le passage à AMQP se fera en Phase 7 :

```bash
composer require symfony/amqp-messenger
# puis MESSENGER_TRANSPORT_DSN=amqp://aep:aep_dev_password@127.0.0.1:5672/%2f/messages
```

## 6. Lancer le frontend

```bash
cd frontend/web-app
npm install
npm run dev             # http://localhost:3000
```

## 7. Tout lancer avec Docker

```bash
docker compose --profile app up --build
```

- Core + nginx : `http://localhost:8000`
- Mercure : `http://localhost:8080`
- Les workers IA et génération démarrent en mode `messenger:consume`.

Le Sandbox Runner est sur un réseau Docker interne, sans accès aux bases ni à Internet.

## 8. Tests

```bash
cd services/core-platform && php bin/phpunit
cd services/ai-orchestrator && php bin/phpunit
cd services/generation-service && php bin/phpunit
```

## 9. Créer un utilisateur et un tenant

```bash
cd services/core-platform

# Utilisateur simple
php bin/console app:create-user alice@example.com

# Administrateur de plateforme
php bin/console app:create-user admin@example.com --platform-admin

# Tenant rattaché à un propriétaire
php bin/console app:create-tenant "Acme Corp" acme-corp --owner=alice@example.com
```

Le mot de passe est demandé de façon masquée s'il n'est pas fourni en second argument.

## 10. Contexte multi-tenant

Le tenant actif est résolu à chaque requête par `TenantContextListener` :

1. en-tête `X-Tenant-Id` (UUID) — vérifié côté serveur ;
2. tenant stocké en session ;
3. unique adhésion active de l'utilisateur.

Un en-tête explicite invalide renvoie `400`, un tenant inactif ou une adhésion absente renvoie `403`.
Le voter `TenantVoter` expose les attributs `TENANT_VIEW`, `TENANT_MANAGE`, `TENANT_AUDIT` et `TENANT_OWNER`.

```bash
curl -H "X-Tenant-Id: <uuid-du-tenant>" http://localhost:8000/
```

## 11. Prochaines étapes (Phase 1 / Phase 2)

1. Journal d'audit append-only.
2. Health checks et logs structurés.
3. CI (lint, tests, analyse de vulnérabilités).
4. Tests d'intégration PostgreSQL et tests négatifs d'isolation.
5. Rate limiting et durcissement CSRF sur les futures API.

Voir `PHASES.md` pour les critères de sortie de chaque phase.
