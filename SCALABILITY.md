# SCALABILITY — Dimensionnement, montée en charge et isolation

## 1. Configuration initiale recommandée

Cible : 100 utilisateurs simultanés. 24 vCPU / 64 Go RAM cumulés indicatifs.

| Nœud | CPU | RAM | Rôle |
|---|---:|---:|---|
| Application + Gateway | 8 | 16 Go | Core Platform + API Gateway + Frontend |
| PostgreSQL | 8 | 32 Go | Bases Core et AI (bases et utilisateurs distincts, même serveur au départ) |
| IA + génération + sandbox | 8 | 16 Go | AI Orchestrator, Generation Service, Sandbox Runner |

Stockage objet séparé. Marge pour les pics. Sandbox avec quotas stricts (CPU, mémoire, disque, durée, réseau désactivé par défaut).

## 2. Configuration évoluée (après benchmark)

| Nœud | CPU | RAM |
|---|---:|---:|
| Application | 8 | 16 Go |
| PostgreSQL | 8 | 32 Go |
| IA Workers | 8 | 16 Go |
| Sandbox | 8 | 16 Go |
| Messaging / services auxiliaires | 4 | 8 Go |

Cette configuration est plus isolée et plus facile à faire évoluer indépendamment.

## 3. Règles de montée en charge

- Chaque service est dimensionnable indépendamment (ajout de VMs ou replicas).
- PHP-FPM (Core) horizontalement réplicable.
- Workers Messenger scalables séparément.
- RabbitMQ durable et supervisé.
- Redis non utilisé comme unique stockage durable des tâches.
- Fichiers dans un stockage objet, pas sur le disque des conteneurs/VMs applicatives.
- PostgreSQL avec sauvegardes, supervision et stratégie HA selon budget.

## 4. Isolation et quotas

- Sandbox : limites CPU, mémoire, disque et durée ; utilisateur non-root ; réseau désactivé par défaut.
- Tâches IA : concurrence bornée (5 à 10 générations concurrentes au départ).
- Files RabbitMQ : monitoring de la profondeur et alertes sur dead-letter queue.
- PostgreSQL : alertes connexions, locks, réplication, espace disque.

## 5. GPU

Non obligatoire en production avec des API IA externes. Pour Ollama en production, prévoir une machine GPU dédiée et chiffrer son coût séparément. Le Mac M2 sert au développement/test uniquement.

## 6. Kubernetes

Non recommandé au démarrage. Devenir pertinent lorsque l'orchestration multi-nœuds, les déploiements progressifs et la montée en charge importante l'exigent.
