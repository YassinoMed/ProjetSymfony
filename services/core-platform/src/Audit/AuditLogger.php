<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\AuditLog;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\User;
use App\Http\CorrelationIdListener;
use App\Repository\AuditLogRepository;
use App\Tenant\TenantContext;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Écrit une entrée d'audit dans une transaction sérialisée par tenant.
 *
 * Le verrou `FOR UPDATE` sur la ligne du tenant garantit que deux écritures
 * concurrentes ne peuvent pas chaîner leurs empreintes sur le même prédécesseur.
 * L'écriture est atomique avec l'opération métier si l'appelant a déjà ouvert
 * une transaction.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository $logs,
        private readonly TenantContext $tenantContext,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(
        AuditAction $action,
        AuditResult $result,
        string $resourceType,
        ?string $resourceId = null,
        array $context = [],
    ): AuditLog {
        $tenant = $this->tenantContext->getTenant();
        $actor = $this->currentUser();
        $request = $this->requestStack->getCurrentRequest();
        $correlationId = $this->correlationId($request);

        $connection = $this->entityManager->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            if (null !== $tenant) {
                // Sérialise la chaîne d'audit de ce tenant jusqu'au commit.
                $connection->executeQuery(
                    'SELECT id FROM tenants WHERE id = :id FOR UPDATE',
                    ['id' => $tenant->getId()->toRfc4122()],
                    ['id' => Types::GUID],
                );
            }

            $log = new AuditLog(
                tenant: $tenant,
                actor: $actor,
                actorEmail: $actor?->getEmail() ?? 'anonymous',
                action: $action,
                result: $result,
                resourceType: $resourceType,
                resourceId: $resourceId,
                context: $this->withCorrelationId($context, $correlationId),
                ipAddress: $request?->getClientIp(),
                userAgent: $request?->headers->get('User-Agent'),
                correlationId: $correlationId,
                previousHash: $this->logs->findLatestHashForTenant($tenant),
            );

            $this->entityManager->persist($log);
            $this->entityManager->flush();

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return $log;
    }

    private function currentUser(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function correlationId(?Request $request): string
    {
        $candidate = $request?->attributes->get(CorrelationIdListener::ATTRIBUTE);
        if (is_string($candidate) && '' !== $candidate) {
            return $candidate;
        }

        $header = $request?->headers->get(CorrelationIdListener::HEADER);
        if (is_string($header) && 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $header)) {
            return $header;
        }

        return Uuid::v7()->toRfc4122();
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function withCorrelationId(array $context, string $correlationId): array
    {
        $context['correlation_id'] = $correlationId;

        return $context;
    }
}
