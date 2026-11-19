<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Protège l'API interne par un jeton partagé.
 *
 * Le jeton est comparé en temps constant. Sans jeton configuré, toutes les
 * routes `/internal/` sont refusées : le service échoue fermé.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class InternalTokenListener
{
    public function __construct(private readonly string $expectedToken)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/internal/')) {
            return;
        }

        $provided = (string) $request->headers->get('X-Internal-Token', '');
        $expected = trim($this->expectedToken);

        if ('' === $expected || !hash_equals($expected, $provided)) {
            $response = new JsonResponse([
                'error' => 'unauthorized',
                'message' => 'Jeton interne absent ou invalide.',
            ], 401);
            $response->headers->set('Cache-Control', 'no-store');

            $event->setResponse($response);
        }
    }
}
