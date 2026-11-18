# SECURITY — Sécurité, isolation et gouvernance

## 1. Principes
- Zero trust entre composants.
- Moindre privilège.
- Core protégé et non modifiable par les agents.
- Défense en profondeur.
- Séparation des environnements.
- Approbation humaine pour actions à impact élevé.
- Traçabilité des décisions et publications.

## 2. Isolation multi-tenant
- Contexte tenant déterminé côté serveur à partir de l'identité et de l'appartenance.
- Ne jamais faire confiance à un `tenant_id` fourni par le navigateur.
- Toutes les requêtes métier filtrées par tenant.
- PostgreSQL RLS comme défense supplémentaire, pas comme remplacement des contrôles applicatifs.
- Tests systématiques d'accès croisé.
- Cache, fichiers, exports, événements Mercure et index vectoriels cloisonnés par tenant.

## 3. Agents IA
- Aucun accès shell général.
- Aucun accès root.
- Outils allowlistés avec schémas d'entrée/sortie stricts.
- Autorisation déterministe avant chaque action.
- Les sorties du modèle sont des données non fiables.
- Validation JSON Schema et limites de taille.
- Protection contre prompt injection dans les documents RAG.
- Secrets jamais transmis au modèle.
- Quotas, timeouts, retries bornés et journalisation.

## 4. Sandbox de code
- Conteneur non privilégié.
- Utilisateur non-root.
- Filesystem éphémère et en lecture seule autant que possible.
- Pas de socket Docker monté.
- Réseau sortant bloqué par défaut.
- CPU, RAM, disque, processus et durée limités.
- Images signées et mises à jour.
- Dépendances verrouillées et scannées.
- Pas de secrets de production.
- Artefacts promus uniquement après tests et approbation.

## 5. Audit
Enregistrer : acteur, tenant, action, ressource, version, horodatage, résultat, correlation_id, empreinte précédente et empreinte courante.

Une chaîne SHA-256 seule ne garantit pas l'immutabilité si un attaquant contrôle la base. Ajouter des sauvegardes protégées, des permissions séparées et, si nécessaire, un ancrage externe signé.

## 6. Contrôles de sécurité
- Authentification forte pour administrateurs.
- RBAC et politiques d'accès.
- CSRF, XSS, injection SQL et SSRF.
- Rate limiting.
- CSP et cookies sécurisés.
- Rotation des secrets.
- Dépendances et images scannées.
- Tests SAST/DAST.
- Revue des journaux pour éviter les données personnelles et secrets.
