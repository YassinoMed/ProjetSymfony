# Generation Service

Transforme les propositions validées de l'IA en fonctionnalités exploitables : schémas JSON, formulaires, workflows, CRUD, classes PHP via AST et artefacts versionnés.

Deux moteurs :

1. **Dynamic Generator** (normal) : schémas, formulaires et workflows.
2. **Code Generator** (avancé) : classes PHP via AST, soumis à des contrôles stricts.

## Lancer

```bash
composer install
php bin/console messenger:setup-transports
php bin/console messenger:consume async -vv
```
