<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Controller;

use MonsieurBiz\HealthcheckBundle\Event\HealthcheckEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class HealthcheckController
{
    public function __construct(private EventDispatcherInterface $eventDispatcher)
    {
    }

    public function __invoke(): Response
    {
        $event = new HealthcheckEvent(new Response('OK', 200, ['Content-Type' => 'text/plain']));
        $this->eventDispatcher->dispatch($event);

        return $event->getResponse();
    }
}
