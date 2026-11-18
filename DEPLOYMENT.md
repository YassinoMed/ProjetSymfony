# DEPLOYMENT — Déploiement et exploitation

## 1. Environnements
- Local : Mac M2 et Docker Compose.
- CI : tests, analyse statique, scans et construction d'images.
- Staging : environnement isolé avec données synthétiques.
- Production : VMs ou services managés, TLS, sauvegardes et supervision.

## 2. Pipeline CI/CD
1. Vérification format et syntaxe.
2. Composer validate et audit des dépendances.
3. Tests unitaires et intégration.
4. PHPStan.
5. Tests de sécurité.
6. Construction image immuable par service.
7. Scan d'image (Trivy).
8. Déploiement staging.
9. Tests smoke et non-régression.
10. Approbation.
11. Déploiement progressif production.
12. Vérification post-déploiement.

## 3. Déploiement applicatif
- Chaque service est déployé, testé, versionné et mis à jour indépendamment (monorepo).
- Artefacts immuables et identifiés par digest.
- Configuration externalisée.
- Migrations compatibles avec déploiement progressif.
- Sauvegarde avant migration risquée.
- Warmup cache dans la nouvelle release.
- Redémarrage contrôlé des workers.
- Vérification health checks.
- Rollback applicatif documenté.

## 4. Base de données
- Sauvegardes automatisées et chiffrées.
- Rétention définie.
- Copie hors site.
- Tests périodiques de restauration.
- Migrations expansives puis contractives pour éviter les interruptions.
- Ne jamais exécuter de reset ou drop de base en production comme procédure standard.
- Transactional Outbox pour la cohérence entre l'enregistrement en base et l'envoi des événements RabbitMQ.

## 5. Exploitation
- Monitoring disponibilité, latence, erreurs et saturation.
- Alertes files Messenger et dead-letter queue.
- Alertes PostgreSQL connexions, locks, réplication et espace disque.
- Rotation des logs.
- Gestion des incidents et post-mortems.
- Procédure de désactivation d'une fonctionnalité défectueuse.

## 6. Secrets
- Aucun secret dans Git.
- Variables locales dans `.env.local` non commité.
- Secrets de production dans un gestionnaire dédié.
- Rotation et révocation documentées.
