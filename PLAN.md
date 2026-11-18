# PLAN — Plateforme Symfony auto-évolutive

## 1. Vision
Permettre à un utilisateur autorisé de décrire une fonctionnalité en langage naturel, puis de la concevoir, générer, valider, publier et faire évoluer sans modifier directement le Core Symfony.

## 2. Périmètre initial
1. Authentification et gestion des utilisateurs.
2. Multi-tenant et isolation des données.
3. Catalogue des fonctionnalités.
4. Moteur de métadonnées JSON Schema.
5. Générateur de formulaires et vues dynamiques.
6. CRUD générique avec validation serveur.
7. Workflows configurables.
8. Orchestration asynchrone des agents IA.
9. Audit, versions, approbations et rollback.
10. Préparation de la sandbox pour la génération PHP avancée.

## 3. Hors périmètre du MVP
- Exécution de PHP généré dans le processus web principal.
- Modification autonome du Core, des fichiers de configuration de sécurité ou des secrets.
- Déploiement global sans homologation.
- Garantie de 100 utilisateurs sans benchmark.
- Exécution de commandes système arbitraires fournies par un LLM.

## 4. Principes directeurs
- Audit avant modification.
- Core immuable pour les agents.
- Métadonnées d'abord, code ensuite.
- Toutes les actions sensibles sont autorisées par des politiques déterministes.
- Validation serveur obligatoire, même si l'interface valide déjà.
- Isolation tenant vérifiée à chaque accès.
- Toute version publiée est immuable et traçable.
- Les tâches IA sont asynchrones, idempotentes et observables.
- Déploiement progressif avec rollback.

## 5. Indicateurs de réussite
- Un tenant ne peut jamais lire ou modifier les données d'un autre tenant.
- Une fonctionnalité JSON valide peut être créée, prévisualisée, testée et activée.
- Une version publiée peut être désactivée ou remplacée par une version antérieure.
- Une panne du fournisseur IA n'interrompt pas les fonctions métier classiques.
- Les tests de charge à 100 utilisateurs atteignent les SLO définis dans `REQUIREMENTS.md`.
- Aucun code généré n'est exécuté dans le processus PHP-FPM principal.

## 6. Livrables
- Dépôt Symfony initialisé (monorepo).
- Compose de développement.
- Migrations et modèle multi-tenant.
- Moteur dynamique.
- Orchestrateur d'agents IA.
- Pipeline de validation.
- Environnement de préproduction.
- Rapport d'homologation et de charge.
- Dossier d'architecture technique complet (Architecture Blueprint).

## 7. Plan de développement recommandé (9 phases)

| Phase | Travail | Livrable |
|---|---|---|
| 1 | Infrastructure et Core Symfony | Core fonctionnel |
| 2 | Multi-tenant et sécurité | Isolation complète |
| 3 | Moteur dynamique | CRUD et workflows générés |
| 4 | AI Orchestrator | Agents IA opérationnels |
| 5 | Generation Service | Génération JSON et PHP |
| 6 | Sandbox Runner | Tests isolés |
| 7 | Messaging et Mercure | Communication asynchrone |
| 8 | Catalogue global | Publication et réutilisation |
| 9 | Cloud et homologation | Production validée |

### Critères AUTO-PASS pour chaque phase

Chaque phase doit produire :
- Un audit initial.
- Une liste des fichiers créés et modifiés.
- Des tests unitaires et d'intégration.
- Des tests de sécurité.
- Des preuves reproductibles.
- Un rapport de performance si nécessaire.
- Un verdict `PASS`, `PASS_WITH_RESERVATIONS` ou `FAIL`.

Aucun déploiement automatique en production si les contrôles obligatoires échouent.

Ne développe pas tous les microservices en même temps. Commence par le Core Platform et le moteur dynamique, puis construis l'Orchestrateur IA et la Sandbox séparément.
