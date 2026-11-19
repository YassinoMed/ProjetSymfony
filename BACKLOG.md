# BACKLOG — Backlog initial priorisé

## P0 — Bloquant
- [x] Initialiser dépôt Symfony et conventions.
- [x] Créer Docker Compose local.
- [x] Configurer PostgreSQL, Redis et RabbitMQ.
- [x] Implémenter authentification et rôles.
- [x] Implémenter le contexte tenant côté serveur.
- [x] Ajouter tests d'isolation tenant.
- [x] Créer migrations de base.
- [x] Ajouter CI minimale.

## P1 — MVP dynamique
- [x] Définir JSON Schema versionné.
- [x] Implémenter validation stricte.
- [x] Construire formulaire dynamique.
- [x] Construire liste, détail et filtres dynamiques.
- [x] Implémenter CRUD générique.
- [x] Ajouter workflow configurable.
- [x] Ajouter Feature Registry.
- [x] Ajouter versionnement des définitions.
- [ ] Ajouter activation/désactivation d'une fonctionnalité par tenant.

## P2 — IA et gouvernance
- [x] Définir interface fournisseur IA.
- [x] Ajouter Agent Architecte.
- [x] Ajouter Agent Builder.
- [x] Ajouter Agent Auditeur.
- [x] Ajouter Agent Gouverneur.
- [x] Ajouter Agent Déployeur (préflight seulement).
- [x] Orchestrer avec Messenger.
- [x] Ajouter approbations humaines.
- [x] Ajouter audit et idempotence.
- [ ] Ajouter Mercure pour progression (reporté en Phase 7).
- [ ] Ajouter un rate limiter partagé sur l'API interne AI.

## P3 — Industrialisation
- [ ] Construire sandbox isolée.
- [ ] Intégrer nikic/php-parser.
- [ ] Ajouter PHPStan et PHPUnit dans sandbox.
- [x] Ajouter scans des dépendances (Composer, npm, Dependabot).
- [ ] Ajouter scans des images conteneur.
- [ ] Ajouter staging et promotion d'artefacts.
- [ ] Ajouter rollback.
- [ ] Ajouter observabilité.
- [ ] Réaliser test de charge 100 utilisateurs.
- [ ] Réaliser audit de sécurité.
- [ ] Documenter exploitation et reprise après sinistre.
