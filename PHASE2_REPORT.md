# PHASE 2 REPORT — Multi-tenant et sécurité

**Date :** 2026-11-18
**Périmètre :** Phase 2 de `PHASES.md` — isolation tenant, RBAC déterministe, audit append-only, CSRF/rate limiting, TLS/secrets, scans de dépendances.
**Base de départ :** commit `7d35ef5` (Phase 1), 24 tests / 67 assertions.

---

## 1. Audit initial

| Élément | État avant Phase 2 |
|---|---|
| Authentification, rôles, contexte tenant | Livrés en Phase 1 |
| Voter `TENANT_VIEW/MANAGE/AUDIT/OWNER` | Livré, sans écran de gestion |
| Isolation des requêtes | Aucun filtre automatique : dépendait de chaque requête |
| Journal d'audit | Entité absente ; aucune immutabilité |
| Gestion des membres | Absente |
| CSRF / validation | Formulaire de connexion seulement |
| Rate limiting | Absent |
| TLS / en-têtes de sécurité | HTTP seul, aucun en-tête |
| CI / scans de dépendances | Absents |
| Tests d'intégration PostgreSQL | Absents |

Écarts comblés : filtre Doctrine fail-closed, gestion complète des adhésions avec politiques, journal chaîné et inaltérable, limiteurs nommés, terminaison TLS, CI et Dependabot.

---

## 2. Contrôles livrés

### Isolation tenant
- `TenantOwnedInterface` et `TenantFilter` : contrainte `tenant_id = <tenant courant>` sur toute entité marquée.
- Échec fermé (`1 = 0`) si le filtre est activé sans tenant.
- `TenantContextListener` désactive le filtre au début de chaque requête et l'active après vérification serveur de l'adhésion (compatible mode worker).
- Recherches par identifiant toujours combinées avec le tenant ; `404` pour une adhésion d'un autre tenant.

### RBAC et politiques déterministes
- `MembershipPolicy` : owner/admin uniquement, admin sans accès aux owners, owner seul pour attribuer owner, auto-modification interdite, dernier owner protégé.
- Application côté contrôleur **et** côté rendu ; chaque refus est audité.

### Audit append-only
- `AuditLog` avec chaîne SHA-256 par tenant (`previous_hash` / `hash`).
- Écriture transactionnelle sérialisée par `SELECT ... FOR UPDATE` sur le tenant.
- Triggers PostgreSQL refusant `UPDATE`, `DELETE` et `TRUNCATE`.
- Corrélation `X-Request-Id` (repris si sûr, sinon UUID v7) conservée et renvoyée au client.

### CSRF, validation, rate limiting
- Formulaires Symfony avec jetons CSRF ; jetons stateless validés par origine.
- Validation email/rôle/longueur avant toute politique.
- `tenant_admin` : 20 mutations/min/tenant/utilisateur ; `audit_view` : 60 consultations/min ; `login_throttling` : 5 tentatives/min.
- Réponses `429` avec `Retry-After`.

### TLS, en-têtes, secrets
- `tls.conf` : TLS 1.2/1.3, suites AEAD, tickets désactivés, redirection HTTP → HTTPS, HSTS.
- En-têtes CSP, `nosniff`, `DENY`, `Referrer-Policy`, `Permissions-Policy`, `COOP`.
- Certificats et secrets hors dépôt ; script de certificat local ; `.env` ignoré.

### Rate limiting

```
php bin/phpunit --filter testAuditRateLimitReturns429AfterSixtyRequests
→ OK (1 test, 62 assertions)
→ 60 requêtes 200, 61e requête 429 avec Retry-After
```

### Dépendances
- `composer audit` et `npm audit` en CI ; Dependabot pour Composer, npm, Actions et Docker.
- `package-lock.json` généré et versionné.

---

## 3. Fichiers

### Créés

| Fichier | Rôle |
|---|---|
| `services/core-platform/src/Tenant/TenantOwnedInterface.php` | Marquage des entités cloisonnées |
| `services/core-platform/src/Tenant/TenantFilter.php` | Filtre SQL fail-closed |
| `services/core-platform/src/Tenant/TenantFilterManager.php` | Activation/désactivation par requête |
| `services/core-platform/src/Entity/AuditLog.php` | Entrée d'audit chaînée |
| `services/core-platform/src/Entity/Enum/AuditAction.php` | Actions auditées |
| `services/core-platform/src/Entity/Enum/AuditResult.php` | Résultats d'audit |
| `services/core-platform/src/Repository/AuditLogRepository.php` | Dernière empreinte, page, total |
| `services/core-platform/src/Audit/AuditLogger.php` | Écriture transactionnelle et chaîne |
| `services/core-platform/src/Http/CorrelationIdListener.php` | `X-Request-Id` |
| `services/core-platform/src/Security/MembershipPolicy.php` | Politiques membres |
| `services/core-platform/src/Controller/MembershipController.php` | Écran et mutations |
| `services/core-platform/src/Controller/AuditController.php` | Journal paginé |
| `services/core-platform/src/Form/AddMemberType.php` | Ajout/réactivation |
| `services/core-platform/src/Form/ChangeMemberRoleType.php` | Changement de rôle |
| `services/core-platform/src/Form/RemoveMemberType.php` | Retrait avec CSRF |
| `services/core-platform/migrations/Version20261118220000.php` | Table, index, FK, triggers |
| `services/core-platform/templates/tenant/members.html.twig` | Écran membres |
| `services/core-platform/templates/audit/index.html.twig` | Écran audit |
| `services/core-platform/tests/Tenant/TenantFilterTest.php` | Filtre |
| `services/core-platform/tests/Security/MembershipPolicyTest.php` | Matrice de politiques |
| `services/core-platform/tests/Audit/AuditLogTest.php` | Empreintes et chaîne |
| `services/core-platform/tests/Integration/TenantIsolationTest.php` | Isolation et triggers |
| `services/core-platform/tests/Controller/MembershipSecurityTest.php` | Parcours HTTP et refus |
| `infrastructure/nginx/tls.conf` | Terminaison TLS |
| `infrastructure/nginx/security-headers.conf` | En-têtes communs |
| `infrastructure/nginx/generate-dev-cert.sh` | Certificat local |
| `.github/workflows/ci.yml` | CI complète |
| `.github/dependabot.yml` | Veille dépendances |
| `frontend/web-app/package-lock.json` | Verrouillage npm |
| `PHASE2_REPORT.md` | Ce rapport |

### Modifiés

| Fichier | Changement |
|---|---|
| `src/Tenant/TenantContextListener.php` | Pilotage du filtre, `applyMembership()` |
| `src/Entity/TenantMembership.php` | Implémente `TenantOwnedInterface` |
| `src/Entity/Enum/MembershipStatus.php` | Libellés |
| `src/Repository/TenantMembershipRepository.php` | Requêtes scopées, owners actifs |
| `config/packages/doctrine.yaml` | Enregistrement de `tenant_filter` |
| `config/packages/framework.yaml` | Session durcie, deux limiteurs |
| `config/packages/security.yaml` | `login_throttling` |
| `config/packages/csrf.yaml` | Jeton stateless `remove_member` |
| `config/services.yaml` | Bindings des limiteurs |
| `config/reference.php` | Référence de configuration régénérée |
| `templates/base.html.twig` | Navigation, flashes, tableaux |
| `templates/dashboard/index.html.twig` | Accès membres/audit |
| `composer.json` / `composer.lock` | `symfony/rate-limiter` 8.1.11 |
| `tests/Tenant/TenantContextListenerTest.php` | Vérifie `disable`/`enable` |
| `infrastructure/nginx/default.conf` | En-têtes de sécurité |
| `docker-compose.yml` | Profil `app-tls` |
| `.gitignore` | Certificats PEM |
| `SECURITY.md`, `GETTING_STARTED.md`, `PHASES.md`, `BACKLOG.md` | Documentation Phase 2 |

---

## 4. Tests

Environnement : PHP 8.5.11, Symfony 8.1.11, PostgreSQL 17 (`postgres:17`, port `55432`), base `core_test`.

```bash
export DATABASE_URL='postgresql://aep:aep_dev_password@127.0.0.1:55432/core_test?serverVersion=17&charset=utf8'
php bin/phpunit
```

```
OK (58 tests, 214 assertions)
```

| Suite | Tests | Objet |
|---|---:|---|
| `Audit\AuditLogTest` | 2 | Empreinte stable, altération, dépendance au hash précédent |
| `Security\MembershipPolicyTest` | 17 | Matrice complète des politiques |
| `Controller\MembershipSecurityTest` | 8 | Parcours HTTP, CSRF, RBAC, cross-tenant, audit, rate limiting |
| `Controller\SecurityControllerTest` | 2 | Connexion (Phase 1) |
| `Entity\TenantTest` | 2 | Slug, suspension |
| `Tenant\TenantContextTest` | 2 | Contexte et reset |
| `Tenant\TenantContextListenerTest` | 6 | Résolution, refus, filtre |
| `Tenant\TenantFilterTest` | 3 | Fail-closed, UUID quoté, entité non cloisonnée |
| `Integration\TenantIsolationTest` | 4 | Filtre SQL, chaîne d'audit, triggers UPDATE/DELETE |
| `Security\TenantVoterTest` | 9 | RBAC |
| `Entity\UserTest` | 3 | Email, rôles, suspension |
| **Total** | **58** | **214 assertions** |

Tests négatifs clés :
- lecture d'adhésions et de logs d'un autre tenant → invisibles sous filtre ;
- adhésion d'un autre tenant ciblée par URL → `404` ;
- member tentant une mutation → `403`, aucune écriture ;
- admin tentant de rétrograder un owner → `403`, rôle inchangé, refus audité ;
- auto-retrait d'un owner → `403`, adhésion toujours active ;
- member consultant `/audit` → `403` ; owner → `200` ;
- page d'audit du tenant A ne contient jamais la ressource du tenant B ;
- 61e consultation de l'audit sur une minute → `429` avec `Retry-After`.

---

## 5. Preuves reproductibles

### Lint

```
php bin/console lint:yaml config      → All 25 YAML files contain valid syntax.
php bin/console lint:container        → container linted successfully.
php bin/console lint:twig templates   → All 5 Twig files contain valid syntax.
php bin/console doctrine:mapping:info → 4 entités OK
php bin/console doctrine:schema:validate --skip-sync → mapping OK
```

### Migration up / down

```
php bin/console doctrine:migrations:migrate --env=test
→ Migrating up to DoctrineMigrations\Version20261118220000
→ 2 migrations executed, 18 SQL queries

php bin/console doctrine:migrations:migrate prev --env=test
→ Migrating down to DoctrineMigrations\Version20261118190000
→ 1 migration executed, 4 SQL queries
→ table audit_logs absente (count = 0)

php bin/console doctrine:migrations:migrate --env=test
→ 1 migration executed, 9 SQL queries
→ 2 triggers présents sur audit_logs
```

### Triggers append-only (preuve SQL directe)

```
UPDATE audit_logs ...   → ERROR: audit_logs est append-only : opération UPDATE interdite
DELETE FROM audit_logs  → ERROR: audit_logs est append-only : opération DELETE interdite
TRUNCATE audit_logs     → ERROR: audit_logs est append-only : opération TRUNCATE interdite
```

Triggers installés :

```
trg_audit_logs_no_update_delete | DELETE OR UPDATE | EXECUTE FUNCTION aep_audit_logs_append_only()
trg_audit_logs_no_truncate      | TRUNCATE         | EXECUTE FUNCTION aep_audit_logs_append_only()
```

### TLS et en-têtes

```
docker run ... nginx:1.27-alpine nginx -t (tls.conf)     → syntax is ok / test is successful
docker run ... nginx:1.27-alpine nginx -t (default.conf) → syntax is ok / test is successful
```

### Rate limiting

```
php bin/phpunit --filter testAuditRateLimitReturns429AfterSixtyRequests
→ OK (1 test, 62 assertions)
→ 60 requêtes 200, 61e requête 429 avec Retry-After
```

### Dépendances

```
composer audit --locked  → No security vulnerability advisories found.
npm audit --audit-level=high → found 0 vulnerabilities
```

### Routes

```
app_dashboard             GET       /
app_tenant_members        GET       /tenant/members
app_tenant_member_add     POST      /tenant/members
app_tenant_member_role    POST      /tenant/members/{id}/role
app_tenant_member_remove  POST      /tenant/members/{id}/remove
app_audit                 GET       /audit
```

---

## 6. Réserves

| # | Réserve | Impact | Statut |
|---|---|---|---|
| 1 | RLS PostgreSQL non activé ; l'isolation repose sur le filtre applicatif | Une requête SQL brute hors ORM échappe au filtre | Non bloquant Phase 2 ; à ajouter en défense en profondeur (Phase 9) |
| 2 | Chaîne d'audit non ancrée hors base | Un superutilisateur PostgreSQL pourrait supprimer les triggers | Non bloquant ; sauvegardes protégées + ancrage externe prévus |
| 3 | CSP autorise `'unsafe-inline'` pour les styles | Surface XSS de style limitée, aucun script inline autorisé | Non bloquant ; nonces CSP quand les styles seront externalisés |
| 4 | Limiteurs stockés dans le cache applicatif local | Non global en cas de plusieurs instances | Bloquant avant scale horizontal ; passer le stockage sur Redis |
| 5 | CI commitée mais pas encore exécutée sur GitHub | Validation locale équivalente effectuée | Non bloquant ; le premier push déclenche le workflow |
| 6 | Certificat TLS local auto-signé | Avertissement navigateur en dev | Attendu ; certificats réels en production |
| 7 | Image `pgvector/pgvector:pg17` non téléchargée localement | Vérifications faites avec `postgres:17` | Non bloquant ; le compose conserve pgvector pour les embeddings |
| 8 | Pas de SAST/DAST ni scan d'images | Couverture sécurité partielle | Planifié Phase 9 |
| 9 | Audit non encore branché sur les futures actions métier | Couverture limitée aux adhésions et à la consultation | Attendu ; à étendre à chaque action sensible |

---

## 7. Verdict

**PASS_WITH_RESERVATIONS**

Les deux critères de sortie de la Phase 2 sont démontrés :
1. **aucune lecture inter-tenant** dans les tests négatifs (filtre SQL, `404` cross-tenant, page d'audit isolée) ;
2. **actions interdites refusées côté serveur** (matrice de politiques + tests HTTP `403` avec vérification de l'état en base).

Les réserves sont non bloquantes pour la Phase 2 mais doivent être traitées avant une exposition publique à fort trafic : RLS, stockage partagé des limiteurs, ancrage externe de l'audit, SAST/DAST et scan d'images.
