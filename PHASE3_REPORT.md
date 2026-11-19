# PHASE 3 REPORT — Moteur dynamique

**Date :** 2026-11-19
**Périmètre :** Phase 3 de `PHASES.md` — JSON Schema versionné, validation stricte, formulaires/listes/détails générés, CRUD générique, filtrage/pagination/tri, workflow configurable.
**Base de départ :** commit `64ec273` (Phase 2), 58 tests / 214 assertions.

---

## 1. Audit initial

| Élément | État avant Phase 3 |
|---|---|
| Définitions de fonctionnalités | Absentes |
| JSON Schema et versionnement | Aucun contrat de validation |
| CRUD métier | Contrôleurs écrits à la main uniquement |
| Formulaires dynamiques | Absents |
| Listes, détails, filtres, tri | Absents |
| Workflow configurable | Absent |
| Permissions par fonctionnalité | Absentes |
| Audit métier | Limité aux membres et à la consultation de l'audit |
| Rate limiting | `tenant_admin` et `audit_view` seulement |
| Dépendance de validation JSON Schema | Absente |
| Tests du moteur | Absents |

Écarts comblés : définitions versionnées et immuables, validation par allowlist, formulaire/liste/détail générés depuis le schéma, CRUD générique, recherche et tri JSONB sécurisés, workflow déclaratif avec gardes serveur, RBAC et audit dédiés.

---

## 2. Contrôles livrés

### Définitions et versions

- `FeatureDefinition` : définition propre à un tenant, clé unique par tenant, nom, description, version courante.
- `FeatureDefinitionVersion` : `schema`, `ui`, `workflow`, empreinte SHA-256, auteur et date ; aucune méthode de mutation.
- Chaque évolution du schéma, de l'interface ou du workflow crée une **nouvelle version** ; une modification de métadonnées seules met à jour la définition sans créer de version.
- La clé technique est immuable après création ; l'édition la relit depuis la route et non depuis le champ désactivé du formulaire.
- L'empreinte est calculée sur une sérialisation canonique : clés associatives triées récursivement, ordre des listes préservé, Unicode et `/` non échappés.
- Les triggers PostgreSQL `aep_feature_versions_immutable` refusent `UPDATE`, `DELETE` et `TRUNCATE` sur `feature_definition_versions`.
- Une clé étrangère composite `(feature_definition_id, schema_version)` garantit qu'un enregistrement ne peut référencer une version inexistante.

### Validation stricte des définitions

Allowlist volontairement limitée, appliquée côté serveur :

- objet racine uniquement, `additionalProperties: false` obligatoire ;
- primitives `string`, `integer`, `number`, `boolean` et tableaux de primitives ;
- aucun `$ref`, `pattern`, `oneOf`, `anyOf`, `allOf`, `not` ni objet imbriqué ;
- clés inconnues refusées à tous les niveaux ;
- maximum 30 champs, 32 Kio de définition, 12 états et 40 transitions ;
- clés et champs réservés refusés (`new`, `id`, `status`, `created_at`, `updated_at`, `created_by`) ;
- workflow : état initial existant, états connus, transitions déclarées, rôles appartenant à `owner`, `admin`, `member`, `auditor` ;
- interface : `list_fields` limité aux champs connus (8 maximum) et `per_page` borné.

Toute définition invalide est refusée avec un statut `422` et des erreurs de formulaire ; aucune ligne n'est créée.

### Formulaires, listes et détails générés

- `DynamicRecordType` construit les champs depuis `FeatureSchema` : texte, nombre, entier, booléen, date, email, URI, liste à choix ou virgules.
- `RecordDataNormalizer` convertit les valeurs HTML vers les types du schéma et retire les champs facultatifs vides.
- `RecordDataValidator` valide les données avec `opis/json-schema` 2.6.2, applique les bornes, énumérations et formats, et **rejette les champs inconnus** (`_form`).
- Les listes affichent les colonnes de `ui.list_fields` (8 maximum), avec recherche plein texte sur les champs `string` autorisés, filtre de statut, tri sur champs autorisés et pagination 10/25/50/100.
- Les détails affichent tous les champs du schéma, le statut, la version du schéma, l'auteur et les dates.
- Les vues sont génériques (`templates/feature/*.twig`) : aucune fonctionnalité ne dispose de contrôleur ou de template dédié.

### CRUD générique et workflow

Routes uniques pour toutes les fonctionnalités :

| Route | Méthode | Accès |
|---|---|---|
| `/features` | GET | tous les rôles |
| `/features/new` | GET/POST | owner, admin |
| `/features/{key}/edit` | GET/POST | owner, admin |
| `/features/{key}/records` | GET | tous les rôles |
| `/features/{key}/records/new` | GET/POST | owner, admin, member |
| `/features/{key}/records/{id}` | GET | tous les rôles |
| `/features/{key}/records/{id}/edit` | GET/POST | owner, admin, member |
| `/features/{key}/records/{id}/delete` | POST | owner, admin |
| `/features/{key}/records/{id}/transition` | POST | rôles déclarés dans la transition |

Le workflow est déclaratif :

- état initial, états libellés et transitions `from` → `to` ;
- vérification serveur de l'état source, de l'existence de la transition et du rôle autorisé ;
- garde de concurrence : si l'état a changé depuis l'affichage, la transition est refusée ;
- refus de politique ou de workflow audité avec `AuditResult::Denied`.

### Sécurité

- Isolation tenant : définitions et enregistrements implémentent `TenantOwnedInterface` ; un accès croisé renvoie `404`.
- RBAC déterministe : `FeaturePolicy` applique la matrice des rôles côté contrôleur et côté rendu.
- CSRF : jetons stateless `record_transition` et `delete_record`, en plus de `submit`.
- Rate limiting : limiteur `feature_write` à 60 mutations/minute/tenant/utilisateur, `429` avec `Retry-After`.
- Audit : création/version/métadonnées de définition, création/mise à jour/suppression/transition d'enregistrement, refus inclus.
- Recherche JSONB : champs et directions issus du schéma ; valeurs toujours paramétrées ; jokers SQL échappés.
- Aucune génération ni exécution de PHP par fonctionnalité : les définitions sont des données validées.

---

## 3. Fichiers

### Créés

| Fichier | Rôle |
|---|---|
| `services/core-platform/migrations/Version20261119120000.php` | Tables, index JSONB/GIN, FK composite, triggers d'immutabilité |
| `src/Entity/FeatureDefinition.php` | Définition tenant et version courante |
| `src/Entity/FeatureDefinitionVersion.php` | Version immuable (schéma, UI, workflow, hash) |
| `src/Entity/DynamicRecord.php` | Enregistrement JSONB, statut, version de schéma |
| `src/Repository/FeatureDefinitionRepository.php` | Recherche par tenant/clé, verrou de publication |
| `src/Repository/FeatureDefinitionVersionRepository.php` | Version courante et historique |
| `src/Repository/DynamicRecordRepository.php` | Recherche, filtre, tri et pagination JSONB |
| `src/Feature/CanonicalJson.php` | Sérialisation canonique et empreinte SHA-256 |
| `src/Feature/DefinitionValidationException.php` | Erreurs de définition |
| `src/Feature/FeatureDefinitionDraft.php` | Résultat normalisé d'une définition |
| `src/Feature/FeatureDefinitionValidator.php` | Allowlist stricte et normalisation |
| `src/Feature/FeatureField.php` | Champ typé du schéma |
| `src/Feature/FeaturePolicy.php` | Matrice RBAC du moteur |
| `src/Feature/FeaturePresenter.php` | Rendu liste/détail |
| `src/Feature/FeatureSchema.php` | Vue typée du JSON Schema et de l'UI |
| `src/Feature/RecordDataNormalizer.php` | Normalisation formulaire → types du schéma |
| `src/Feature/RecordDataValidator.php` | Validation `opis/json-schema` |
| `src/Feature/WorkflowDefinition.php` | États, transitions et gardes |
| `src/Feature/WorkflowTransition.php` | Transition et rôles autorisés |
| `src/Form/FeatureDefinitionType.php` | Formulaire de définition |
| `src/Form/DynamicRecordType.php` | Formulaire généré depuis le schéma |
| `src/Form/RecordTransitionType.php` | Transition avec état source |
| `src/Form/DeleteRecordType.php` | Suppression avec CSRF |
| `src/Controller/FeatureDefinitionController.php` | Liste, création, édition, versionnement |
| `src/Controller/DynamicRecordController.php` | CRUD, filtres, transitions, RBAC, audit |
| `templates/feature/index.html.twig` | Liste des fonctionnalités |
| `templates/feature/new.html.twig` | Création d'une définition |
| `templates/feature/edit.html.twig` | Édition, historique et publication |
| `templates/feature/records.html.twig` | Liste dynamique, filtres et pagination |
| `templates/feature/record_form.html.twig` | Formulaire dynamique |
| `templates/feature/record_show.html.twig` | Détail et transitions |
| `tests/Feature/CanonicalJsonTest.php` | Sérialisation et empreinte |
| `tests/Feature/FeatureDefinitionValidatorTest.php` | Allowlist et refus |
| `tests/Feature/FeatureSchemaTest.php` | Champs, tris, recherche, UI |
| `tests/Feature/FeaturePolicyTest.php` | Matrice RBAC |
| `tests/Feature/RecordDataValidatorTest.php` | Types, bornes, formats, inconnus |
| `tests/Feature/WorkflowDefinitionTest.php` | États, transitions, rôles |
| `tests/Integration/FeatureEngineIntegrationTest.php` | JSONB, isolation, triggers, FK |
| `tests/Controller/FeatureEngineHttpTest.php` | CRUD HTTP, RBAC, CSRF, audit, versionnement |
| `PHASE3_REPORT.md` | Ce rapport |

### Modifiés

| Fichier | Changement |
|---|---|
| `composer.json` / `composer.lock` | Ajout de `opis/json-schema` 2.6.2 |
| `config/packages/csrf.yaml` | Jetons stateless `record_transition`, `delete_record` |
| `config/packages/framework.yaml` | Limiteur `feature_write` |
| `config/services.yaml` | Binding `$featureWriteLimiter` |
| `src/Entity/Enum/AuditAction.php` | 7 actions Phase 3 et libellés |
| `templates/base.html.twig` | Navigation « Fonctionnalités », styles formulaires/tableaux/filtres |
| `PHASES.md` | Phase 3 marquée terminée |
| `BACKLOG.md` | P1 coché, activation par tenant reportée |
| `GETTING_STARTED.md` | Routes, règles et tests du moteur |
| `SECURITY.md` | Isolation, allowlist, immutabilité, CSRF, audit et rate limiting Phase 3 |
| `README.md` | Statut des phases 1 à 3 |

---

## 4. Tests

Environnement : PHP 8.5.11, Symfony 8.1.11, Doctrine ORM 3.8.0, PostgreSQL 17 (`postgres:17`, port `55432`), base `core_test_test`.

```bash
export DATABASE_URL='postgresql://aep:aep_dev_password@127.0.0.1:55432/core_test?serverVersion=17&charset=utf8'
php bin/phpunit
```

```
OK (117 tests, 671 assertions)
```

| Suite | Tests | Assertions | Objet |
|---|---:|---:|---|
| `tests/Feature` | 41 | 190 | CanonicalJson, validateur de définitions, schéma, politique, données, workflow |
| `tests/Integration/FeatureEngineIntegrationTest.php` | 8 | 46 | JSONB, pagination, isolation, triggers, FK, hash |
| `tests/Controller/FeatureEngineHttpTest.php` | 10 | 221 | CRUD, RBAC, CSRF, audit, versionnement, validation, rate limiting |
| Suites Phase 1–2 | 58 | 214 | Socle, tenants, sécurité, audit |
| **Total** | **117** | **671** | |

### Tests unitaires Phase 3

- **CanonicalJson** : tri récursif des clés, ordre des listes préservé, Unicode, hash indépendant de l'ordre des clés, hash sensible à l'ordre des listes.
- **FeatureDefinitionValidator** : définition valide normalisée ; refus de `$ref`, `pattern`, objets imbriqués, clés inconnues, champs réservés, workflow invalide, limites de taille et de nombre.
- **FeatureSchema** : champs, obligatoires, `list_fields`, `per_page`, tris/recherches autorisés, conversion Opis, échec fermé sur champ inconnu.
- **FeaturePolicy** : lecture pour tous ; définitions owner/admin ; création/mise à jour owner/admin/member ; suppression owner/admin ; transitions selon les rôles déclarés.
- **RecordDataValidator** : normalisation des types, bornes, énumérations, formats email/date, tableaux, booléens HTML, champs facultatifs vides, rejet des champs inconnus.
- **WorkflowDefinition** : état initial, libellés, transitions disponibles, refus des états/transitions/rôles inconnus.

### Tests d'intégration PostgreSQL

- Recherche `q` limitée aux champs `string`, insensible à la casse, jokers SQL échappés.
- Filtre de statut, tri numérique/texte/booléen, valeurs absentes en dernier.
- Pagination bornée et `page_count` cohérent.
- Enregistrements limités à leur définition et à leur tenant ; filtre Doctrine fail-closed.
- `UPDATE`, `DELETE` et `TRUNCATE` sur `feature_definition_versions` refusés par trigger.
- FK composite refusant une version de schéma inexistante.
- Hash de version identique au payload canonique.

### Tests HTTP

- Owner : création d'une fonctionnalité et d'un enregistrement, liste, détail, édition, audit.
- Member : peut ouvrir une transition autorisée ; refus sur fermeture, suppression et gestion des définitions (`403`), état inchangé.
- Auditor : lecture seule ; refus de toutes les mutations, y compris les transitions.
- Admin : suppression d'un enregistrement avec audit.
- Cross-tenant : fonctionnalité et enregistrement d'un autre tenant → `404`.
- CSRF : jeton absent ou origine étrangère → aucun changement d'état, aucune suppression.
- Validation : donnée invalide → `422`, aucune ligne persistée.
- Versionnement : v1 conservée, v2 publiée, anciens enregistrements en v1 puis migrés en v2 à la modification ; métadonnées seules → pas de nouvelle version.
- Définition invalide (`$ref`) → `422`, aucune création.
- 60 mutations acceptées puis 61e → `429` avec `Retry-After`.

---

## 5. Preuves reproductibles

### Lint et mapping

```
php bin/console lint:yaml config      → All 25 YAML files contain valid syntax.
php bin/console lint:twig templates   → All 11 Twig files contain valid syntax.
php bin/console lint:container        → container linted successfully.
php bin/console doctrine:mapping:info → 7 entités mappées, toutes OK.
php bin/console doctrine:schema:validate --skip-sync → mapping correct.
```

### Migration up / down

```
php bin/console doctrine:migrations:migrate prev --env=test
→ Migrating down to DoctrineMigrations\Version20261118220000
→ 1 migration executed, 6 SQL queries

php bin/console doctrine:migrations:migrate --env=test
→ Migrating up to DoctrineMigrations\Version20261119120000
→ 1 migration executed, 22 SQL queries
```

### Triggers d'immutabilité

```
UPDATE feature_definition_versions ...  → ERROR: feature_definition_versions est immuable : opération UPDATE interdite
DELETE FROM feature_definition_versions → ERROR: feature_definition_versions est immuable : opération DELETE interdite
TRUNCATE feature_definition_versions CASCADE → ERROR: feature_definition_versions est immuable : opération TRUNCATE interdite
```

### Routes

```
app_features                    GET        /features
app_feature_new                 GET|POST   /features/new
app_feature_edit                GET|POST   /features/{key}/edit
app_feature_records             GET        /features/{key}/records
app_feature_record_new          GET|POST   /features/{key}/records/new
app_feature_record_show         GET        /features/{key}/records/{id}
app_feature_record_edit         GET|POST   /features/{key}/records/{id}/edit
app_feature_record_delete       POST       /features/{key}/records/{id}/delete
app_feature_record_transition   POST       /features/{key}/records/{id}/transition
```

### Dépendances

```
composer audit → No security vulnerability advisories found.
```

`opis/json-schema` 2.6.2 est verrouillé dans `composer.lock` avec `opis/string` et `opis/uri`.

### Critère de sortie Phase 3

Le test `testOwnerCreatesFeatureAndRecordThroughGenericEngine` crée la fonctionnalité `incident`, puis un enregistrement complet via les routes génériques, sans aucun fichier PHP ajouté pour cette fonctionnalité. Le même contrôleur sert toutes les définitions.

---

## 6. Réserves

| # | Réserve | Impact | Statut |
|---|---|---|---|
| 1 | RLS PostgreSQL non activée ; l'isolation repose sur le filtre applicatif | Une requête SQL brute hors ORM échappe au filtre | Non bloquant Phase 3 ; défense en profondeur prévue Phase 9 |
| 2 | Chaîne d'audit non ancrée hors base | Un superutilisateur PostgreSQL pourrait supprimer les triggers | Non bloquant ; sauvegardes protégées et ancrage externe prévus |
| 3 | Limiteurs stockés dans le cache local | Non global en cas de plusieurs instances | Non bloquant ; stockage partagé Redis avant scale horizontal |
| 4 | Pas d'activation/désactivation d'une fonctionnalité par tenant | Une définition créée est immédiatement active | Non bloquant ; reste au backlog P1 |
| 5 | Pas de migration automatique des enregistrements lors d'un changement de schéma | Les anciens enregistrements restent lisibles avec leur `schemaVersion` jusqu'à leur prochaine modification | Accepté ; une revalidation a lieu à chaque écriture |
| 6 | Pas de champs calculés, de contraintes d'unicité métier ni d'import/export | Le moteur couvre le CRUD standard, pas les besoins avancés | Non bloquant ; extensions possibles dans les phases suivantes |
| 7 | `doctrine:schema:validate` complet signalerait des écarts connus (`messenger_messages`, FK composite, index GIN et DESC) | Ces objets PostgreSQL sont volontaires et absents du mapping Doctrine | Non bloquant ; la CI valide le mapping avec `--skip-sync` |
| 8 | Pas de test de charge JSONB à grande échelle | La performance de recherche/filtre au-delà du MVP n'est pas mesurée | Non bloquant ; Phase 9 |
| 9 | CSP autorise `style-src 'unsafe-inline'` | Surface XSS de style limitée ; aucun script inline | Non bloquant ; nonces CSP lors de l'externalisation |
| 10 | Aucune génération de code PHP par fonctionnalité | C'est le choix de conception de la Phase 3 | Attendu ; la génération PHP reste réservée à la Phase 5 dans une sandbox |

---

## 7. Verdict

**PASS**

Le critère de sortie de la Phase 3 est démontré : une fonctionnalité CRUD complète (définition, formulaire, liste, détail, filtres, transitions) est créée **sans nouveau fichier PHP**, avec permissions serveur, isolation tenant, audit, CSRF, rate limiting et tests unitaires, d'intégration et HTTP verts.

Les réserves sont non bloquantes pour la Phase 3. Les plus structurantes — RLS, ancrage externe de l'audit, stockage partagé des limiteurs et test de charge — restent planifiées avant une exposition publique à fort trafic.
