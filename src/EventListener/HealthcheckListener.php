<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\EventListener;

use MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Runs the tagged checks on the raw configured path, before any application
 * request listener, without initializing the router or channel/locale state.
 */
final class HealthcheckListener
{
    public function __construct(private HealthcheckController $healthcheckController, private string $path)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !\in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)) {
            return;
        }

        if (rawurldecode($request->getPathInfo()) !== $this->path) {
            return;
        }

        // setResponse() already stops propagation (RequestEvent, Symfony 6-8).
        $event->setResponse(($this->healthcheckController)());
    }
}
