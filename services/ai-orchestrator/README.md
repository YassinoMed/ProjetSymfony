# AI Orchestrator

Service indépendant de coordination des agents IA : demandes en langage naturel, RAG filtré, routage des fournisseurs, propositions et décision humaine.

La base PostgreSQL `ai` est séparée du Core. Le service ne lit jamais les tables du Core : il reçoit des identifiants tenant/utilisateur opaques.

## Architecture

```
src/
├── Agent/          # Architecte, Builder, Auditeur, Gouverneur, Déployeur
├── Controller/     # API interne (aucune publication)
├── Entity/         # AiRequest, AiRun, AiProposal, RagDocument, RagChunk
├── Message/        # RunGenerationRequest + handler
├── Proposal/       # Pipeline, décision, validateurs
├── Provider/       # Contrat + stub, Ollama, compatible OpenAI
├── RAG/            # Indexation, recherche filtrée, injection, embeddings
├── Repository/     # Requêtes tenant, verrou, idempotence
└── Security/       # Jeton interne à échec fermé
```

## Pipeline

```
AiRequest (queued)
  → RAG filtré tenant + rôle
  → Architecte (fournisseur IA + validation)
  → Builder (déterministe)
  → Auditeur (preuves)
  → Gouverneur (politique)
  → AiProposal (awaiting_approval | rejected)
  → décision humaine (approved | rejected)
```

Aucun statut `published` n'existe et aucune route de publication n'est exposée.

## Lancer

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console messenger:setup-transports
php bin/console messenger:consume async -vv

# API interne
php -S 0.0.0.0:8080 -t public public/index.php
```

Avec Docker, l'API est exposée sur `http://127.0.0.1:8010` (`AI_HOST_PORT`).

## Variables

Voir `.env` et la section 13 de `GETTING_STARTED.md`. En production : `AI_ALLOW_STUB=0`, jeton interne long, clés injectées par le gestionnaire de secrets.

## Tests

```bash
export DATABASE_URL='postgresql://aep:aep_dev_password@127.0.0.1:5432/ai?serverVersion=17&charset=utf8'
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

En test, Messenger route vers `sync://` : les tests HTTP exécutent réellement le pipeline. Voir `PHASE4_REPORT.md` pour les preuves et réserves.
