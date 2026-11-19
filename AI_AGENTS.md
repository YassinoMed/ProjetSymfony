# AI AGENTS — Rôles, outils et orchestration

## 1. Architecture

Les agents sont des rôles logiques orchestrés par Symfony Messenger dans `services/ai-orchestrator`. Ils ne disposent d'aucun privilège autonome : chaque agent reçoit des données, produit un résultat structuré, et aucune sortie n'est exécutée comme du code.

La base `ai` est séparée de la base `core`. Le service ne lit jamais les tables du Core ; il reçoit des identifiants tenant/utilisateur opaques et retourne une proposition que le Core pourra exploiter en Phase 5.

Pipeline :

```
AiRequest (queued)
  → RAG filtré (tenant + rôle, injection exclue)
  → Architecte (fournisseur IA + validation)
  → Builder (déterministe)
  → Auditeur (déterministe, preuves)
  → Gouverneur (politique + approbation humaine)
  → AiProposal (awaiting_approval | rejected)
  → décision humaine (approved | rejected)
```

Chaque étape est tracée dans `ai_runs` : agent, statut, fournisseur, modèle, latence, jetons, résumé ou erreur.

## 2. Agents

### Architecte
- Reçoit le prompt, le rôle acteur et le contexte RAG.
- Consulte le fournisseur selon `AI_PROVIDER_CHAIN` : `stub`, `ollama`, cloud compatible OpenAI.
- Produit une spécification JSON : `key`, `name`, `description`, `fields`, `workflow`, `assumptions`, `open_questions`.
- Types autorisés : `string`, `integer`, `number`, `boolean`, `date`, `datetime`, `select`, `text`.
- La sortie est validée par `ArchitectSpecificationValidator` (JSON Schema Opis + contrôles sémantiques) avant d'atteindre le Builder.
- Ne persiste rien, ne publie pas, n'appelle aucun outil métier.

### Builder
- Déterministe, sans fournisseur IA.
- Convertit la spécification en payload `feature_definition` conforme à l'allowlist du Core : `schema`, `ui`, `workflow`.
- Traduit les types de l'Architecte vers les types du Core (`date`, `email`, `uri`, `array`).
- Le payload est revérifié par `FeatureDefinitionShapeValidator`.
- Ne génère pas de PHP, ne lit aucun secret, ne publie pas.

### Auditeur
- Déterministe, sans fournisseur IA.
- Produit six contrôles : structure, champs réservés, tailles et volumes, instructions de publication, injection RAG, vocabulaire sensible.
- Chaque constat porte un identifiant stable (`AUD-01`…`AUD-06`), une sévérité (`low`, `medium`, `high`) et une preuve.
- Ne peut pas s'auto-attribuer un PASS : le résultat contient `passed`, `failures`, `warnings` et la liste des contrôles.

### Gouverneur
- Déterministe, indépendant du Builder.
- Applique la politique : `requires_human_approval` toujours `true`, `publication` toujours `forbidden`.
- Statut `awaiting_approval` si l'audit passe et qu'aucune injection de sévérité haute n'est détectée ; sinon `rejected`.
- Niveau de risque : `low` sans constat, `medium` avec avertissements, `high` avec échec.
- Refuse les opérations hors politique.

### Déployeur
- Déterministe, en **préflight uniquement**.
- Vérifie l'état de la proposition et les préconditions.
- Le contrôle de publication échoue systématiquement, y compris pour une proposition `approved`.
- Aucune activation, aucun rollback et aucune publication ne sont implémentés en Phase 4.

## 3. Contrat de fournisseur

```php
interface AiProviderInterface
{
    public function name(): string;
    public function isConfigured(): bool;
    public function complete(ProviderRequest $request): ProviderResponse;
}
```

- `ProviderRequest` : prompt système, prompt utilisateur, identifiant de corrélation, modèle, délai maximal.
- `ProviderResponse` : texte, fournisseur, modèle, latence, jetons d'entrée/sortie.
- `ProviderRegistry` parcourt `AI_PROVIDER_CHAIN`, ignore les fournisseurs non configurés et ne bascule sur le suivant qu'en cas d'indisponibilité ou de réponse inexploitable.
- Une panne totale lève `ProviderUnavailableException` : la demande reste récupérable ou passe `failed` après épuisement des tentatives.
- Aucun fournisseur ne reçoit de secret, de jeton interne ni de contenu d'un autre tenant.

## 4. Orchestration

- Message `RunGenerationRequest` (identifiant de demande UUID).
- `RunGenerationHandler` verrouille la demande (`SELECT … FOR UPDATE`) et ne la traite que si elle est encore `queued` : un doublon ou un worker concurrent s'arrête sans effet.
- Reprises bornées par `AI_MAX_ATTEMPTS` (3 par défaut), puis `failed` avec erreur bornée.
- Idempotence HTTP : contrainte unique `(tenant_id, idempotency_key)` ; une clé rejouée renvoie la demande existante.
- Une seule proposition par demande (`UNIQUE (request_id)`).
- Statuts de demande : `queued`, `analyzing`, `building`, `validating`, `awaiting_approval`, `approved`, `rejected`, `failed`. **Aucun statut `published`**.
- Statuts de proposition : `awaiting_approval`, `approved`, `rejected`. **Aucun statut `published`**.

## 5. RAG

- Indexation tenant-aware, empreinte SHA-256, idempotence par `(tenant_id, checksum)`.
- Rôles autorisés : `owner`, `admin`, `member`, `auditor`.
- Le filtre SQL **tenant + rôle** est appliqué avant le calcul de similarité.
- Embeddings déterministes par défaut, Ollama optionnel ; les dimensions incompatibles sont ignorées.
- Les documents sont traités comme des **données non fiables** : le prompt les encadre par `<contexte_interne_non_fiable>`.
- `PromptInjectionScanner` détecte instructions ignorées, changement de rôle, mode développeur, exfiltration, identifiants et Unicode invisible.
- Un fragment détecté est exclu du prompt mais conservé dans les constats d'audit et de gouvernance.
- `RagContext::citations()` expose les sources dans la proposition.

## 6. Routage IA

- `stub` : développement et tests, reproductible, aucun réseau.
- `ollama` : modèle local, données pouvant rester sur l'infrastructure.
- Cloud compatible OpenAI : tâches à capacité supérieure, sous politique de données approuvée, désactivé par défaut.
- Repli contrôlé ; mise en attente ou échec explicite si tous les fournisseurs sont indisponibles.
- Mesure de la latence et des jetons dans `ai_runs` et `provider_meta`.

## 7. Décision humaine

- `POST /internal/ai/proposals/{id}/decision` avec `approve` ou `reject` et un motif de 10 à 1000 caractères.
- La proposition est recherchée par identifiant **et** tenant ; un accès croisé renvoie `404`.
- Une seconde décision renvoie `409`.
- L'acteur et l'horodatage sont enregistrés.
- Une approbation **ne publie rien** : elle autorise seulement une phase ultérieure.

## 8. Garde-fous

- Jeton interne `X-Internal-Token` à échec fermé sur toutes les routes `/internal/*`.
- En-têtes `X-Tenant-Id`, `X-User-Id`, `X-Actor-Role` validés.
- Corps JSON strict, clés inconnues refusées (y compris `published`).
- Aucune route de publication.
- Aucun accès shell, root, réseau de production ou secret depuis un agent.
- Sorties de modèle traitées comme des données non fiables, validées avant persistance.
