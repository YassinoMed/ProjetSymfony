# INFRASTRUCTURE — Matériel, services et environnements

## 1. Hypothèses
- 100 utilisateurs simultanés visés.
- Charge web, formulaires et consultation majoritairement classique.
- 5 à 10 tâches de génération IA concurrentes au départ, sous réserve de quotas.
- IA hybride : fournisseur cloud en production, Ollama local pour développement/expérimentation.
- Mac M2 pour développement, cloud pour staging et production.

## 2. Dimensionnement initial recommandé

Configuration initiale (24 vCPU / 64 Go RAM cumulés indicatifs) :

| Nœud | CPU | RAM | Rôle |
|---|---:|---:|---|
| Application + Gateway | 8 | 16 Go | Core Platform + API Gateway + Frontend |
| PostgreSQL | 8 | 32 Go | Bases Core et AI (bases et utilisateurs distincts, même serveur au départ) |
| IA + génération + sandbox | 8 | 16 Go | AI Orchestrator, Generation Service, Sandbox Runner |

Prévoir du stockage objet séparé et une marge pour les pics. La sandbox devra avoir des quotas stricts.

Configuration évoluée (après benchmark) :

| Nœud | CPU | RAM |
|---|---:|---:|
| Application | 8 | 16 Go |
| PostgreSQL | 8 | 32 Go |
| IA Workers | 8 | 16 Go |
| Sandbox | 8 | 16 Go |
| Messaging / services auxiliaires | 4 | 8 Go |

La deuxième configuration est plus isolée et plus facile à faire évoluer indépendamment.

**GPU :** non obligatoire en production avec des API IA externes. Pour Ollama en production, prévoir une machine GPU dédiée et chiffrer son coût séparément. Le Mac M2 sert à développer et tester les modèles locaux, mais ne doit pas être un point de dépendance de la production.

## 3. Environnement local Mac
- Docker Desktop + Compose.
- PHP/Composer local pour outils ponctuels, runtime applicatif conteneurisé recommandé.
- PostgreSQL 17 avec pgvector.
- Redis, RabbitMQ, Mercure.
- Ollama facultatif.
- Tests PHPUnit/PHPStan.

Le Mac ne doit pas être un point de dépendance de la production.

## 4. Environnements
- Local : `localhost` (Docker Compose).
- Staging : domaine dédié, données synthétiques.
- Production : domaines publics derrière TLS.
- Sandbox : réseau isolé, aucun accès direct aux données de production.

## 5. Services transverses
- Stockage objet compatible S3 pour fichiers et artefacts.
- Gestionnaire de secrets.
- Monitoring et alerting (Prometheus, Grafana, OpenTelemetry).
- Centralisation des logs.
- Sauvegardes hors site.
- DNS, TLS et protection anti-abus.

## 6. Conteneurisation et déploiement
- Docker Compose est adapté au développement et à un premier staging.
- Pour la production, utiliser des VM dédiées par groupe de services ou des services managés. Kubernetes devient pertinent uniquement lorsqu'une orchestration multi-nœuds avancée, des déploiements progressifs et une montée en charge importante sont nécessaires.
- Chaque service possède son propre Dockerfile, ses dépendances et ses tests (monorepo).
- Ne pas exposer PostgreSQL, Redis ou RabbitMQ sur Internet.

## 7. IA locale
Ollama sur Mac est un endpoint de développement uniquement. Aucun GPU n'est obligatoire si les modèles cloud sont utilisés.
