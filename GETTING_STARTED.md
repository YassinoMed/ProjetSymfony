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

## 9. Prochaines étapes (Phase 1)

1. Entités `Identity` (User, Role) et `Tenant` (Tenant, Membership).
2. `TenantContext` injecté par requête.
3. Migrations Doctrine versionnées.
4. Authentification + RBAC + voters.
5. Journal d'audit append-only.
6. Premiers tests d'isolation multi-tenant.

Voir `PHASES.md` pour les critères de sortie de chaque phase.
