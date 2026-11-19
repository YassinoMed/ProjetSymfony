# PHASE 4 REPORT — Orchestrateur IA

**Date :** 2026-11-19
**Périmètre :** Phase 4 de `PHASES.md` — contrat de fournisseur IA, agents Architecte / Builder / Auditeur / Gouverneur / Déployeur, file asynchrone avec reprises bornées, RAG filtré par tenant et permissions, fournisseurs cloud et Ollama local.
**Base de départ :** commit `35b461c` (Phase 3), 117 tests / 671 assertions côté Core.
**Critère de sortie :** une demande produit une proposition structurée, explicable et **non publiée automatiquement**.

---

## 1. Audit initial

| Élément | État avant Phase 4 |
|---|---|
| Service AI Orchestrator | Squelette Symfony, aucune entité ni logique |
| Contrat de fournisseur IA | Absent |
| Agents Architecte / Builder / Auditeur / Gouverneur / Déployeur | Dossiers vides |
| File asynchrone | Messenger installé, aucun message métier |
| RAG | Absent, pgvector disponible dans PostgreSQL |
| Filtrage RAG par tenant et rôle | Absent |
| Approbation humaine | Absente |
| Publication automatique | Non encadrée : aucun statut de proposition n'existait |
| Idempotence des demandes | Absente |
| Tests AI | Absents |
| API interne AI | Absente |
| Sécurité inter-services | Aucun jeton interne côté AI |

Écarts comblés : contrat de fournisseur stable, chaîne de repli contrôlée, quatre agents déterministes ou assistés, pipeline explicable, propositions non publiables, file avec verrou et reprises, RAG cloisonné, API interne à échec fermé.

---

## 2. Contrôles livrés

### 2.1 Contrat de fournisseur

- `AiProviderInterface` : `name()`, `isConfigured()`, `complete(ProviderRequest): ProviderResponse`.
- `ProviderRequest` transporte le prompt système, le prompt utilisateur, un identifiant de corrélation, le modèle et un délai maximal ; aucune donnée tenant brute n'est envoyée au fournisseur au-delà du prompt nécessaire.
- `ProviderResponse` expose le texte, le fournisseur, le modèle, la latence et l'usage de jetons ; `toArray()` fournit une trace sérialisable.
- `ProviderRegistry` lit `AI_PROVIDER_CHAIN` (liste ordonnée), ignore les fournisseurs non configurés et bascule sur le suivant uniquement pour une indisponibilité ou une réponse invalide.
- `ProviderUnavailableException` et `ProviderResponseException` distinguent panne et réponse inexploitable.
- Implémentations :
  - `DeterministicProvider` (`stub`) : produit une spécification reproductible, utile en test et en développement sans réseau ;
  - `OllamaProvider` : `POST /api/generate` sur `OLLAMA_URL`, sans streaming, délai borné ;
  - `OpenAiCompatibleProvider` : `POST /chat/completions`, compatible OpenAI et serveurs auto-hébergés, désactivé par défaut (`AI_ENABLE_CLOUD=0`).
- Aucun fournisseur n'a le droit d'écrire en base, de publier ou d'appeler un outil métier.

### 2.2 Agents

| Agent | Type | Entrée | Sortie | Interdictions |
|---|---|---|---|---|
| Architecte | Fournisseur IA + validation | Prompt, rôle, contexte RAG | Spécification `key`, `name`, `fields`, `workflow`, `assumptions`, `open_questions` | Ne persiste rien, ne publie pas |
| Builder | Déterministe | Spécification validée | Payload `feature_definition` conforme au Core | Pas de PHP, pas de secret, pas de publication |
| Auditeur | Déterministe | Payload, contexte RAG | `passed`, `failures`, `warnings`, `checks`, `risks` | Ne peut pas s'auto-attribuer un PASS sans preuve |
| Gouverneur | Déterministe | Résultat d'audit, contexte | `policy` avec statut, approbation humaine et publication | Refuse hors politique |
| Déployeur | Déterministe | Proposition | Préflight uniquement | Publication toujours `forbidden` |

- Chaque exécution est enregistrée dans `ai_runs` avec agent, statut, fournisseur, modèle, latence, jetons, résumé ou erreur.
- `ProposalPipeline` enchaîne Architecte → Builder → Auditeur → Gouverneur, marque la demande `analyzing`, `building`, `validating`, puis crée **au plus une** proposition par demande.
- Le Builder convertit les types de l'Architecte vers l'allowlist du Core (`string`, `integer`, `number`, `boolean`, `array`, formats `date`, `email`, `uri`). Les types `date`, `datetime`, `select` et `text` sont traduits ou normalisés ; aucun type arbitraire n'atteint le Core.
- `ArchitectSpecificationValidator` : validation JSON Schema Opis puis contrôles sémantiques (clé réservée, champs dupliqués, `select` sans options, options hors `select`, état initial et transitions connus, rôles autorisés, nombre de champs borné).
- `FeatureDefinitionShapeValidator` : revérifie le payload Builder avant persistance.
- `AuditorAgent` produit six contrôles : structure, champs réservés, tailles et volumes, instructions de publication, injection RAG, vocabulaire sensible. Chaque risque porte un identifiant stable (`AUD-01`…`AUD-06`), une sévérité et une preuve.
- `GovernorAgent` : `requires_human_approval` toujours `true`, `publication` toujours `forbidden`, statut `awaiting_approval` ou `rejected`, niveau de risque `low`, `medium` ou `high`.
- `DeployerAgent::preflight()` échoue systématiquement sur le contrôle de publication, y compris pour une proposition approuvée.

### 2.3 Proposition et décision humaine

- `AiProposal` contient : payload, rationale (résumé, décisions, hypothèses, questions ouvertes), risques, citations RAG, politique, trace d'agents, métadonnées fournisseur.
- Statuts possibles : `awaiting_approval`, `approved`, `rejected`. **Aucun cas `published` n'existe** : l'absence de cet état rend la publication automatique non représentable.
- `ProposalDecisionService` :
  - recherche la proposition **par identifiant et tenant** ;
  - refuse une seconde décision (`409`) ;
  - exige un motif de 10 à 1000 caractères ;
  - enregistre l'acteur et l'horodatage ;
  - met à jour la demande (`approved` ou `rejected`), sans jamais créer de version ni activer de fonctionnalité.
- La réponse HTTP expose explicitement `published: false` et `publication: forbidden`.

### 2.4 File asynchrone, idempotence et reprises

- Symfony Messenger, transport `doctrine://default` en développement, `in-memory://` en test.
- `RunGenerationRequest` porte l'identifiant de demande ; un identifiant non UUID est refusé à la construction.
- `RunGenerationHandler` :
  - `AiRequestRepository::claim()` verrouille la ligne (`LockMode::PESSIMISTIC_WRITE`) et ne rend la demande que si elle est encore `queued` ;
  - un message dupliqué ou un worker concurrent s'arrête sans effet ;
  - une panne fournisseur programme une reprise tant que `attempts < AI_MAX_ATTEMPTS - 1` ;
  - à épuisement, la demande passe `failed` avec l'erreur bornée à 2000 caractères ;
  - toute autre exception est journalisée et clôt la demande.
- Contrainte unique `(tenant_id, idempotency_key)` : la même clé rejouée par le même tenant renvoie la demande existante (`200`, `Idempotent-Replay: true`) ; la même clé dans un autre tenant crée une demande distincte.
- Une proposition existante est retournée telle quelle si le pipeline est relancé.

### 2.5 RAG filtré

- `RagDocument` : tenant, titre, source, contenu, rôles autorisés, empreinte SHA-256, auteur.
- `RagChunk` : document, ordinal, contenu, embedding JSONB.
- `RagIndexer` : découpage (`TextChunker`), empreinte, indexation idempotente par `(tenant_id, checksum)`, refus du contenu vide et des rôles inconnus.
- `RagRetriever` : **filtre SQL tenant + rôle appliqué avant la similarité**, puis calcul cosinus (`VectorMath`) sur les seuls fragments autorisés. Un rôle inconnu est refusé.
- Embeddings : `DeterministicEmbeddingProvider` par défaut (aucun réseau, reproductible) ; `OllamaEmbeddingProvider` optionnel. Les vecteurs de dimension différente sont ignorés plutôt que comparés approximativement.
- `PromptInjectionScanner` : détection de motifs connus (instructions ignorées, changement de rôle, mode développeur, exfiltration, identifiants, Unicode invisible).
- Un fragment détecté comme injection est **exclu du prompt**, mais conservé dans les constats d'audit et de gouvernance. Le contexte injecté dans le prompt est encadré par `<contexte_interne_non_fiable>` et présenté comme des **données**, jamais comme des instructions.
- `RagContext::citations()` fournit les sources affichées dans la proposition.

### 2.6 API interne et sécurité

| Route | Méthode | Objet |
|---|---|---|
| `/health` | GET | Santé publique, `publication: forbidden` |
| `/internal/ai/requests` | POST | Création idempotente d'une demande |
| `/internal/ai/requests/{id}` | GET | Statut et proposition d'une demande |
| `/internal/ai/proposals/{id}` | GET | Proposition complète |
| `/internal/ai/proposals/{id}/decision` | POST | Approbation ou rejet humain |

- `InternalTokenListener` exige `X-Internal-Token` sur toutes les routes `/internal/*` ; jeton absent ou invalide → `401`, comparaison en temps constant. Échec fermé : un jeton vide en configuration refuse tout.
- En-têtes obligatoires : `X-Tenant-Id` (UUID), `X-User-Id` (UUID, requis pour une décision), `X-Actor-Role` (`owner`, `admin`, `member`, `auditor`).
- Toute lecture ou décision inter-tenant renvoie `404` : l'identifiant seul ne suffit jamais.
- Corps JSON strict : clés inconnues refusées, y compris `published`.
- Aucune route `/publish` n'existe ; une tentative renvoie `404`.
- L'API n'ouvre aucune connexion à la base du Core et n'utilise que des identifiants opaques.

### 2.7 Exploitation

- `docker-compose.yml` : `ai-orchestrator` (worker Messenger) et `ai-orchestrator-api` (serveur PHP), même image `auto-evolving-platform-ai-orchestrator:latest`.
- Ancre `x-ai-environment` : base `ai`, transport Doctrine, jeton interne, chaîne de fournisseurs, Ollama, embeddings, tentatives maximales.
- Port hôte `AI_HOST_PORT=8010` par défaut.
- `ollama` reste dans le profil `ai-local` : le socle démarre sans modèle local.
- Commande `ai:rag:index` pour indexer un document avec rôles autorisés.

---

## 3. Fichiers

### Créés

| Fichier | Rôle |
|---|---|
| `migrations/Version20261119090000.php` | Tables `ai_requests`, `ai_runs`, `ai_proposals`, `rag_documents`, `rag_chunks`, `messenger_messages` et contraintes |
| `src/Entity/AiRequest.php` | Demande, statut, rôle acteur, tentatives, idempotence |
| `src/Entity/AiRun.php` | Trace d'exécution d'un agent |
| `src/Entity/AiProposal.php` | Proposition non publiable, décision humaine |
| `src/Entity/RagDocument.php` | Document RAG cloisonné |
| `src/Entity/RagChunk.php` | Fragment et embedding |
| `src/Entity/Enum/RequestStatus.php` | Statuts de demande, sans `published` |
| `src/Entity/Enum/ProposalStatus.php` | `awaiting_approval`, `approved`, `rejected` |
| `src/Entity/Enum/AgentName.php` | Architecte, Builder, Auditeur, Gouverneur, Déployeur |
| `src/Entity/Enum/RunStatus.php` | `succeeded`, `failed` |
| `src/Entity/Enum/Decision.php` | `approve`, `reject` |
| `src/Repository/AiRequestRepository.php` | Verrou pessimiste, recherche par tenant, idempotence |
| `src/Repository/AiProposalRepository.php` | Proposition par demande ou par tenant |
| `src/Repository/AiRunRepository.php` | Trace ordonnée par demande |
| `src/Repository/RagDocumentRepository.php` | Recherche par tenant et empreinte |
| `src/Repository/RagChunkRepository.php` | Filtrage SQL tenant + rôle avant similarité |
| `src/Provider/AiProviderInterface.php` | Contrat de fournisseur |
| `src/Provider/ProviderRequest.php` | Requête normalisée |
| `src/Provider/ProviderResponse.php` | Réponse normalisée et sérialisable |
| `src/Provider/ProviderRegistry.php` | Chaîne ordonnée et repli contrôlé |
| `src/Provider/DeterministicProvider.php` | Fournisseur `stub` reproductible |
| `src/Provider/OllamaProvider.php` | Fournisseur local Ollama |
| `src/Provider/OpenAiCompatibleProvider.php` | Fournisseur cloud compatible OpenAI |
| `src/Provider/ProviderUnavailableException.php` | Panne fournisseur |
| `src/Provider/ProviderResponseException.php` | Réponse inexploitable |
| `src/Agent/AgentResult.php` | Résultat d'agent |
| `src/Agent/ArchitectAgent.php` | Spécification assistée et validée |
| `src/Agent/BuilderAgent.php` | Conversion vers le format du Core |
| `src/Agent/AuditorAgent.php` | Audit déterministe avec preuves |
| `src/Agent/GovernorAgent.php` | Politique et approbation obligatoire |
| `src/Agent/DeployerAgent.php` | Préflight, publication interdite |
| `src/Proposal/ProposalPipeline.php` | Orchestration et création de la proposition |
| `src/Proposal/ProposalDecisionService.php` | Décision humaine idempotente par tenant |
| `src/Proposal/ArchitectSpecificationValidator.php` | Validation Opis + sémantique |
| `src/Proposal/FeatureDefinitionShapeValidator.php` | Validation du payload Builder |
| `src/Proposal/InvalidSpecificationException.php` | Erreurs de spécification |
| `src/Proposal/InvalidDefinitionException.php` | Erreurs de définition |
| `src/Proposal/ProposalConflictException.php` | Seconde décision |
| `src/Proposal/ProposalNotFoundException.php` | Proposition hors tenant |
| `src/RAG/TextChunker.php` | Découpage déterministe |
| `src/RAG/RagIndexer.php` | Indexation idempotente |
| `src/RAG/RagIndexResult.php` | Résultat d'indexation |
| `src/RAG/RagRetriever.php` | Recherche filtrée |
| `src/RAG/RagContext.php` | Contexte, citations, injections |
| `src/RAG/RetrievedChunk.php` | Fragment retourné |
| `src/RAG/RagRoles.php` | Rôles autorisés et normalisation |
| `src/RAG/PromptInjectionScanner.php` | Détection d'injection |
| `src/RAG/VectorMath.php` | Similarité cosinus |
| `src/RAG/Embedding/EmbeddingProviderInterface.php` | Contrat d'embedding |
| `src/RAG/Embedding/DeterministicEmbeddingProvider.php` | Embeddings locaux reproductibles |
| `src/RAG/Embedding/OllamaEmbeddingProvider.php` | Embeddings Ollama |
| `src/RAG/Embedding/EmbeddingProviderSelector.php` | Sélection et repli |
| `src/Message/RunGenerationRequest.php` | Message de génération |
| `src/Message/RunGenerationHandler.php` | Verrou, reprises, échec définitif |
| `src/Security/InternalTokenListener.php` | Jeton interne à échec fermé |
| `src/Controller/InternalAiController.php` | API interne et décision |
| `src/Command/IndexRagDocumentCommand.php` | `ai:rag:index` |
| `config/packages/messenger.yaml` | Transport async, routage test synchrone |
| `config/packages/doctrine.yaml` | Connexion base `ai`, suffixe `_test` |
| `.env.test` | Jeton, chaîne `stub`, Ollama/cloud désactivés |
| `phpunit.dist.xml`, `bin/phpunit` | PHPUnit 13 |
| `.dockerignore` | Contexte d'image minimal |
| `tests/Support/Fixtures.php` | Spécification, payload, demande |
| `tests/Unit/...` (15 fichiers) | Fournisseurs, validateurs, agents, RAG |
| `tests/Integration/...` (5 fichiers) | Base PostgreSQL, RAG, pipeline, décision, handler |
| `tests/Controller/InternalAiHttpTest.php` | API interne et sécurité |
| `PHASE4_REPORT.md` | Ce rapport |

### Modifiés

| Fichier | Changement |
|---|---|
| `composer.json` / `composer.lock` | `opis/json-schema`, `symfony/http-client`, `symfony/messenger`, `symfony/doctrine-messenger`, `symfony/uid`, `symfony/yaml`, PHPUnit |
| `config/services.yaml` | Câblage fournisseurs, RAG, sécurité, handler |
| `.env` | Variables AI, RAG, Ollama, cloud |
| `Dockerfile` | Extensions PostgreSQL, utilisateur non privilégié, build reproductible |
| `.gitignore` | Cache PHPUnit, `.env.local` |
| `README.md` (service) | Architecture, pipeline, démarrage et tests réels |
| `docker-compose.yml` | Worker et API AI, ancre d'environnement, image partagée |
| `PHASES.md` | Phase 4 marquée terminée |
| `BACKLOG.md` | P2 coché |
| `README.md` | Statut des phases 1 à 4 |
| `GETTING_STARTED.md` | Démarrage AI, variables, routes, tests |
| `SECURITY.md` | Jeton interne, RAG, injection, non-publication |
| `AI_AGENTS.md` | Agents réels, entrées/sorties, garde-fous |
| `API_CONTRACTS.md` | Contrats internes AI |
| `DATA_MODEL.md` | Schéma de la base `ai` |
| `TEST_PLAN.md` | Scénarios et preuves Phase 4 |
| `contracts/api/ai-orchestrator.openapi.yaml` | Contrat OpenAPI de l'API interne |
| `.github/workflows/ci.yml` | Job AI avec PostgreSQL et tests |

### Supprimés

| Fichier | Raison |
|---|---|
| `src/Agents/*/.gitkeep` | Remplacés par `src/Agent/*.php` |
| `src/Providers/*/.gitkeep` | Remplacés par `src/Provider/*.php` |
| `src/Messaging/.gitkeep` | Remplacé par `src/Message/` |
| `src/Policies/.gitkeep` | Politique portée par le Gouverneur |
| `src/PromptManagement/.gitkeep` | Prompts gérés par les agents |
| `src/ToolRegistry/.gitkeep` | Aucun outil autonome en Phase 4 |
| `src/RAG/.gitkeep` | Dossier désormais peuplé |

---

## 4. Tests

Environnement : PHP 8.5.11, Symfony 8.1.11, Doctrine ORM 3.8.0, PostgreSQL 17 (pgvector), base `ai_test`.

```bash
cd services/ai-orchestrator
DATABASE_URL='postgresql://aep:aep_dev_password@127.0.0.1:5432/ai?serverVersion=17&charset=utf8' \
  php bin/phpunit
```

```
OK (117 tests, 295 assertions)
```

| Suite | Tests | Assertions | Objet |
|---|---:|---:|---|
| `tests/Unit` | 80 | 144 | Fournisseurs, validateurs, agents, RAG, vecteurs |
| `tests/Integration` | 23 | 80 | PostgreSQL, indexation, pipeline, décision, handler |
| `tests/Controller` | 14 | 71 | API interne, jetons, en-têtes, idempotence, cross-tenant |
| **Total** | **117** | **295** | |

### Tests unitaires

- **Fixtures / ProviderResponse** : structure stable, sérialisation, valeurs par défaut.
- **ProviderRegistry** : ordre de la chaîne, fournisseur non configuré ignoré, repli sur panne, échec quand toute la chaîne est indisponible, nom inconnu refusé.
- **DeterministicProvider** : spécification valide, stable, clé ASCII dérivée du prompt (accents translittérés), types autorisés.
- **ArchitectSpecificationValidator** : spécification valide ; refus des clés inconnues, champs réservés ou dupliqués, `select` sans options, options hors `select`, états et rôles inconnus, clé réservée, hypothèses absentes.
- **FeatureDefinitionShapeValidator** : payload valide ; refus des clés inconnues, type erroné, clé ou champ réservé, type de champ invalide, champ de liste inconnu, état ou rôle inconnu, définition absente.
- **BuilderAgent** : conversion complète vers le format Core, déterminisme, titre humanisé.
- **AuditorAgent** : payload propre, champs réservés, instruction de publication, injection RAG, vocabulaire sensible.
- **GovernorAgent** : approbation humaine et publication interdite, rejet sur audit échoué, rejet sur injection, risque moyen sur avertissements.
- **DeployerAgent** : publication toujours refusée, y compris après approbation ; préflight échoué sur proposition rejetée.
- **TextChunker** : texte vide, texte court, paragraphes, découpage forcé, taille minimale, nombre de fragments borné, déterminisme.
- **PromptInjectionScanner** : texte propre, six familles de motifs, Unicode invisible, déduplication et borne.
- **RagRoles** : normalisation, casse, doublons, rôle inconnu, rôle non chaîne, liste autorisée.
- **Embedding** : dimension et stabilité, normalisation, texte vide, sélection déterministe, sélection Ollama, repli si Ollama désactivé.
- **VectorMath** : identiques, orthogonaux, opposés, vecteur nul, dimensions différentes, vecteurs vides, invariance d'échelle.

### Tests d'intégration PostgreSQL

- Indexation idempotente par tenant ; même contenu dans deux tenants indexé séparément.
- Recherche restreinte au tenant et au rôle ; un membre ne voit pas un document réservé aux propriétaires.
- Fragment injecté exclu du contexte mais signalé ; contexte encadré comme données non fiables.
- Requête vide, rôle inconnu, contenu vide et rôle inconnu à l'indexation refusés.
- Pipeline complet : proposition `awaiting_approval`, demande `awaiting_approval`, trace des quatre agents, `publication: forbidden`, aucune clé `published`.
- Pipeline idempotent : une seule proposition pour une demande.
- Panne fournisseur : demande `failed`, exécution `architect` `failed`, erreur conservée.
- Proposition cloisonnée par tenant.
- Approbation enregistrée sans publication ; rejet enregistré ; seconde décision `409` ; décision inter-tenant `404` ; motif trop court refusé.
- Handler : exécution unique, doublon ignoré, demande inconnue ignorée, trois pannes puis échec définitif avec `attempts = 2`, identifiant invalide refusé, exception inattendue du pipeline → demande `failed` avec erreur conservée.

### Tests HTTP et sécurité

- `/health` public et déclare `publication: forbidden`.
- Jeton absent ou invalide → `401` ; en-tête tenant absent → `400`.
- Clé de corps inconnue (`published`) refusée.
- Rôle acteur invalide refusé.
- Création : pipeline exécuté, proposition `awaiting_approval`, `published: false`, quatre agents, payload `feature_definition`.
- Idempotence par tenant (`200` + `Idempotent-Replay`) et indépendance entre tenants.
- Lecture inter-tenant → `404` pour la demande et la proposition.
- Décision : `approved`, acteur enregistré, `published: false`, demande mise à jour.
- Seconde décision → `409` ; acteur absent → `400` ; décision `publish` → `400`.
- Route `/publish` inexistante → `404`.

---

## 5. Preuves reproductibles

### Lint et mapping

```
php bin/console lint:yaml config --parse-tags → All 9 YAML files contain valid syntax.
php bin/console lint:container                → The container was compiled successfully.
php bin/console doctrine:schema:validate      → Mapping correct, base synchronisée.
php -l sur src/ et tests/                     → aucune erreur de syntaxe.
```

`--parse-tags` est requis par `!tagged_iterator` dans `config/services.yaml` ; le job CI AI l'utilise.

### Migration

```
php bin/console doctrine:migrations:migrate --no-interaction
→ Version20261119090000 : 11 requêtes SQL exécutées

php bin/console doctrine:migrations:migrate prev --no-interaction
→ Version20261119090000 retirée (6 tables supprimées, 6 requêtes)

php bin/console doctrine:migrations:migrate --no-interaction
→ Version20261119090000 réappliquée
```

### Contraintes structurelles

```
SELECT status FROM ai_proposals → awaiting_approval | approved | rejected uniquement
CHECK chk_ai_proposals_status   → aucun statut published accepté
UNIQUE (tenant_id, idempotency_key) → doublon refusé par PostgreSQL
UNIQUE (request_id) sur ai_proposals → une proposition par demande
```

### Routes

```
health                        GET      /health
internal_ai_create_request    POST     /internal/ai/requests
internal_ai_show_request      GET      /internal/ai/requests/{id}
internal_ai_show_proposal     GET      /internal/ai/proposals/{id}
internal_ai_decide_proposal   POST     /internal/ai/proposals/{id}/decision
```

### Docker

```
docker compose --profile app build ai-orchestrator
→ image auto-evolving-platform-ai-orchestrator:latest (EXIT 0)

docker compose --profile app up -d ai-orchestrator ai-orchestrator-api
→ worker Messenger « Consuming messages from transport "async" »
→ API sur 0.0.0.0:8010

GET http://127.0.0.1:8010/health
→ 200 {"status":"ok","service":"ai-orchestrator","phase":4,"publication":"forbidden"}

POST http://127.0.0.1:8010/internal/ai/requests (X-Internal-Token)
→ 202 {"status":"queued","proposal":null}

Worker (journal réel)
→ [info] Received message App\Message\RunGenerationRequest
→ [info] Message ... handled by App\Message\RunGenerationHandler::__invoke
→ [info] ... was handled successfully (acknowledging to transport)

GET http://127.0.0.1:8010/internal/ai/requests/{id}
→ status awaiting_approval, provider stub
→ proposal awaiting_approval, published false, publication forbidden
→ agent_trace : architect, builder, auditor, governor

POST /internal/ai/requests (même clé d'idempotence)
→ 200, en-tête Idempotent-Replay: true

GET /internal/ai/requests/{id} sans X-Internal-Token → 401
POST /internal/ai/proposals/{id}/publish → 404

POST /internal/ai/proposals/{id}/decision {"decision":"approve", ...}
→ 200, status approved, published false, publication forbidden, décidé par l'acteur transmis
→ seconde décision → 409
```

### Dépendances

```
composer audit → No security vulnerability advisories found.
```

### Intégration continue

```
Commit 818a39b → run GitHub Actions 44485604173 : success
Jobs : Core Symfony, AI Orchestrator, generation-service, sandbox-runner, Frontend
Le job AI applique les migrations, vérifie le rollback puis exécute les 117 tests.
```

### Critère de sortie Phase 4

Le test `testCreateRunsPipelineAndNeverPublishes` prouve qu'une demande produit une proposition structurée et explicable (`payload`, `rationale`, `risks`, `citations`, `policy`, `agent_trace`), que son statut est `awaiting_approval` et que `published` vaut `false` avec `publication: forbidden`. Le test `testNoPublishRouteExists` prouve qu'aucune route de publication n'existe.

---

## 6. Réserves

| # | Réserve | Impact | Statut |
|---|---|---|---|
| 1 | Embeddings déterministes par défaut, non sémantiques | La pertinence RAG dépend des mots-clés ; Ollama doit être activé pour une vraie similarité sémantique | Non bloquant Phase 4 ; activer `RAG_EMBEDDING_PROVIDER=ollama` et mesurer |
| 2 | Embeddings stockés en JSONB, similarité calculée en PHP | Pas d'index vectoriel pgvector exploité ; coût linéaire au volume | Non bloquant au MVP ; index HNSW et dimension fixe avant montée en charge |
| 3 | Aucun chiffrement des prompts au repos | Les prompts et contenus RAG sont en clair dans PostgreSQL | Non bloquant ; chiffrement applicatif ou disque selon l'exigence |
| 4 | Publication non implémentée | C'est le périmètre volontaire de la Phase 4 | Attendu ; la Phase 6+ doit introduire un chemin d'activation séparé et audité |
| 5 | Ollama non testé contre un modèle réel en CI | Le contrat HTTP est testé avec un client simulé, pas la qualité des réponses | Non bloquant ; test manuel avec `--profile ai-local` |
| 6 | Fournisseur cloud non testé contre un compte réel | Même limite : contrat simulé, pas de validation de quota ni de coût | Non bloquant ; clé absente par défaut |
| 7 | Pas de rate limiting sur l'API interne AI | L'API est interne et protégée par jeton ; une saturation reste possible depuis un service compromis | Non bloquant ; limiteur partagé avant exposition |
| 8 | Pas de Mercure pour la progression | Le statut est consultable par polling | Non bloquant ; événements de progression en Phase 5 |
| 9 | Trace d'audit locale au service AI | Pas d'ancrage dans le journal d'audit du Core | Non bloquant ; corrélation par `correlation_id` à généraliser |
| 10 | Pas de test de charge du pipeline | Le débit et la latence sous concurrence ne sont pas mesurés | Non bloquant ; Phase 9 |
| 11 | `stub` activé en développement | Une configuration oubliée pourrait laisser le fournisseur déterministe actif en production | Accepté en dev ; `AI_ALLOW_STUB=0` obligatoire en production |
| 12 | RLS PostgreSQL non activée sur la base `ai` | L'isolation repose sur les requêtes filtrées du service | Non bloquant ; défense en profondeur prévue Phase 9 |

---

## 7. Verdict

**PASS**

Le critère de sortie est démontré : une demande produit une proposition structurée, explicable (payload, rationale, risques, citations, politique, trace d'agents) et **non publiée automatiquement**. Aucun statut `published` n'existe, aucune route de publication n'est exposée et le Déployeur refuse toute publication, y compris après approbation humaine.

Les contrôles de sécurité sont en place : jeton interne à échec fermé, isolation tenant sur chaque lecture et décision, RBAC déterministe, validation stricte des spécifications et des payloads, RAG filtré avant similarité, injections exclues du prompt, idempotence et reprises bornées.

Les réserves sont non bloquantes pour la Phase 4. Les plus structurantes — embeddings sémantiques avec index vectoriel, chiffrement au repos, rate limiting interne, ancrage d'audit et test de charge — restent à traiter avant une exposition à fort trafic ou à des données client sensibles.
