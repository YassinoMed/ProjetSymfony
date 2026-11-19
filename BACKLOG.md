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
- [ ] Définir interface fournisseur IA.
- [ ] Ajouter Agent Architecte.
- [ ] Ajouter Agent Builder.
- [ ] Ajouter Agent Auditeur.
- [ ] Ajouter Agent Gouverneur.
- [ ] Ajouter Agent Déployeur.
- [ ] Orchestrer avec Messenger.
- [ ] Ajouter approbations humaines.
- [ ] Ajouter audit et idempotence.
- [ ] Ajouter Mercure pour progression.

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
