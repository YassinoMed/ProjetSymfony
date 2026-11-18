# ARCHITECTURE — Architecture logique et technique

## 1. Style

Micro-services : chaque domaine métier et chaque service technique (PostgreSQL, Redis, RabbitMQ, Mercure, sandbox) tourne sur sa propre machine VM dédiée. Chaque micro-service est déployé, isolé, dimensionné et mis à jour indépendamment. Les VM communiquent via un réseau privé (API REST, messages et événements). Cette approche isole les pannes, simplifie le dimensionnement par service et renforce la sécurité par segmentation.

## 2. Domaines
- Identity & Access : utilisateurs, rôles, permissions.
- Tenant Management : tenants, adhésions, contexte tenant.
- Feature Registry : catalogue, versions, activation.
- Dynamic Engine : schémas, formulaires, vues, données et workflows.
- AI Orchestration : demandes, agents, fournisseurs et outils.
- Governance : approbations, politiques, publications.
- Audit : événements immuables et preuves d'intégrité.
- Sandbox: génération et validation de code isolé.

## 3. Frontières de confiance
1. Navigateur → reverse proxy : TLS, rate limiting.
2. Reverse proxy → micro-service Core : réseau privé.
3. Micro-service → base/queues : identités de service à privilèges minimaux.
4. Orchestrateur → fournisseur IA : données minimisées, secrets protégés.
5. Builder → sandbox : artefacts et tâches bornés, aucun accès privilégié.
6. Sandbox → staging : promotion d'artefacts validés uniquement.

## 4. Règles de conception
- Le Core ne dépend pas des modules dynamiques.
- Les modules dynamiques dépendent de contrats stables.
- Aucun `eval()` et aucune exécution de PHP dans PHP-FPM.
- Les définitions dynamiques sont validées avant stockage et avant activation.
- Les permissions sont évaluées côté serveur.
- Les requêtes de données dynamiques sont construites avec des identifiants autorisés et des paramètres liés.
- Les événements métier sont publiés après commit.
- Les traitements asynchrones sont idempotents.

## 5. Génération dynamique
Pipeline : demande → proposition JSON → validation de schéma → revue → version candidate → tests → approbation → activation tenant.

Les formulaires et vues sont rendus par des composants génériques. Les types de champs, filtres, règles et actions sont limités à un catalogue explicite.

## 6. Génération PHP
Réservée aux extensions non exprimables par le moteur de métadonnées. Le code est produit dans une sandbox éphémère, testé, signé et stocké comme artefact versionné. L'exécution éventuelle se fait dans un runtime séparé, avec permissions minimales.

## 7. Disponibilité et évolutivité
- Chaque micro-service sur sa propre VM, dimensionnable indépendamment.
- PHP-FPM sur sa VM, réplicable par ajout de VMs.
- Workers Messenger sur VM dédiée(s), scalables séparément.
- PostgreSQL sur VM dédiée avec sauvegardes, supervision et stratégie HA selon budget.
- Redis sur VM dédiée, non utilisé comme unique stockage durable des tâches.
- RabbitMQ sur VM dédiée, durable et supervisé.
- Mercure sur VM dédiée.
- Sandbox sur VM dédiée et isolée, sans accès privilégié.
- Fichiers dans un stockage objet, pas sur le disque des VMs applicatives.
