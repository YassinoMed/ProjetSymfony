<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Entity\DynamicRecord;
use App\Entity\Enum\AuditAction;
use App\Entity\Enum\AuditResult;
use App\Entity\FeatureDefinition;
use App\Entity\FeatureDefinitionVersion;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Feature\FeaturePolicy;
use App\Feature\FeaturePresenter;
use App\Feature\FeatureSchema;
use App\Feature\RecordDataNormalizer;
use App\Feature\RecordDataValidator;
use App\Feature\WorkflowDefinition;
use App\Feature\WorkflowTransition;
use App\Form\DeleteRecordType;
use App\Form\DynamicRecordType;
use App\Form\RecordTransitionType;
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
use Symfony\Component\Uid\Uuid;

/**
 * CRUD générique et workflow des enregistrements dynamiques.
 *
 * Le contrôleur ne connaît aucun champ métier : tout est dérivé de la version
 * courante de la définition (JSON Schema, UI, workflow) et des politiques
 * déterministes. Ajouter une fonctionnalité CRUD ne crée donc aucun fichier
 * PHP supplémentaire.
 */
final class DynamicRecordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly FeatureDefinitionRepository $definitions,
        private readonly FeatureDefinitionVersionRepository $versions,
        private readonly DynamicRecordRepository $records,
        private readonly RecordDataValidator $dataValidator,
        private readonly RecordDataNormalizer $normalizer,
        private readonly FeaturePresenter $presenter,
        private readonly FeaturePolicy $policy,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimiterFactoryInterface $featureWriteLimiter,
    ) {
    }

    #[Route('/features/{key}/records', name: 'app_feature_records', methods: ['GET'])]
    public function index(string $key, Request $request): Response
    {
        $context = $this->loadContext($key);
        $schema = $context['schema'];
        $workflow = $context['workflow'];
        $membership = $context['membership'];

        $criteria = [
            'q' => trim((string) $request->query->get('q', '')),
            'status' => trim((string) $request->query->get('status', '')),
            'sort' => (string) $request->query->get('sort', 'created_at'),
            'dir' => 'asc' === mb_strtolower((string) $request->query->get('dir', 'desc')) ? 'asc' : 'desc',
            'page' => max(1, (int) $request->query->get('page', 1)),
            'per_page' => (int) $request->query->get('per_page', $schema->perPage()),
        ];

        if (!$workflow->hasState($criteria['status'])) {
            $criteria['status'] = '';
        }

        if (!$schema->isSortable($criteria['sort'])) {
            $criteria['sort'] = 'created_at';
        }

        $result = $this->records->search($context['definition'], $schema, $criteria);

        $rows = [];
        foreach ($result['items'] as $record) {
            $rows[] = [
                'record' => $record,
                'values' => $this->presenter->row($record, $schema, $workflow),
            ];
        }

        $statuses = [];
        foreach ($workflow->states() as $state => $label) {
            $statuses[$state] = $label;
        }

        $sortable = [];
        foreach ($schema->sortableFields() as $field) {
            $sortable[$field] = $this->presenter->label($schema, $field);
        }

        return $this->render('feature/records.html.twig', [
            'tenant' => $context['tenant'],
            'feature' => $context['definition'],
            'version' => $context['version'],
            'workflow' => $workflow,
            'columns' => $this->presenter->columns($schema),
            'rows' => $rows,
            'result' => $result,
            'criteria' => $criteria,
            'statuses' => $statuses,
            'sortable' => $sortable,
            'can_create' => $this->policy->canCreateRecord($membership->getRole()),
            'can_manage' => $this->policy->canManageDefinitions($membership->getRole()),
        ]);
    }

    #[Route('/features/{key}/records/new', name: 'app_feature_record_new', methods: ['GET', 'POST'])]
    public function new(string $key, Request $request): Response
    {
        $context = $this->loadContext($key);
        $membership = $context['membership'];
        $schema = $context['schema'];

        if (!$this->policy->canCreateRecord($membership->getRole())) {
            $this->denyRecord(AuditAction::DynamicRecordCreate, $context, null, 'policy');
        }

        if ($request->isMethod('POST')) {
            $this->enforceRateLimit($context['tenant'], $membership);
        }

        $form = $this->createForm(
            DynamicRecordType::class,
            $this->normalizer->formData($schema, $schema->defaults()),
            ['schema' => $schema],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $validated = $this->applyValidation($form, $schema);

            if (null !== $validated) {
                $user = $this->requireUser();
                $record = null;

                $this->entityManager->wrapInTransaction(function () use ($context, $validated, $user, &$record): void {
                    $record = new DynamicRecord(
                        tenant: $context['tenant'],
                        featureDefinition: $context['definition'],
                        schemaVersion: $context['version']->getVersion(),
                        data: $validated,
                        status: $context['workflow']->initial(),
                        createdBy: $user,
                    );
                    $this->entityManager->persist($record);

                    $this->auditLogger->log(
                        AuditAction::DynamicRecordCreate,
                        AuditResult::Success,
                        'dynamic_record',
                        $record->getId()->toRfc4122(),
                        $this->recordContext($context, $record) + [
                            'actor_role' => $context['membership']->getRole()->value,
                        ],
                    );
                });

                \assert($record instanceof DynamicRecord);
                $this->addFlash('success', 'L\'enregistrement a été créé.');

                return $this->redirectToRoute('app_feature_record_show', [
                    'key' => $context['definition']->getKey(),
                    'id' => $record->getId()->toRfc4122(),
                ]);
            }
        }

        return $this->renderRecordForm($context, $form, null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/features/{key}/records/{id}', name: 'app_feature_record_show', methods: ['GET'])]
    public function show(string $key, string $id): Response
    {
        $context = $this->loadContext($key);
        $record = $this->findRecord($id, $context['definition']);
        $membership = $context['membership'];

        $transitions = $this->policy->canUpdateRecord($membership->getRole())
            ? $context['workflow']->availableTransitions($record->getStatus(), $membership->getRole())
            : [];

        return $this->render('feature/record_show.html.twig', [
            'tenant' => $context['tenant'],
            'feature' => $context['definition'],
            'version' => $context['version'],
            'workflow' => $context['workflow'],
            'record' => $record,
            'detail_rows' => $this->presenter->detailRows($record, $context['schema'], $context['workflow']),
            'transitions' => $transitions,
            'can_update' => $this->policy->canUpdateRecord($membership->getRole()),
            'can_delete' => $this->policy->canDeleteRecord($membership->getRole()),
            'can_manage' => $this->policy->canManageDefinitions($membership->getRole()),
        ]);
    }

    #[Route('/features/{key}/records/{id}/edit', name: 'app_feature_record_edit', methods: ['GET', 'POST'])]
    public function edit(string $key, string $id, Request $request): Response
    {
        $context = $this->loadContext($key);
        $membership = $context['membership'];
        $schema = $context['schema'];

        if (!$this->policy->canUpdateRecord($membership->getRole())) {
            $this->denyRecord(AuditAction::DynamicRecordUpdate, $context, $this->uuidOrNull($id), 'policy');
        }

        if ($request->isMethod('POST')) {
            $this->enforceRateLimit($context['tenant'], $membership);
        }

        $record = $this->findRecord($id, $context['definition']);

        $form = $this->createForm(
            DynamicRecordType::class,
            $this->normalizer->formData($schema, $record->getData()),
            ['schema' => $schema],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $validated = $this->applyValidation($form, $schema);

            if (null !== $validated) {
                $before = $record->getData();
                $changed = [];
                foreach ($validated as $field => $value) {
                    if (($before[$field] ?? null) !== $value) {
                        $changed[] = $field;
                    }
                }
                foreach (array_keys($before) as $field) {
                    if (!\array_key_exists($field, $validated)) {
                        $changed[] = $field;
                    }
                }

                $this->entityManager->wrapInTransaction(function () use ($context, $record, $validated, $changed): void {
                    $record->updateData($validated, $context['version']->getVersion());

                    $this->auditLogger->log(
                        AuditAction::DynamicRecordUpdate,
                        AuditResult::Success,
                        'dynamic_record',
                        $record->getId()->toRfc4122(),
                        $this->recordContext($context, $record) + [
                            'actor_role' => $context['membership']->getRole()->value,
                            'changed_fields' => array_values(array_unique($changed)),
                        ],
                    );
                });

                $this->addFlash('success', 'L\'enregistrement a été mis à jour.');

                return $this->redirectToRoute('app_feature_record_show', [
                    'key' => $context['definition']->getKey(),
                    'id' => $record->getId()->toRfc4122(),
                ]);
            }
        }

        return $this->renderRecordForm($context, $form, $record, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/features/{key}/records/{id}/delete', name: 'app_feature_record_delete', methods: ['POST'])]
    public function delete(string $key, string $id, Request $request): Response
    {
        $context = $this->loadContext($key);
        $membership = $context['membership'];

        if (!$this->policy->canDeleteRecord($membership->getRole())) {
            $this->denyRecord(AuditAction::DynamicRecordDelete, $context, $this->uuidOrNull($id), 'policy');
        }

        $this->enforceRateLimit($context['tenant'], $membership);
        $record = $this->findRecord($id, $context['definition']);

        $form = $this->createForm(DeleteRecordType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'La suppression est invalide.');

            return $this->redirectToRoute('app_feature_record_show', [
                'key' => $context['definition']->getKey(),
                'id' => $record->getId()->toRfc4122(),
            ]);
        }

        $this->entityManager->wrapInTransaction(function () use ($context, $record): void {
            $this->entityManager->remove($record);

            $this->auditLogger->log(
                AuditAction::DynamicRecordDelete,
                AuditResult::Success,
                'dynamic_record',
                $record->getId()->toRfc4122(),
                $this->recordContext($context, $record) + [
                    'actor_role' => $context['membership']->getRole()->value,
                ],
            );
        });

        $this->addFlash('success', 'L\'enregistrement a été supprimé.');

        return $this->redirectToRoute('app_feature_records', ['key' => $context['definition']->getKey()]);
    }

    #[Route('/features/{key}/records/{id}/transition', name: 'app_feature_record_transition', methods: ['POST'])]
    public function transition(string $key, string $id, Request $request): Response
    {
        $context = $this->loadContext($key);
        $membership = $context['membership'];

        if (!$this->policy->canUpdateRecord($membership->getRole())) {
            $this->denyRecord(AuditAction::DynamicRecordTransition, $context, $this->uuidOrNull($id), 'policy');
        }

        $this->enforceRateLimit($context['tenant'], $membership);
        $record = $this->findRecord($id, $context['definition']);

        $form = $this->createForm(RecordTransitionType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'La transition est invalide.');

            return $this->redirectToRoute('app_feature_record_show', [
                'key' => $context['definition']->getKey(),
                'id' => $record->getId()->toRfc4122(),
            ]);
        }

        /** @var array{from?: string, to?: string} $data */
        $data = $form->getData();
        $from = (string) ($data['from'] ?? '');
        $to = (string) ($data['to'] ?? '');

        if ($from !== $record->getStatus()) {
            $this->addFlash('error', 'L\'état de l\'enregistrement a changé. Rechargez la page avant de réessayer.');

            return $this->redirectToRoute('app_feature_record_show', [
                'key' => $context['definition']->getKey(),
                'id' => $record->getId()->toRfc4122(),
            ]);
        }

        $transition = $context['workflow']->findTransition($from, $to);
        if (null === $transition || !$this->policy->canTransition($membership->getRole(), $transition)) {
            $this->auditLogger->log(AuditAction::DynamicRecordTransition, AuditResult::Denied, 'dynamic_record', $record->getId()->toRfc4122(), [
                'feature_key' => $context['definition']->getKey(),
                'from' => $from,
                'to' => $to,
                'actor_role' => $membership->getRole()->value,
                'reason' => 'workflow',
            ]);

            throw new AccessDeniedHttpException('Cette transition n\'est pas autorisée pour votre rôle.');
        }

        $this->entityManager->wrapInTransaction(function () use ($context, $record, $transition): void {
            $record->transitionTo($transition->to);

            $this->auditLogger->log(
                AuditAction::DynamicRecordTransition,
                AuditResult::Success,
                'dynamic_record',
                $record->getId()->toRfc4122(),
                $this->recordContext($context, $record) + [
                    'actor_role' => $context['membership']->getRole()->value,
                    'from' => $transition->from,
                    'to' => $transition->to,
                ],
            );
        });

        $this->addFlash('success', sprintf('Transition « %s » appliquée.', $transition->label));

        return $this->redirectToRoute('app_feature_record_show', [
            'key' => $context['definition']->getKey(),
            'id' => $record->getId()->toRfc4122(),
        ]);
    }

    /**
     * @return array{
     *     tenant: Tenant,
     *     membership: TenantMembership,
     *     definition: FeatureDefinition,
     *     version: FeatureDefinitionVersion,
     *     schema: FeatureSchema,
     *     workflow: WorkflowDefinition
     * }
     */
    private function loadContext(string $key): array
    {
        $tenant = $this->requireTenant();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);
        $membership = $this->requireMembership();

        $definition = $this->definitions->findOneByTenantAndKey($tenant, $key);
        if (null === $definition) {
            throw new NotFoundHttpException('Fonctionnalité introuvable.');
        }

        $version = $this->versions->findCurrent($definition);
        if (null === $version) {
            throw new NotFoundHttpException('Version courante introuvable.');
        }

        return [
            'tenant' => $tenant,
            'membership' => $membership,
            'definition' => $definition,
            'version' => $version,
            'schema' => FeatureSchema::fromVersion($version),
            'workflow' => new WorkflowDefinition($version->getWorkflow()),
        ];
    }

    private function findRecord(string $id, FeatureDefinition $definition): DynamicRecord
    {
        $uuid = $this->uuidOrNull($id);

        if (null === $uuid) {
            throw new NotFoundHttpException('Enregistrement introuvable.');
        }

        $record = $this->records->findOneForDefinition($uuid, $definition);
        if (null === $record) {
            throw new NotFoundHttpException('Enregistrement introuvable.');
        }

        return $record;
    }

    private function uuidOrNull(string $value): ?Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Valide les données du formulaire et rattache les erreurs aux champs.
     *
     * @return array<string, mixed>|null Données normalisées si valides.
     */
    private function applyValidation(FormInterface $form, FeatureSchema $schema): ?array
    {
        if (!$form->isValid()) {
            return null;
        }

        $data = $form->getData();
        $result = $this->dataValidator->validate($schema, \is_array($data) ? $data : []);

        if ([] !== $result['errors']) {
            foreach ($result['errors'] as $field => $message) {
                if (\is_string($field) && $form->has($field)) {
                    $form->get($field)->addError(new FormError($message));
                } else {
                    $form->addError(new FormError($message));
                }
            }

            return null;
        }

        return $result['data'];
    }

    /**
     * @param array{
     *     tenant: Tenant,
     *     membership: TenantMembership,
     *     definition: FeatureDefinition,
     *     version: FeatureDefinitionVersion,
     *     schema: FeatureSchema,
     *     workflow: WorkflowDefinition
     * } $context
     */
    private function renderRecordForm(array $context, FormInterface $form, ?DynamicRecord $record, int $status): Response
    {
        $membership = $context['membership'];

        return $this->render('feature/record_form.html.twig', [
            'tenant' => $context['tenant'],
            'feature' => $context['definition'],
            'version' => $context['version'],
            'schema' => $context['schema'],
            'workflow' => $context['workflow'],
            'form' => $form->createView(),
            'record' => $record,
            'is_edit' => null !== $record,
        ], new Response(status: $status));
    }

    /**
     * @param array{
     *     tenant: Tenant,
     *     membership: TenantMembership,
     *     definition: FeatureDefinition,
     *     version: FeatureDefinitionVersion,
     *     schema: FeatureSchema,
     *     workflow: WorkflowDefinition
     * } $context
     * @return array<string, mixed>
     */
    private function recordContext(array $context, DynamicRecord $record): array
    {
        return [
            'feature_key' => $context['definition']->getKey(),
            'record_id' => $record->getId()->toRfc4122(),
            'status' => $record->getStatus(),
            'schema_version' => $record->getSchemaVersion(),
        ];
    }

    /**
     * @param array{
     *     tenant: Tenant,
     *     membership: TenantMembership,
     *     definition: FeatureDefinition,
     *     version: FeatureDefinitionVersion,
     *     schema: FeatureSchema,
     *     workflow: WorkflowDefinition
     * } $context
     */
    private function denyRecord(AuditAction $action, array $context, ?Uuid $recordId, string $reason): never
    {
        $this->auditLogger->log($action, AuditResult::Denied, 'dynamic_record', $recordId?->toRfc4122(), [
            'feature_key' => $context['definition']->getKey(),
            'actor_role' => $context['membership']->getRole()->value,
            'reason' => $reason,
        ]);

        throw new AccessDeniedHttpException('Vous n\'avez pas la permission d\'effectuer cette action.');
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
