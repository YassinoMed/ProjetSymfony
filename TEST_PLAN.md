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
