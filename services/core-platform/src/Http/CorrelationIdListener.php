<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Attribue un identifiant de corrélation à chaque requête.
 *
 * L'identifiant entrant est repris uniquement s'il est court et sûr ; sinon un
 * UUID est généré. Il est exposé à l'audit et renvoyé dans l'en-tête de réponse.
 */
final class CorrelationIdListener
{
    public const string HEADER = 'X-Request-Id';

    public const string ATTRIBUTE = '_correlation_id';

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $candidate = $request->headers->get(self::HEADER);

        if (!is_string($candidate) || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $candidate)) {
            $candidate = Uuid::v7()->toRfc4122();
        }

        $request->attributes->set(self::ATTRIBUTE, $candidate);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $correlationId = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (is_string($correlationId) && '' !== $correlationId) {
            $event->getResponse()->headers->set(self::HEADER, $correlationId);
        }
    }
}
