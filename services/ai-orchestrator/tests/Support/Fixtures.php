<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Agent\BuilderAgent;
use App\Entity\AiRequest;
use App\Proposal\FeatureDefinitionShapeValidator;
use Symfony\Component\Uid\Uuid;

final class Fixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function specification(): array
    {
        return [
            'key' => 'gestion_des_taches',
            'name' => 'Gestion des tâches',
            'description' => 'Suivi simple des tâches d’une équipe.',
            'fields' => [
                [
                    'name' => 'titre',
                    'type' => 'string',
                    'required' => true,
                    'title' => 'Titre',
                    'description' => 'Titre de la tâche.',
                ],
                [
                    'name' => 'details',
                    'type' => 'text',
                    'required' => false,
                    'title' => 'Détails',
                ],
                [
                    'name' => 'priorite',
                    'type' => 'select',
                    'required' => true,
                    'title' => 'Priorité',
                    'options' => ['basse', 'normale', 'haute'],
                ],
                [
                    'name' => 'echeance',
                    'type' => 'date',
                    'required' => false,
                    'title' => 'Échéance',
                ],
                [
                    'name' => 'terminee',
                    'type' => 'boolean',
                    'required' => false,
                    'title' => 'Terminée',
                ],
            ],
            'workflow' => [
                'initial' => 'brouillon',
                'states' => [
                    'brouillon' => ['label' => 'Brouillon'],
                    'ouvert' => ['label' => 'Ouvert'],
                    'clos' => ['label' => 'Clos'],
                ],
                'transitions' => [
                    [
                        'from' => 'brouillon',
                        'to' => 'ouvert',
                        'label' => 'Ouvrir',
                        'roles' => ['owner', 'admin', 'member'],
                    ],
                    [
                        'from' => 'ouvert',
                        'to' => 'clos',
                        'label' => 'Clore',
                        'roles' => ['owner', 'admin'],
                    ],
                ],
            ],
            'assumptions' => ['Un workflow à trois états suffit.'],
            'open_questions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $builder = new BuilderAgent(new FeatureDefinitionShapeValidator());

        return $builder->execute(self::specification())->data['payload'];
    }

    public static function request(
        ?Uuid $tenantId = null,
        string $prompt = 'Créer une gestion des tâches avec priorités et échéances.',
        string $actorRole = 'member',
    ): AiRequest {
        return new AiRequest(
            tenantId: $tenantId ?? Uuid::v4(),
            prompt: $prompt,
            idempotencyKey: 'test:'.Uuid::v4()->toRfc4122(),
            actorRole: $actorRole,
        );
    }
}
