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
cd services/core-platform

# Suite complète (les tests d'intégration nécessitent PostgreSQL)
php bin/phpunit

# Uniquement les tests unitaires (sans base)
php bin/phpunit tests/Tenant tests/Security tests/Audit
```

Les tests d'intégration et fonctionnels s'exécutent sur une base PostgreSQL de test. En local, le plus simple est un conteneur dédié :

```bash
docker run -d --name aep-phase2-pg \
  -e POSTGRES_USER=aep -e POSTGRES_PASSWORD=aep_dev_password -e POSTGRES_DB=core \
  -p 55432:5432 postgres:17

docker exec aep-phase2-pg createdb -U aep core_test

export DATABASE_URL='postgresql://aep:aep_dev_password@127.0.0.1:55432/core?serverVersion=17&charset=utf8'
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

`when@test` ajoute le suffixe `_test` au nom de base : avec `DATABASE_URL` pointant sur `core`, les tests utilisent `core_test`. Si la base ou le schéma manque, les tests d'intégration sont marqués `skipped` avec un message explicite.

Les autres services :

```bash
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

Après vérification de l'adhésion, le listener active le filtre Doctrine `tenant_filter`. Toute entité implémentant `TenantOwnedInterface` est alors automatiquement restreinte au tenant courant. Le filtre échoue de manière fermée (`1 = 0`) s'il est activé sans tenant.

```bash
curl -H "X-Tenant-Id: <uuid-du-tenant>" http://localhost:8000/
```

## 11. Gestion des membres et journal d'audit

| Route | Méthode | Accès | Rôle |
|---|---|---|---|
| `/tenant/members` | GET | `TENANT_VIEW` | Liste des adhésions |
| `/tenant/members` | POST | `TENANT_MANAGE` | Ajout / réactivation |
| `/tenant/members/{id}/role` | POST | `TENANT_MANAGE` | Changement de rôle |
| `/tenant/members/{id}/remove` | POST | `TENANT_MANAGE` | Retrait (suspension) |
| `/audit` | GET | `TENANT_AUDIT` | Journal paginé |

Règles serveur :

- owner et admin peuvent gérer les membres ; un admin ne touche jamais un owner ;
- seul un owner attribue le rôle owner ;
- personne ne peut modifier ou retirer sa propre adhésion ;
- le dernier owner actif ne peut être ni rétrogradé ni retiré.

Les mutations sont limitées à 20/minute/tenant/utilisateur et la consultation de l'audit à 60/minute. Un dépassement renvoie `429`.

Le journal est append-only : chaîne SHA-256 par tenant et triggers PostgreSQL interdisant `UPDATE`, `DELETE` et `TRUNCATE`.

## 12. Moteur dynamique (Phase 3)

Une fonctionnalité CRUD se crée depuis l'interface ou via les routes génériques : **aucun fichier PHP n'est ajouté**. La définition JSON contient `schema`, `ui` et `workflow`, est validée par une allowlist stricte, puis publiée sous forme de version immuable. La validation des enregistrements utilise `opis/json-schema`.

### Routes

| Route | Méthode | Accès | Rôle |
|---|---|---|---|
| `/features` | GET | `TENANT_VIEW` | Liste des définitions |
| `/features/new` | GET/POST | owner, admin | Créer une définition (v1) |
| `/features/{key}/edit` | GET/POST | owner, admin | Publier une version ou modifier les métadonnées |
| `/features/{key}/records` | GET | `TENANT_VIEW` | Liste, recherche, filtres, tri, pagination |
| `/features/{key}/records/new` | GET/POST | owner, admin, member | Créer un enregistrement |
| `/features/{key}/records/{id}` | GET | `TENANT_VIEW` | Détail et transitions disponibles |
| `/features/{key}/records/{id}/edit` | GET/POST | owner, admin, member | Modifier |
| `/features/{key}/records/{id}/delete` | POST | owner, admin | Supprimer |
| `/features/{key}/records/{id}/transition` | POST | rôles déclarés | Appliquer une transition |

### Règles

- Le schéma accepte uniquement des primitives et des tableaux de primitives ; `$ref`, `pattern`, les objets imbriqués et les clés inconnues sont refusés.
- Chaque évolution du schéma, de l'interface ou du workflow crée une version immuable (`feature_definition_versions`) protégée par triggers PostgreSQL. Une modification de métadonnées seules ne crée pas de version.
- Les enregistrements stockent la version de schéma utilisée. Une modification les revalide contre la version courante et met à jour leur `schemaVersion`.
- Les filtres, tris et colonnes sont limités aux champs autorisés par le schéma ; les valeurs SQL sont paramétrées et aucun identifiant SQL ne vient du navigateur.
- Les transitions sont déclaratives et vérifiées côté serveur : état source, état cible et rôle autorisé.
- Les mutations sont limitées à 60/minute/tenant/utilisateur (`feature_write`).
- Les refus et chaque opération sont audités (`feature_definition.*`, `dynamic_record.*`).

### Tests dédiés

```bash
php bin/phpunit tests/Feature
php bin/phpunit tests/Integration/FeatureEngineIntegrationTest.php
php bin/phpunit tests/Controller/FeatureEngineHttpTest.php
```

Les deux derniers nécessitent PostgreSQL et les migrations Phase 3.

## 13. TLS en local

```bash
./infrastructure/nginx/generate-dev-cert.sh
docker compose --profile app --profile app-tls up -d --build
# https://localhost:8443 (certificat auto-signé)
```

`infrastructure/nginx/tls.conf` applique TLS 1.2/1.3, HSTS et la redirection HTTP → HTTPS. Les certificats (`infrastructure/nginx/certs/*.pem`) ne sont jamais commités.

## 14. CI et scans de dépendances

- `.github/workflows/ci.yml` : lint YAML/conteneur/Twig, migrations up/down, suite PHPUnit complète, `composer audit`, `npm audit`, build frontend.
- `.github/dependabot.yml` : mises à jour hebdomadaires Composer, npm, GitHub Actions et Docker.
- Les lockfiles (`composer.lock`, `package-lock.json`) sont versionnés.

## 15. Prochaines étapes (Phase 4)

1. Contrat de fournisseur IA et orchestration asynchrone.
2. Agents Architecte, Builder limité, Auditeur, Gouverneur et Déployeur.
3. File RabbitMQ, retries, timeouts et idempotence.
4. RAG avec filtrage des documents par tenant et permissions.
5. Fournisseurs cloud et Ollama local.

Voir `PHASES.md` pour les critères de sortie de chaque phase et `PHASE3_REPORT.md` pour les preuves de la phase courante.
