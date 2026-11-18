# Core Platform

Service central Symfony 8 (PHP 8.5) : utilisateurs, tenants, permissions, catalogue de fonctionnalités, moteur dynamique, workflows, gouvernance, audit, facturation et notifications.

Il ne dépend pas des services IA et reste fonctionnel lorsque ceux-ci sont indisponibles.

## Modules

```
src/
├── Identity/
├── Authentication/
├── Authorization/
├── Tenant/
├── FeatureRegistry/
├── DynamicEngine/
├── Workflow/
├── Governance/
├── Audit/
├── Billing/
└── Notification/
```

## Lancer

```bash
composer install
php bin/console messenger:setup-transports
symfony serve -d          # http://localhost:8000
# ou
docker compose --profile app up --build
```

## Tests

```bash
php bin/phpunit
```
