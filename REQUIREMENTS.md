# REQUIREMENTS — Exigences du système

## 1. Contexte et objectifs
Plateforme Symfony multi-tenant auto-évolutive. Cible de départ : 100 utilisateurs simultanés, hébergement hybride, IA hybride.

## 2. Acteurs
- Visiteur : accès aux pages publiques autorisées.
- Utilisateur tenant : utilise les fonctionnalités activées pour son espace.
- Administrateur tenant : configure son espace et gère ses utilisateurs.
- Administrateur plateforme : gouverne le catalogue global.
- Auditeur : consulte les preuves et historiques selon ses droits.
- Agent IA : propose des changements via outils contrôlés, sans privilèges implicites.

## 3. Exigences fonctionnelles

### FR-001 — Authentification
Le système doit authentifier les utilisateurs et gérer les sessions de manière sécurisée.

### FR-002 — Multi-tenant
Chaque donnée métier doit être associée à un tenant ou explicitement marquée globale. L'accès doit être filtré côté serveur.

### FR-003 — Demande en langage naturel
Un utilisateur autorisé doit pouvoir soumettre une demande de fonctionnalité. La demande est enregistrée avec un identifiant, un auteur, un tenant, un statut et un horodatage.

### FR-004 — Analyse architecturale
L'Agent Architecte doit analyser le besoin, rechercher les capacités existantes et produire une proposition structurée.

### FR-005 — Validation de schéma
Toute définition dynamique doit respecter un JSON Schema versionné et une liste blanche de types, opérateurs et composants.

### FR-006 — Formulaires dynamiques
Le moteur doit générer des formulaires à partir de métadonnées validées, avec validation serveur.

### FR-007 — Données dynamiques
Le moteur doit permettre de créer, lire, modifier et supprimer les enregistrements autorisés. Les règles d'accès s'appliquent au niveau serveur.

### FR-008 — Workflows
Les workflows doivent définir des états, transitions, gardes et rôles autorisés. Les gardes sont évaluées par du code déterministe approuvé, pas par du PHP arbitraire fourni par l'IA.

### FR-009 — Versionnement
Chaque modification de définition crée une nouvelle version. Une version publiée n'est pas modifiée en place.

### FR-010 — Approbation
Les changements à risque élevé, les publications globales et les changements de code nécessitent une approbation humaine.

### FR-011 — Orchestration asynchrone
Les tâches IA sont exécutées via Messenger et un transport durable. Elles disposent de délais, retries bornés, gestion d'échec et idempotence.

### FR-012 — Audit
Les actions sensibles sont enregistrées dans un journal append-only avec acteur, tenant, action, objet, horodatage et empreinte de chaîne.

### FR-013 — Publication
Une fonctionnalité n'est activée qu'après validation, autorisation et création d'un événement de publication.

### FR-014 — Temps réel
L'interface peut recevoir l'état d'avancement via Mercure, sans exposer de données d'un autre tenant.

### FR-015 — Fournisseurs IA
Le système doit pouvoir basculer entre fournisseur local et externe via une interface commune.

### FR-016 — Catalogue global
Une fonctionnalité tenant peut être proposée au catalogue global après revue, tests de compatibilité, approbation et suppression des données propres au tenant.

### FR-017 — Génération de code avancée
Si nécessaire, le code PHP est produit dans un workspace isolé, analysé et testé avant promotion. Le serveur web ne l'exécute jamais directement depuis un dossier d'upload.

### FR-018 — Rollback
Le système doit pouvoir désactiver une version et réactiver une version antérieure compatible.

## 4. Exigences non fonctionnelles

### NFR-001 — Performance
Cible initiale à vérifier : API métier P95 < 500 ms sous le profil de charge convenu, hors opérations IA longues.

### NFR-002 — Charge
Scénario de référence : 100 utilisateurs virtuels simultanés avec parcours réalistes. Les tâches IA sont testées séparément avec concurrence bornée.

### NFR-003 — Disponibilité
Objectif initial de disponibilité 99,9 %, à confirmer selon budget et architecture de redondance.

### NFR-004 — Sécurité
RBAC, isolation tenant, protection CSRF, validation, rate limiting, gestion de secrets, chiffrement TLS et scans des dépendances.

### NFR-005 — Résilience
La panne IA ne doit pas interrompre les fonctions métier non IA. Les messages échoués doivent être traçables et récupérables.

### NFR-006 — Observabilité
Logs structurés sans secrets ni données personnelles inutiles, métriques, traces et identifiants de corrélation.

### NFR-007 — Maintenabilité
Code modulaire, contrats explicites, tests automatisés, migrations versionnées et documentation à jour.

### NFR-008 — Portabilité
Environnements reproductibles via conteneurs et configuration externalisée.

## 5. Critères d'acceptation
- Aucune lecture inter-tenant dans les tests négatifs.
- Schémas invalides refusés.
- Actions interdites refusées côté serveur.
- Une demande IA ne peut pas publier sans autorisation.
- Les tâches sont idempotentes et récupérables.
- Rollback démontré en staging.
- Test de charge documenté avec métriques et limites.
