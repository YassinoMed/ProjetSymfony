<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Tenant;

/**
 * Marque une entité dont les lignes appartiennent à un tenant.
 *
 * Le filtre Doctrine `tenant_filter` ajoute automatiquement une contrainte
 * `tenant_id = <tenant courant>` aux requêtes DQL/SQL portant sur ces entités.
 * Une entité peut retourner `null` uniquement pour des données plateforme
 * volontairement globales (elles restent invisibles lorsqu'un tenant est actif).
 */
interface TenantOwnedInterface
{
    public function getTenant(): ?Tenant;
}
