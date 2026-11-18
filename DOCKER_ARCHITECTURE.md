# DOCKER_ARCHITECTURE — Conteneurisation et déploiement par service

## 1. Principe

Chaque microservice possède son propre `Dockerfile`, ses dépendances et ses tests au sein d'un monorepo. Un service peut être construit, testé, versionné et déployé séparément.

Structure suggérée :
```
services/
├── core-platform/
│   ├── Dockerfile
│   ├── composer.json
│   └── ...
├── ai-orchestrator/
│   ├── Dockerfile
│   └── ...
├── generation-service/
│   ├── Dockerfile
│   └── ...
└── sandbox-runner/
    ├── Dockerfile
    └── ...
infrastructure/
├── docker/
│   ├── postgres/
│   ├── redis/
│   ├── rabbitmq/
│   └── mercure/
├── nginx/
└── monitoring/
docker-compose.yml
compose.staging.yaml
```

## 2. Environnement local (Docker Compose)

`docker-compose.yml` orchestre :
- Core Platform (PHP-FPM + nginx)
- AI Orchestrator
- Generation Service
- Sandbox Runner
- PostgreSQL 17 + pgvector
- Redis
- RabbitMQ
- Mercure
- (optionnel) Ollama

Le runtime applicatif est conteneurisé. PHP/Composer local est réservé aux outils ponctuels.

## 3. Production

Pour la production, utiliser des VM dédiées par groupe de services ou des services managés. Kubernetes devient pertinent uniquement lorsqu'une orchestration multi-nœuds avancée, des déploiements progressifs et une montée en charge importante sont nécessaires.

- Artefacts immuables identifiés par digest.
- Configuration externalisée (variables d'environnement, gestionnaire de secrets).
- Ne pas exposer PostgreSQL, Redis ou RabbitMQ sur Internet.
- Sandbox Runner sur une image non privilégiée, réseau sortant bloqué par défaut.

## 4. Règles Docker

- Image de base légère (ex. Alpine ou Debian slim).
- Utilisateur non-root dans le conteneur applicatif.
- Multi-stage build pour réduire la taille de l'image finale.
- Pas de secrets dans l'image (variables d'environnement ou secrets montés).
- Health check sur chaque service.
- Limites de ressources (CPU, mémoire) appliquées à la sandbox.
