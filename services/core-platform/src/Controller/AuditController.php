<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Repository\AuditLogRepository;
use App\Security\TenantVoter;
use App\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuditController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogRepository $logs,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimiterFactoryInterface $auditViewLimiter,
    ) {
    }

    #[Route('/audit', name: 'app_audit', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->requireTenant();
        $this->denyAccessUnlessGranted(TenantVoter::AUDIT, $tenant);
        $membership = $this->requireMembership();
        $this->enforceRateLimit($tenant, $membership);

        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->logs->countForTenant($tenant);
        $entries = $this->logs->findPageForTenant($tenant, $page, self::PER_PAGE);

        $this->auditLogger->log(AuditAction::AuditViewed, AuditResult::Success, 'audit_log', null, [
            'page' => $page,
            'returned_entries' => count($entries),
        ]);

        return $this->render('audit/index.html.twig', [
            'tenant' => $tenant,
            'entries' => $entries,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'page_count' => max(1, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Aucun tenant actif pour cette requête.');
        }

        return $tenant;
    }

    private function requireMembership(): TenantMembership
    {
        $membership = $this->tenantContext->getMembership();
        if (null === $membership) {
            throw new AccessDeniedHttpException('Aucune adhésion active pour cette requête.');
        }

        return $membership;
    }

    private function enforceRateLimit(Tenant $tenant, TenantMembership $membership): void
    {
        $key = sprintf('%s|%s', $tenant->getId()->toRfc4122(), $membership->getId()->toRfc4122());
        $limit = $this->auditViewLimiter->create($key)->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, 'Trop de consultations du journal d\'audit. Réessayez plus tard.');
        }
    }
}
