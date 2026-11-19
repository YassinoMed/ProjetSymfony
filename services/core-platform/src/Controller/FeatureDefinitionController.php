<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\FeatureDefinition;
use App\Entity\FeatureDefinitionVersion;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Feature\DefinitionValidationException;
use App\Feature\FeatureDefinitionDraft;
use App\Feature\FeatureDefinitionValidator;
use App\Feature\FeaturePolicy;
use App\Feature\FeatureSchema;
use App\Feature\WorkflowDefinition;
use App\Form\FeatureDefinitionType;
use App\Repository\DynamicRecordRepository;
use App\Repository\FeatureDefinitionRepository;
use App\Repository\FeatureDefinitionVersionRepository;
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

/**
 * Gestion des définitions de fonctionnalités dynamiques.
 *
 * Créer une fonctionnalité CRUD ne nécessite aucun nouveau fichier PHP : un
 * administrateur publie une définition JSON validée, le moteur fournit ensuite
 * la liste, le formulaire, le détail, les filtres et le workflow.
 */
final class FeatureDefinitionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly FeatureDefinitionRepository $definitions,
        private readonly FeatureDefinitionVersionRepository $versions,
        private readonly DynamicRecordRepository $records,
        private readonly FeatureDefinitionValidator $validator,
        private readonly FeaturePolicy $policy,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimiterFactoryInterface $featureWriteLimiter,
    ) {
    }

    #[Route('/features', name: 'app_features', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->requireTenant();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);
        $membership = $this->requireMembership();

        $features = [];
        foreach ($this->definitions->findAllByTenant($tenant) as $definition) {
            $version = $this->versions->findCurrent($definition);
            if (null === $version) {
                continue;
            }

            $schema = FeatureSchema::fromVersion($version);
            $workflow = new WorkflowDefinition($version->getWorkflow());

            $features[] = [
                'definition' => $definition,
                'version' => $version,
                'field_count' => \count($schema->fieldNames()),
                'state_count' => \count($workflow->states()),
                'record_count' => $this->records->countForDefinition($definition),
            ];
        }

        return $this->render('feature/index.html.twig', [
            'tenant' => $tenant,
            'features' => $features,
            'can_manage' => $this->policy->canManageDefinitions($membership->getRole()),
        ]);
    }

    #[Route('/features/new', name: 'app_feature_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant = $this->requireTenant();
        $membership = $this->requireMembership();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);

        if (!$this->policy->canManageDefinitions($membership->getRole())) {
            $this->denyDefinition(AuditAction::FeatureDefinitionCreate, $membership, null, 'policy');
        }

        if ($request->isMethod('POST')) {
            $this->enforceRateLimit($tenant, $membership);
        }

        $form = $this->createForm(FeatureDefinitionType::class, [
            'definition' => FeatureDefinitionType::EXAMPLE_JSON,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $draft = $this->validateSubmittedDefinition($form);
            if (null !== $draft) {
                $data = $form->getData();
                $key = mb_strtolower(trim((string) $data['key']));

                if (null !== $this->definitions->findOneByTenantAndKey($tenant, $key)) {
                    $form->get('key')->addError(new FormError('Cette clé est déjà utilisée dans ce tenant.'));

                    return $this->renderNew($tenant, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $user = $this->requireUser();
                $definition = null;

                $this->entityManager->wrapInTransaction(function () use ($tenant, $key, $data, $draft, $user, &$definition): void {
                    $definition = new FeatureDefinition($tenant, $key, (string) $data['name'], $data['description']);
                    $this->entityManager->persist($definition);
                    $this->entityManager->flush();

                    $version = new FeatureDefinitionVersion(
                        tenant: $tenant,
                        featureDefinition: $definition,
                        version: 1,
                        schema: $draft->normalizedSchema,
                        ui: $draft->normalizedUi,
                        workflow: $draft->normalizedWorkflow,
                        hash: $draft->hash,
                        createdBy: $user,
                    );
                    $this->entityManager->persist($version);

                    $this->auditLogger->log(AuditAction::FeatureDefinitionCreate, AuditResult::Success, 'feature_definition', $definition->getId()->toRfc4122(), [
                        'feature_key' => $definition->getKey(),
                        'version' => 1,
                        'hash' => $draft->hash,
                        'field_count' => \count($draft->schema->fieldNames()),
                        'state_count' => \count($draft->workflow->states()),
                    ]);
                });

                \assert($definition instanceof FeatureDefinition);
                $this->addFlash('success', sprintf('La fonctionnalité « %s » a été créée (version 1).', $definition->getName()));

                return $this->redirectToRoute('app_feature_records', ['key' => $definition->getKey()]);
            }
        }

        return $this->renderNew($tenant, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/features/{key}/edit', name: 'app_feature_edit', methods: ['GET', 'POST'])]
    public function edit(string $key, Request $request): Response
    {
        $tenant = $this->requireTenant();
        $membership = $this->requireMembership();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);

        if (!$this->policy->canManageDefinitions($membership->getRole())) {
            $this->denyDefinition(AuditAction::FeatureDefinitionVersionCreate, $membership, $key, 'policy');
        }

        if ($request->isMethod('POST')) {
            $this->enforceRateLimit($tenant, $membership);
        }

        $definition = $this->findDefinition($tenant, $key);
        $current = $this->versions->findCurrent($definition);
        if (null === $current) {
            throw new NotFoundHttpException('Version courante introuvable.');
        }

        $form = $this->createForm(FeatureDefinitionType::class, [
            'key' => $definition->getKey(),
            'name' => $definition->getName(),
            'description' => $definition->getDescription() ?? '',
            'definition' => $current->getPayloadJson(),
        ], ['lock_key' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $draft = $this->validateSubmittedDefinition($form, $definition->getKey());
            if (null !== $draft) {
                $data = $form->getData();
                $name = (string) $data['name'];
                $description = $data['description'];
                $user = $this->requireUser();

                $publishedVersion = null;
                $metadataUpdated = false;

                $this->entityManager->wrapInTransaction(function () use (
                    $tenant,
                    $definition,
                    $draft,
                    $name,
                    $description,
                    $user,
                    &$publishedVersion,
                    &$metadataUpdated,
                ): void {
                    $locked = $this->definitions->findForUpdate($definition->getId());
                    if (null === $locked) {
                        throw new NotFoundHttpException('Fonctionnalité introuvable.');
                    }

                    $currentLocked = $this->versions->findCurrent($locked);
                    if (null !== $currentLocked && $currentLocked->getHash() === $draft->hash) {
                        $changed = $locked->getName() !== trim($name)
                            || ($locked->getDescription() ?? '') !== trim((string) $description);

                        if ($changed) {
                            $locked->updateMetadata($name, $description);
                            $metadataUpdated = true;

                            $this->auditLogger->log(AuditAction::FeatureDefinitionUpdate, AuditResult::Success, 'feature_definition', $locked->getId()->toRfc4122(), [
                                'feature_key' => $locked->getKey(),
                                'version' => $locked->getCurrentVersion(),
                                'actor_role' => $this->tenantContext->getMembership()?->getRole()->value,
                            ]);
                        }

                        return;
                    }

                    $nextVersion = $locked->getCurrentVersion() + 1;
                    $version = new FeatureDefinitionVersion(
                        tenant: $tenant,
                        featureDefinition: $locked,
                        version: $nextVersion,
                        schema: $draft->normalizedSchema,
                        ui: $draft->normalizedUi,
                        workflow: $draft->normalizedWorkflow,
                        hash: $draft->hash,
                        createdBy: $user,
                    );
                    $this->entityManager->persist($version);
                    $locked->publishVersion($nextVersion, $name, $description);

                    $this->auditLogger->log(AuditAction::FeatureDefinitionVersionCreate, AuditResult::Success, 'feature_definition', $locked->getId()->toRfc4122(), [
                        'feature_key' => $locked->getKey(),
                        'version' => $nextVersion,
                        'hash' => $draft->hash,
                        'field_count' => \count($draft->schema->fieldNames()),
                        'state_count' => \count($draft->workflow->states()),
                    ]);

                    $publishedVersion = $nextVersion;
                });

                if (null !== $publishedVersion) {
                    $this->addFlash('success', sprintf('La version %d de « %s » a été publiée.', $publishedVersion, $name));

                    return $this->redirectToRoute('app_feature_records', ['key' => $definition->getKey()]);
                }

                if ($metadataUpdated) {
                    $this->addFlash('success', 'Les métadonnées ont été mises à jour ; le schéma est inchangé.');
                } else {
                    $this->addFlash('info', 'Aucun changement détecté : aucune nouvelle version n\'a été créée.');
                }

                return $this->redirectToRoute('app_feature_edit', ['key' => $definition->getKey()]);
            }
        }

        return $this->render('feature/edit.html.twig', [
            'tenant' => $tenant,
            'feature' => $definition,
            'version' => $current,
            'history' => $this->versions->findAllForDefinition($definition),
            'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function renderNew(Tenant $tenant, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('feature/new.html.twig', [
            'tenant' => $tenant,
            'form' => $form->createView(),
        ], new Response(status: $status));
    }

    private function validateSubmittedDefinition(FormInterface $form, ?string $key = null): ?FeatureDefinitionDraft
    {
        $raw = $form->get('definition')->getData();
        if (!\is_string($raw) || '' === trim($raw)) {
            $form->get('definition')->addError(new FormError('La définition JSON est obligatoire.'));

            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $form->get('definition')->addError(new FormError('Le contenu n\'est pas un JSON valide.'));

            return null;
        }

        if (!\is_array($decoded)) {
            $form->get('definition')->addError(new FormError('La définition doit être un objet JSON.'));

            return null;
        }

        $data = $form->getData();

        try {
            return $this->validator->validate(
                $key ?? (string) ($data['key'] ?? ''),
                (string) ($data['name'] ?? ''),
                isset($data['description']) && \is_string($data['description']) ? $data['description'] : null,
                $decoded,
            );
        } catch (DefinitionValidationException $exception) {
            foreach (\array_slice($exception->getErrors(), 0, 20) as $error) {
                $form->get('definition')->addError(new FormError($error));
            }

            return null;
        }
    }

    private function findDefinition(Tenant $tenant, string $key): FeatureDefinition
    {
        $definition = $this->definitions->findOneByTenantAndKey($tenant, $key);
        if (null === $definition) {
            throw new NotFoundHttpException('Fonctionnalité introuvable.');
        }

        return $definition;
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

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Utilisateur non authentifié.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function denyDefinition(AuditAction $action, TenantMembership $membership, ?string $key, string $reason): never
    {
        $this->auditLogger->log($action, AuditResult::Denied, 'feature_definition', null, [
            'feature_key' => $key,
            'actor_role' => $membership->getRole()->value,
            'reason' => $reason,
        ]);

        throw new AccessDeniedHttpException('Vous n\'avez pas la permission de gérer les définitions.');
    }

    private function enforceRateLimit(Tenant $tenant, TenantMembership $membership): void
    {
        $key = sprintf('%s|%s', $tenant->getId()->toRfc4122(), $membership->getId()->toRfc4122());
        $limit = $this->featureWriteLimiter->create($key)->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, 'Trop de mutations. Réessayez plus tard.');
        }
    }
}
