<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Tenant;
use App\Entity\TenantMembership;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Contexte de la requête courante : tenant actif et adhésion associée.
 *
 * Le contexte est réinitialisé entre deux requêtes (worker mode) et n'est
 * jamais considéré comme une source de vérité : chaque accès aux données
 * doit être vérifié côté serveur par le voter.
 */
final class TenantContext implements ResetInterface
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    public function setTenant(TenantMembership $membership): void
    {
        $this->membership = $membership;
        $this->tenant = $membership->getTenant();
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getMembership(): ?TenantMembership
    {
        return $this->membership;
    }

    public function hasTenant(): bool
    {
        return null !== $this->tenant;
    }

    public function getTenantId(): ?Uuid
    {
        return $this->tenant?->getId();
    }

    public function reset(): void
    {
        $this->tenant = null;
        $this->membership = null;
    }
}
