# TEST PLAN — Stratégie de validation

## 1. Pyramide de tests
- Unitaires : règles métier, schémas, politiques et transitions.
- Intégration : Doctrine, PostgreSQL, Messenger, Redis et Mercure.
- Fonctionnels : parcours utilisateur.
- Sécurité : isolation tenant, RBAC, IDOR, injections, CSRF, SSRF.
- Contrats : fournisseurs IA et outils agents.
- Charge : k6 ou outil équivalent.
- Résilience : panne de services, retries et restauration.

## 2. Scénarios critiques
1. Tenant A ne peut pas lire un enregistrement de tenant B.
2. Un utilisateur sans rôle ne peut pas créer ou publier une fonctionnalité.
3. Un schéma JSON invalide est rejeté.
4. Une définition contenant un type ou opérateur interdit est rejetée.
5. Une tâche rejouée avec la même clé d'idempotence ne crée pas de doublon.
6. Une panne du fournisseur IA laisse la demande en état récupérable.
7. Un agent Builder ne peut pas appeler une commande non autorisée.
8. Une publication sans approbation requise est refusée.
9. Un rollback réactive une version connue et compatible.
10. Une notification Mercure ne fuit aucune donnée entre tenants.
11. Le code généré ne peut pas accéder aux secrets ni au réseau de production.
12. Les journaux ne contiennent ni mots de passe, ni tokens, ni prompts sensibles non nécessaires.

## 3. Charge de référence
- 100 utilisateurs virtuels sur navigation et lecture.
- 50 utilisateurs sur formulaires dynamiques.
- 10 demandes de génération soumises simultanément.
- 5 à 10 tâches IA concurrentes, avec limites fournisseur.
- 100 connexions Mercure.
- Test de montée progressive, plateau et pic.

## 4. SLO initiaux à mesurer
- API métier P95 < 500 ms sous charge convenue, hors IA.
- Erreurs 5xx < 0,1 % sur scénario stable.
- Aucune perte de tâche confirmée.
- Aucun accès inter-tenant.
- Temps de reprise et rollback mesurés.

## 5. Preuves attendues
Pour chaque campagne :
- commit testé;
- version des conteneurs;
- configuration matérielle;
- jeu de données;
- script de charge;
- résultats bruts;
- métriques CPU/RAM/DB/queues;
- anomalies et limites;
- verdict PASS, PASS_WITH_RESERVATIONS ou FAIL.

## 6. Règle de verdict
PASS uniquement si tous les critères obligatoires sont démontrés. Une absence de test ou de preuve ne vaut pas PASS. Les réserves doivent être explicites et bloquantes ou non bloquantes.

## 7. Phase 4 — Orchestrateur IA

### Commandes

```bash
cd services/ai-orchestrator
export DATABASE_URL='postgresql://aep:aep_dev_password@127.0.0.1:5432/ai?serverVersion=17&charset=utf8'
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

### Couverture livrée

| Suite | Tests | Assertions | Objet |
|---|---:|---:|---|
| `tests/Unit` | 80 | 144 | Fournisseurs, validateurs, agents, RAG, vecteurs |
| `tests/Integration` | 23 | 80 | RAG, pipeline, décision, handler, PostgreSQL |
| `tests/Controller` | 14 | 71 | API interne, jeton, en-têtes, idempotence, cross-tenant |
| **Total** | **117** | **295** | |

### Scénarios Phase 4 démontrés

1. Une demande produit une proposition `awaiting_approval` avec payload, rationale, risques, citations, politique et trace des quatre agents.
2. `published` vaut `false`, `publication` vaut `forbidden`, et aucun statut `published` n'existe en base.
3. Aucune route `/publish` n'existe (`404`).
4. Une proposition approuvée reste non publiée ; le Déployeur refuse toute publication.
5. Un jeton interne absent ou invalide renvoie `401`.
6. Un accès inter-tenant renvoie `404` pour une demande comme pour une proposition.
7. Une clé d'idempotence rejouée par le même tenant ne crée pas de doublon ; la même clé dans un autre tenant crée une demande distincte.
8. Un message dupliqué ne crée pas de seconde proposition ; le verrou pessimiste empêche deux workers de traiter la même demande.
9. Une panne fournisseur est reprise jusqu'à `AI_MAX_ATTEMPTS`, puis la demande passe `failed` avec l'erreur conservée.
10. Un document RAG réservé à `owner` n'est pas retourné à un `member`.
11. Un fragment contenant une injection est exclu du prompt mais signalé à l'auditeur et au gouverneur.
12. Une spécification hors allowlist, un champ réservé, un état ou un rôle inconnu sont refusés avant persistance.
13. Une seconde décision sur la même proposition renvoie `409`.
14. Un motif de décision absent ou hors bornes renvoie `400`.

### Preuves attendues

- sortie `OK (117 tests, 295 assertions)` ;
- migration up/down/re-up de `Version20261119090000` ;
- `lint:container`, `lint:yaml`, `doctrine:schema:validate` ;
- `GET /health` → `publication: forbidden` ;
- `POST /internal/ai/requests` → `202`, proposition non publiée ;
- `POST /internal/ai/proposals/{id}/publish` → `404` ;
- `composer audit` sans avis.

Les preuves détaillées et les réserves sont dans `PHASE4_REPORT.md`.
