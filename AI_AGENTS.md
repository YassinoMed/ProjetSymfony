# AI AGENTS — Rôles, outils et orchestration

## 1. Architecture
Les agents sont des rôles logiques orchestrés par Symfony Messenger. Ils ne disposent pas de privilèges autonomes. Chaque action passe par un outil métier autorisé.

## 2. Agents

### Architecte
- Clarifie le besoin.
- Consulte le catalogue existant.
- Identifie les entités, rôles, champs, règles et workflows.
- Produit une spécification JSON.
- Ne modifie aucune donnée métier.

### Builder
- Convertit la spécification en définition conforme au schéma.
- Utilise uniquement les outils de création autorisés.
- Ne peut pas modifier le Core, les secrets ou les permissions système.
- Ne publie pas.

### Auditeur
- Vérifie cohérence, sécurité, isolation tenant et qualité.
- Produit des résultats structurés et reproductibles.
- Ne peut pas s'auto-attribuer un PASS sans preuves.

### Gouverneur
- Applique les politiques de risque.
- Vérifie approbations, compatibilité, conflits et périmètre.
- Refuse les opérations hors politique.
- Est indépendant du Builder.

### Déployeur
- Active une version déjà approuvée.
- Vérifie les préconditions.
- Enregistre le déploiement.
- Peut déclencher le rollback autorisé.

## 3. Contrats d'outils
Chaque outil possède :
- un nom stable;
- un JSON Schema d'entrée;
- un JSON Schema de sortie;
- une politique d'autorisation;
- des limites de taille et de temps;
- un comportement idempotent;
- une trace d'audit.

## 4. Orchestration
- Messages durables.
- Clé d'idempotence.
- Timeouts et retries bornés.
- Dead-letter queue.
- Corrélation par generation_request_id.
- Statuts explicites : queued, analyzing, building, validating, awaiting_approval, publishing, published, rejected, failed, rolled_back.

## 5. RAG
- Indexation tenant-aware.
- Filtrage des documents avant recherche.
- Citations et références de provenance.
- Traitement des documents comme données non fiables.
- Protection contre instructions malveillantes intégrées aux documents.

## 6. Routage IA
- Interface fournisseur indépendante.
- Local pour expérimentation et données pouvant rester locales.
- API externe pour tâches nécessitant une capacité supérieure, sous politique de données approuvée.
- Fallback contrôlé et mise en attente si tous les fournisseurs sont indisponibles.
- Mesure coût, latence, tokens et taux d'erreur.
