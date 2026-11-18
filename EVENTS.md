# EVENTS — Événements versionnés et Transactional Outbox

Les échanges asynchrones entre microservices s'appuient sur des événements versionnés publiés via RabbitMQ.

## 1. Catalogue des événements

| Événement | Producteur | Consommateur | Objet |
|---|---|---|---|
| `feature.generation.requested` | Core Platform | AI Orchestrator | Une demande de fonctionnalité est prête à être analysée |
| `feature.specification.created` | AI Orchestrator | Generation Service | Le cahier des charges JSON est produit |
| `feature.artifact.created` | Generation Service | Sandbox Runner | Un artefact est prêt à être validé |
| `feature.validation.completed` | Sandbox Runner | Gouverneur | Les tests et analyses sont terminés |
| `feature.approval.requested` | Gouverneur | Core Platform | Une approbation humaine est demandée |
| `feature.publication.requested` | Core Platform | Déployeur | Une version est prête à être publiée |
| `feature.published` | Core Platform | Notification | Une fonctionnalité est activée pour un tenant |

## 2. Structure d'un événement

Chaque événement contient :
- `event_id` : identifiant unique de l'événement.
- `event_type` : nom du contrat (ex. `feature.generation.requested`).
- `contract_version` : version du contrat d'événement.
- `tenant_id` : identifiant du tenant concerné.
- `correlation_id` : identifiant de corrélation pour tracer le flux.
- `timestamp` : horodatage d'émission.
- `payload` : données minimales et référence à l'artefact (pas de données sensibles inutiles).

## 3. Transactional Outbox

Pour éviter les incohérences entre l'enregistrement en base et l'envoi de l'événement RabbitMQ :
1. L'événement est écrit dans une table `outbox` dans la même transaction métier.
2. Un processus (polling ou CDC) lit la table et publie l'événement sur RabbitMQ.
3. L'événement est marqué comme publié ou supprimé après succès.

## 4. Idempotence

Les consommateurs traitent les événements de manière idempotente : un événement déjà traité ne doit pas produire d'effet de bord supplémentaire. Un identifiant de corrélation permet la détection des doublons.

## 5. Ordre et fiabilité

- RabbitMQ durable et supervisé, avec dead-letter queue pour les messages échoués.
- Les files sont consommées avec des retries bornés et des timeouts.
- Un message en échec est traçable et récupérable.
