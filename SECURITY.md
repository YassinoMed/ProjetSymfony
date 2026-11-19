# SECURITY — Sécurité, isolation et gouvernance

## 1. Principes
- Zero trust entre composants.
- Moindre privilège.
- Core protégé et non modifiable par les agents.
- Défense en profondeur.
- Séparation des environnements.
- Approbation humaine pour actions à impact élevé.
- Traçabilité des décisions et publications.

## 2. Isolation multi-tenant

### Résolution du tenant
- Contexte tenant déterminé côté serveur à partir de l'identité et de l'appartenance.
- Ne jamais faire confiance à un `tenant_id` fourni par le navigateur.
- Ordre de résolution : en-tête `X-Tenant-Id`, puis session, puis unique adhésion active.
- L'en-tête est un **sélecteur**, pas une autorisation : l'adhésion est revérifiée en base à chaque requête.
- En-tête malformé → `400` ; tenant inactif ou adhésion absente → `403`.

### Filtre Doctrine
- `App\Tenant\TenantOwnedInterface` marque les entités cloisonnées (`getTenant(): ?Tenant`).
- `App\Tenant\TenantFilter` (`tenant_filter`) ajoute `alias.tenant_id = <tenant courant>` à toute requête DQL/SQL portant sur ces entités.
- Le filtre est **fail-closed** : activé sans tenant, il applique `1 = 0` au lieu de laisser passer les lignes.
- `TenantContextListener` désactive le filtre au début de chaque requête puis l'active uniquement après vérification serveur de l'adhésion. Cela évite toute fuite entre deux requêtes en mode worker.
- Toute nouvelle entité métier doit implémenter `TenantOwnedInterface`, sinon elle échappe au filtre : c'est un point de revue obligatoire.

### Accès croisés
- Les recherches par identifiant combinent toujours l'identifiant **et** le tenant (`findOneByIdForTenant`).
- Une adhésion appartenant à un autre tenant renvoie `404`, jamais `403`, pour ne pas révéler son existence.
- Tests d'accès croisé : `tests/Integration/TenantIsolationTest.php` et `tests/Controller/MembershipSecurityTest.php`.

### Défenses complémentaires
- PostgreSQL RLS reste prévu comme défense supplémentaire, pas comme remplacement des contrôles applicatifs (non activé en Phase 2).
- Cache, fichiers, exports, événements Mercure et index vectoriels devront être cloisonnés par tenant dans les phases suivantes.

## 3. Agents IA
- Aucun accès shell général.
- Aucun accès root.
- Outils allowlistés avec schémas d'entrée/sortie stricts.
- Autorisation déterministe avant chaque action.
- Les sorties du modèle sont des données non fiables.
- Validation JSON Schema et limites de taille.
- Protection contre prompt injection dans les documents RAG.
- Secrets jamais transmis au modèle.
- Quotas, timeouts, retries bornés et journalisation.

## 4. Sandbox de code
- Conteneur non privilégié.
- Utilisateur non-root.
- Filesystem éphémère et en lecture seule autant que possible.
- Pas de socket Docker monté.
- Réseau sortant bloqué par défaut.
- CPU, RAM, disque, processus et durée limités.
- Images signées et mises à jour.
- Dépendances verrouillées et scannées.
- Pas de secrets de production.
- Artefacts promus uniquement après tests et approbation.

## 5. Audit

### Modèle
Chaque entrée enregistre : acteur, tenant, action, ressource, résultat, contexte, adresse IP, user-agent, `correlation_id`, empreinte précédente et empreinte courante.

- Entité : `App\Entity\AuditLog`, table `audit_logs`.
- Chaîne **par tenant** : `previous_hash` référence l'empreinte de l'entrée précédente, `hash` couvre l'ensemble des champs.
- Écriture sérialisée par un verrou `SELECT ... FOR UPDATE` sur la ligne du tenant, dans la même transaction que l'opération métier.
- `X-Request-Id` est repris s'il est sûr, sinon un UUID v7 est généré ; il est renvoyé dans la réponse et conservé dans l'entrée.

### Append-only
La migration `Version20261118220000` installe des triggers PostgreSQL :
- `UPDATE` et `DELETE` par ligne → exception `audit_logs est append-only : opération … interdite` ;
- `TRUNCATE` par statement → même exception.

Une chaîne SHA-256 seule ne garantit pas l'immutabilité si un attaquant contrôle la base. Ajouter des sauvegardes protégées, des permissions séparées et, si nécessaire, un ancrage externe signé.

### Consultation
- `GET /audit`, réservé à `TENANT_AUDIT` (owner, admin, auditeur).
- Pagination de 50 entrées, limitée par le limiteur `audit_view` (60/min/tenant/utilisateur).
- La consultation est elle-même auditée (`audit.view`).

## 6. Contrôles de sécurité

### Authentification et sessions
- Hachage des mots de passe via Symfony PasswordHasher.
- `login_throttling` : 5 tentatives/minute par couple email/IP.
- Cookies de session `HttpOnly`, `SameSite=Lax`, `Secure` automatique en HTTPS et forcé en production.

### CSRF et validation
- Formulaires Symfony avec jetons CSRF.
- Jetons stateless (`submit`, `authenticate`, `logout`, `remove_member`) validés par origine (en-tête `Origin`/`Referer` ou `Sec-Fetch-Site`).
- Validation serveur systématique (email, longueur, enum de rôle) avant toute politique d'accès.

### Politiques déterministes
`App\Security\MembershipPolicy` :
- owner/admin seulement pour gérer les membres ;
- un admin ne peut ni créer, ni promouvoir, ni modifier, ni retirer un owner ;
- seul un owner peut attribuer le rôle owner ;
- auto-modification et auto-retrait interdits ;
- dernier owner actif protégé contre la rétrogradation et le retrait.

Les mêmes règles sont appliquées côté contrôleur et côté rendu (défense en profondeur), et les refus sont audités.

### Rate limiting
- Mutations membres : `tenant_admin` — 20/minute/tenant/utilisateur.
- Audit : `audit_view` — 60/minute/tenant/utilisateur.
- Dépassement → `429` avec `Retry-After`.
- En production multi-instance, le stockage du limiteur doit être partagé (Redis) pour être global.

### Transport et en-têtes
- `infrastructure/nginx/tls.conf` : TLS 1.2/1.3, suites AEAD, session tickets désactivés, redirection HTTP → HTTPS, HSTS 1 an.
- `infrastructure/nginx/security-headers.conf` : CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`.
- La CSP autorise `'unsafe-inline'` pour les styles (feuille de style embarquée dans Twig) ; aucun script inline n'est autorisé.

### Secrets
- Aucun secret réel dans le dépôt ; `.env` est ignoré et `.env.example` ne contient que des valeurs de développement.
- Les certificats TLS sont générés localement et ignorés (`infrastructure/nginx/certs/*.pem`).
- En production : injection par le gestionnaire de secrets de la plateforme, rotation documentée, `APP_SECRET` non vide.

### Dépendances et CI
- GitHub Actions exécute `composer audit` pour les quatre services et `npm audit --audit-level=high` pour le frontend.
- Dependabot surveille Composer, npm, GitHub Actions et Docker (mises à jour hebdomadaires groupées).
- `composer.lock` et `package-lock.json` sont versionnés pour des installations reproductibles.

### Contrôles restant à industrialiser
- SAST/DAST automatisés et scan d'images conteneur.
- RLS PostgreSQL et ancrage externe de l'audit.
- Rotation automatisée des secrets et certificats.
- Tests de charge et revue sécurité indépendante (Phase 9).
