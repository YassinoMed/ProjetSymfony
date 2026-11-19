<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * Fournisseur déterministe pour les tests, le développement et les démos.
 *
 * Il ne contacte aucun service externe et produit toujours la même
 * spécification pour une même demande. Il est désactivé hors développement via
 * `AI_ALLOW_STUB=0`.
 */
final class DeterministicProvider implements AiProviderInterface
{
    public function __construct(private readonly bool $enabled = true)
    {
    }

    public function name(): string
    {
        return 'stub';
    }

    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    public function complete(ProviderRequest $request): ProviderResponse
    {
        if ('architect' !== $request->agent) {
            throw new ProviderResponseException('Le fournisseur déterministe ne gère que l’agent Architecte.');
        }

        $key = $this->keyFromPrompt($request->userPrompt);
        $name = $this->shortName($request->userPrompt);

        $specification = [
            'key' => $key,
            'name' => $name,
            'description' => 'Proposition générée de façon déterministe à partir de la demande.',
            'fields' => [
                [
                    'name' => 'titre',
                    'type' => 'string',
                    'required' => true,
                    'title' => 'Titre',
                    'description' => 'Titre synthétique de l’élément.',
                ],
                [
                    'name' => 'description',
                    'type' => 'text',
                    'required' => false,
                    'title' => 'Description',
                    'description' => 'Description détaillée.',
                ],
                [
                    'name' => 'priorite',
                    'type' => 'select',
                    'required' => true,
                    'title' => 'Priorité',
                    'description' => 'Niveau de priorité.',
                    'options' => ['basse', 'normale', 'haute'],
                ],
                [
                    'name' => 'echeance',
                    'type' => 'date',
                    'required' => false,
                    'title' => 'Échéance',
                    'description' => 'Date cible.',
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
            'assumptions' => [
                'Un workflow simple à trois états suffit pour une première version.',
                'Les champs sont volontairement limités à des primitives validables par le Core.',
            ],
            'open_questions' => [],
        ];

        $content = json_encode($specification, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new ProviderResponse(
            provider: 'stub',
            model: 'deterministic-v1',
            content: $content,
            inputTokens: max(1, (int) ceil(\strlen($request->systemPrompt.$request->userPrompt) / 4)),
            outputTokens: max(1, (int) ceil(\strlen($content) / 4)),
            latencyMs: 0,
        );
    }

    private function keyFromPrompt(string $prompt): string
    {
        $source = strtr(mb_strtolower($prompt), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ]);

        $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $source));
        $key = trim((string) preg_replace('/_+/', '_', $key), '_');
        $key = mb_substr($key, 0, 40);
        $key = rtrim($key, '_');

        if ('' === $key) {
            return 'fonctionnalite';
        }

        if (1 !== preg_match('/^[a-z]/', $key)) {
            $key = 'f_'.$key;
            $key = mb_substr($key, 0, 40);
        }

        if (mb_strlen($key) < 2) {
            return 'fonctionnalite';
        }

        return $key;
    }

    private function shortName(string $prompt): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $prompt));
        if ('' === $normalized) {
            return 'Fonctionnalité';
        }

        return mb_substr(mb_strtoupper(mb_substr($normalized, 0, 1)).mb_substr($normalized, 1), 0, 120);
    }
}
