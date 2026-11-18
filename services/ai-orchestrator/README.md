# AI Orchestrator

Service indépendant de coordination des agents IA : demandes en langage naturel, RAG, prompts, routage des modèles et suivi des coûts.

Les cinq agents sont des composants logiques internes (pas des microservices séparés) :

```
src/Agents/
├── ArchitectAgent/
├── BuilderAgent/
├── AuditorAgent/
├── GovernorAgent/
└── DeployerAgent/
```

## Lancer

```bash
composer install
php bin/console messenger:setup-transports
php bin/console messenger:consume async -vv
```

Les fournisseurs (OpenAI, Ollama, autres) seront branchés en Phase 4 via une interface commune.
