<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\Enum\MembershipRole;
use App\Entity\Enum\MembershipStatus;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Form\AddMemberType;
use App\Form\ChangeMemberRoleType;
use App\Form\RemoveMemberType;
use App\Repository\TenantMembershipRepository;
use App\Repository\UserRepository;
use App\Security\MembershipPolicy;
use App\Security\TenantVoter;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class MembershipController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly TenantMembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly MembershipPolicy $policy,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimiterFactoryInterface $tenantAdminLimiter,
    ) {
    }

    #[Route('/tenant/members', name: 'app_tenant_members', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->requireTenant();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);

        return $this->renderMembers($tenant);
    }

    #[Route('/tenant/members', name: 'app_tenant_member_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $tenant = $this->requireTenant();
        $this->denyAccessUnlessGranted(TenantVoter::MANAGE, $tenant);
        $this->enforceRateLimit();

        $actor = $this->requireMembership();
        $form = $this->createForm(AddMemberType::class, null, [
            'allowed_roles' => $this->policy->assignableRoles($actor->getRole()),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderMembers($tenant, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{email: string, role: MembershipRole} $data */
        $data = $form->getData();
        $role = $data['role'];

        if (!$this->policy->canAdd($actor->getRole(), $role)) {
            $this->auditLogger->log(AuditAction::MembershipAdd, AuditResult::Denied, 'tenant_membership', null, [
                'email' => $data['email'],
                'requested_role' => $role->value,
                'actor_role' => $actor->getRole()->value,
                'reason' => 'policy',
            ]);

            throw $this->createAccessDeniedException('Vous ne pouvez pas attribuer ce rôle.');
        }

        $user = $this->users->findOneByEmail($data['email']);
        if (null === $user) {
            $form->get('email')->addError(new FormError('Aucun compte utilisateur ne correspond à cet email.'));

            return $this->renderMembers($tenant, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$user->isActive()) {
            $form->get('email')->addError(new FormError('Ce compte utilisateur est inactif.'));

            return $this->renderMembers($tenant, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $this->memberships->findOneBy(['tenant' => $tenant, 'user' => $user]);
        if (null !== $existing && $existing->isActive()) {
            $form->get('email')->addError(new FormError('Cet utilisateur est déjà membre actif du tenant.'));

            return $this->renderMembers($tenant, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->wrapInTransaction(function () use ($tenant, $user, $role, $existing, $actor): void {
            if (null !== $existing) {
                $existing->setRole($role);
                $existing->setStatus(MembershipStatus::Active);
            } else {
                $this->entityManager->persist(new TenantMembership($tenant, $user, $role));
            }

            $this->auditLogger->log(AuditAction::MembershipAdd, AuditResult::Success, 'tenant_membership', $existing?->getId()->toRfc4122(), [
                'email' => $user->getEmail(),
                'role' => $role->value,
                'reactivated' => null !== $existing,
                'actor_role' => $actor->getRole()->value,
            ]);
        });

        $this->addFlash('success', sprintf('%s a été ajouté au tenant avec le rôle %s.', $user->getEmail(), $role->label()));

        return $this->redirectToRoute('app_tenant_members');
    }

    #[Route('/tenant/members/{id}/role', name: 'app_tenant_member_role', methods: ['POST'])]
    public function changeRole(string $id, Request $request): Response
    {
        $tenant = $this->requireTenant();
        $this->denyAccessUnlessGranted(TenantVoter::MANAGE, $tenant);
        $this->enforceRateLimit();

        $actor = $this->requireMembership();
        $membership = $this->findMembership($id, $tenant);
        $form = $this->createForm(ChangeMemberRoleType::class, ['role' => $membership->getRole()], [
            'action' => $this->generateUrl('app_tenant_member_role', ['id' => $membership->getId()->toRfc4122()]),
            'allowed_roles' => $this->policy->assignableRoles($actor->getRole()),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Le changement de rôle est invalide.');

            return $this->redirectToRoute('app_tenant_members');
        }

        /** @var array{role: MembershipRole} $data */
        $data = $form->getData();
        $newRole = $data['role'];
        $currentRole = $membership->getRole();
        $ownerCount = $this->memberships->countActiveOwners($tenant);

        $allowed = $this->policy->canChangeRole($actor->getRole(), $membership->getId()->equals($actor->getId()), $currentRole, $newRole)
            && $this->policy->preservesAtLeastOneOwner($currentRole, $newRole, $ownerCount);

        if (!$allowed) {
            $this->auditLogger->log(AuditAction::MembershipRoleChange, AuditResult::Denied, 'tenant_membership', $membership->getId()->toRfc4122(), [
                'previous_role' => $currentRole->value,
                'requested_role' => $newRole->value,
                'actor_role' => $actor->getRole()->value,
                'target_self' => $membership->getId()->equals($actor->getId()),
                'active_owners' => $ownerCount,
            ]);

            throw $this->createAccessDeniedException('Ce changement de rôle est interdit.');
        }

        if ($newRole === $currentRole) {
            $this->addFlash('info', 'Le rôle est inchangé.');

            return $this->redirectToRoute('app_tenant_members');
        }

        $this->entityManager->wrapInTransaction(function () use ($membership, $newRole, $currentRole, $actor): void {
            $membership->setRole($newRole);

            $this->auditLogger->log(AuditAction::MembershipRoleChange, AuditResult::Success, 'tenant_membership', $membership->getId()->toRfc4122(), [
                'previous_role' => $currentRole->value,
                'new_role' => $newRole->value,
                'actor_role' => $actor->getRole()->value,
            ]);
        });

        $this->addFlash('success', sprintf('Le rôle de %s est maintenant %s.', $membership->getUser()->getEmail(), $newRole->label()));

        return $this->redirectToRoute('app_tenant_members');
    }

    #[Route('/tenant/members/{id}/remove', name: 'app_tenant_member_remove', methods: ['POST'])]
    public function remove(string $id, Request $request): Response
    {
        $tenant = $this->requireTenant();
        $this->denyAccessUnlessGranted(TenantVoter::MANAGE, $tenant);
        $this->enforceRateLimit();

        $actor = $this->requireMembership();
        $membership = $this->findMembership($id, $tenant);
        $form = $this->createForm(RemoveMemberType::class, null, [
            'action' => $this->generateUrl('app_tenant_member_remove', ['id' => $membership->getId()->toRfc4122()]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Le retrait est invalide.');

            return $this->redirectToRoute('app_tenant_members');
        }

        $ownerCount = $this->memberships->countActiveOwners($tenant);
        $allowed = $this->policy->canRemove(
            $actor->getRole(),
            $membership->getId()->equals($actor->getId()),
            $membership->getRole(),
            $ownerCount,
        );

        if (!$allowed) {
            $this->auditLogger->log(AuditAction::MembershipRemove, AuditResult::Denied, 'tenant_membership', $membership->getId()->toRfc4122(), [
                'target_role' => $membership->getRole()->value,
                'actor_role' => $actor->getRole()->value,
                'target_self' => $membership->getId()->equals($actor->getId()),
                'active_owners' => $ownerCount,
            ]);

            throw $this->createAccessDeniedException('Ce retrait est interdit.');
        }

        $this->entityManager->wrapInTransaction(function () use ($membership, $actor): void {
            $membership->setStatus(MembershipStatus::Suspended);

            $this->auditLogger->log(AuditAction::MembershipRemove, AuditResult::Success, 'tenant_membership', $membership->getId()->toRfc4122(), [
                'target_email' => $membership->getUser()->getEmail(),
                'target_role' => $membership->getRole()->value,
                'actor_role' => $actor->getRole()->value,
            ]);
        });

        $this->addFlash('success', sprintf('%s a été retiré du tenant.', $membership->getUser()->getEmail()));

        return $this->redirectToRoute('app_tenant_members');
    }

    private function renderMembers(Tenant $tenant, ?FormInterface $addForm = null, int $status = Response::HTTP_OK): Response
    {
        $actor = $this->requireMembership();
        $canManage = $this->isGranted(TenantVoter::MANAGE, $tenant);
        $assignableRoles = $this->policy->assignableRoles($actor->getRole());

        $memberships = $this->memberships->findAllByTenant($tenant);
        $ownerCount = $this->memberships->countActiveOwners($tenant);
        $roleForms = [];
        $removeForms = [];

        if ($canManage) {
            foreach ($memberships as $membership) {
                if (!$membership->isActive()) {
                    continue;
                }

                $id = $membership->getId()->toRfc4122();
                $isSelf = $membership->getId()->equals($actor->getId());

                if (!$this->policy->canChangeRole($actor->getRole(), $isSelf, $membership->getRole(), $membership->getRole())) {
                    continue;
                }

                $roleForms[$id] = $this->createForm(ChangeMemberRoleType::class, ['role' => $membership->getRole()], [
                    'action' => $this->generateUrl('app_tenant_member_role', ['id' => $id]),
                    'allowed_roles' => $assignableRoles,
                ])->createView();

                if ($this->policy->canRemove($actor->getRole(), $isSelf, $membership->getRole(), $ownerCount)) {
                    $removeForms[$id] = $this->createForm(RemoveMemberType::class, null, [
                        'action' => $this->generateUrl('app_tenant_member_remove', ['id' => $id]),
                    ])->createView();
                }
            }
        }

        $addForm ??= $this->createForm(AddMemberType::class, null, ['allowed_roles' => $assignableRoles]);

        return $this->render('tenant/members.html.twig', [
            'tenant' => $tenant,
            'actor' => $actor,
            'memberships' => $memberships,
            'can_manage' => $canManage,
            'add_form' => $addForm->createView(),
            'role_forms' => $roleForms,
            'remove_forms' => $removeForms,
            'assignable_roles' => $assignableRoles,
        ], new Response(status: $status));
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

    private function findMembership(string $id, Tenant $tenant): TenantMembership
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Membre introuvable.');
        }

        $membership = $this->memberships->findOneByIdForTenant($uuid, $tenant);
        if (null === $membership) {
            // 404 volontaire : ne révèle pas l'existence d'une adhésion d'un autre tenant.
            throw new NotFoundHttpException('Membre introuvable.');
        }

        return $membership;
    }

    private function enforceRateLimit(): void
    {
        $tenant = $this->requireTenant();
        $actor = $this->requireMembership();
        $key = sprintf('%s|%s', $tenant->getId()->toRfc4122(), $actor->getId()->toRfc4122());

        $limit = $this->tenantAdminLimiter->create($key)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, 'Trop d\'opérations de gestion. Réessayez plus tard.');
        }
    }
}
