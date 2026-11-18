# Sandbox Runner

Environnement isolé qui reçoit des artefacts et exécute des tests bornés : lint PHP, PHPStan, PHPUnit et scan de sécurité.

Aucun accès direct aux données de production. Chaque exécution est limitée en CPU, mémoire, disque et durée, avec un utilisateur non-root et le réseau désactivé par défaut.

## Lancer

```bash
composer install
php bin/sandbox list
php bin/sandbox php-lint <fichier>   # implémentation en Phase 6
```

## Image Docker

```bash
docker build -t aep/sandbox-runner .
docker run --rm --network none --memory 512m --cpus 1 aep/sandbox-runner list
```
