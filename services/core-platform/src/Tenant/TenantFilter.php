<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Filtre Doctrine d'isolation multi-tenant.
 *
 * Défense en profondeur : même si un contrôleur oublie de filtrer par tenant,
 * toute requête DQL/SQL sur une entité `TenantOwnedInterface` est restreinte au
 * tenant courant. Le filtre est activé par `TenantFilterManager` uniquement
 * après vérification serveur de l'adhésion (voir TenantContextListener).
 *
 * Si le filtre est activé sans tenant, il échoue de manière fermée (`1 = 0`)
 * plutôt que de laisser fuiter des lignes.
 */
final class TenantFilter extends SQLFilter
{
    public const string NAME = 'tenant_filter';

    private const string PARAMETER = 'tenant_id';

    private ?string $tenantId = null;

    public function setTenantId(string $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->setParameter(self::PARAMETER, $tenantId);
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Vérification par nom de classe : ne dépend pas de l'initialisation
        // de la réflexion Doctrine et reste stable pour les entités héritées.
        if (!is_subclass_of($targetEntity->getName(), TenantOwnedInterface::class)) {
            return '';
        }

        if (null === $this->tenantId) {
            return '1 = 0';
        }

        return sprintf('%s.tenant_id = %s', $targetTableAlias, $this->getParameter(self::PARAMETER));
    }
}
