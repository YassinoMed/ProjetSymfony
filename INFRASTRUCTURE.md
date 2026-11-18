# INFRASTRUCTURE — Matériel, services et environnements

## 1. Hypothèses
- 100 utilisateurs simultanés visés.
- Charge web, formulaires et consultation majoritairement classique.
- 5 à 10 tâches de génération IA concurrentes au départ, sous réserve de quotas.
- IA hybride : fournisseur cloud en production, Ollama local pour développement/expérimentation.
- Mac M2 pour développement, cloud pour staging et production.

## 2. Dimensionnement initial proposé
Ce dimensionnement est une hypothèse de départ, à confirmer par benchmark. Les ressources ne sont pas une garantie de capacité.
Dans l'architecture micro-services retenue, chaque service est provisionné sur sa propre machine VM dédiée.

| Service | VM dédiée | vCPU | RAM | Disque indicatif |
|---|---:|---:|---:|---:|
| Reverse proxy | Oui | 1 | 1 Go | 5 Go |
| Micro-service Core (Symfony/PHP-FPM) | Oui | 8 | 16 Go | 30 Go |
| PostgreSQL | Oui | 8 | 32 Go | 300 Go NVMe |
| Redis | Oui | 2 | 2 Go | 5 Go |
| RabbitMQ | Oui | 2 | 4 Go | 20 Go |
| Mercure | Oui | 1 | 1 Go | 5 Go |
| Workers IA | Oui | 4 | 8 Go | 20 Go |
| Sandbox | Oui | 8 | 16 Go | 100 Go éphémères |

Total théorique : 34 vCPU et 80 Go RAM si tous les services sont provisionnés sur des VM séparées. La base et la sandbox doivent rester isolées sur des VM distinctes en production.

## 3. Environnement local Mac
- Docker Desktop + Compose pour le développement local.
- PHP/Composer local pour outils ponctuels, runtime applicatif conteneurisé recommandé.
- PostgreSQL avec pgvector.
- Redis.
- RabbitMQ.
- Mercure.
- Ollama facultatif.
- Tests PHPUnit/PHPStan.

Le Mac ne doit pas être un point de dépendance de la production. La cible de production reste des VM dédiées par service.

## 4. Environnements
- Local : `localhost` (Docker Compose).
- Staging : domaine dédié, données synthétiques, VMs dédiées par service.
- Production : domaines publics derrière TLS, VMs dédiées par service.
- Sandbox : réseau isolé, aucun accès direct aux données de production.

## 5. Services transverses
- Stockage objet compatible S3 pour fichiers et artefacts.
- Gestionnaire de secrets.
- Monitoring et alerting.
- Centralisation des logs.
- Sauvegardes hors site.
- DNS, TLS et protection anti-abus.

## 6. Machines VM et déploiement
Chaque micro-service tourne sur sa propre VM. Compose est adapté au développement et à un premier staging. Pour la production, provisionner des VM dédiées par service, avec mise à jour et montée en charge indépendantes. Ne pas exposer PostgreSQL, Redis ou RabbitMQ sur Internet.

## 7. IA locale
Ollama sur Mac est un endpoint de développement uniquement. Pour une IA locale de production, prévoir une machine GPU dédiée et chiffrer son coût séparément. Aucun GPU n'est obligatoire si les modèles cloud sont utilisés.
