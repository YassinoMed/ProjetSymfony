# DECISIONS — Registre des décisions architecturales

| ID | Décision | Motif | Statut |
|---|---|---|---|
| ADR-001 | Monolithe modulaire pour le Core | Réduire la complexité initiale | Acceptée |
| ADR-002 | Métadonnées avant génération PHP | Sécurité et rapidité d'évolution | Acceptée |
| ADR-003 | PostgreSQL JSONB + pgvector | Données structurées et recherche sémantique | Acceptée |
| ADR-004 | RabbitMQ pour tâches durables | Découplage et reprise des tâches | Acceptée |
| ADR-005 | Redis pour cache/verrous | Ne pas confondre cache et file durable | Acceptée |
| ADR-006 | IA hybride via interface fournisseur | Flexibilité et résilience | Acceptée |
| ADR-007 | Sandbox séparée pour code généré | Protéger le Core et les données | Acceptée |
| ADR-008 | Approbation humaine des changements à risque | Gouvernance | Acceptée |
| ADR-009 | Mac local non critique pour la production | Éviter un point de défaillance | Acceptée |
| ADR-010 | 100 utilisateurs comme cible de benchmark | Dimensionnement initial vérifiable | À valider |

## Modifications
Toute modification d'une décision doit inclure le contexte, les options évaluées, les impacts, le plan de migration et les preuves.
