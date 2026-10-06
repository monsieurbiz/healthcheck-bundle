<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Tests\Unit;

use MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController;
use MonsieurBiz\HealthcheckBundle\Event\HealthcheckEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Response;

final class HealthcheckControllerTest extends TestCase
{
    public function testInitialResponseIsDispatchedUnderTheEventClassName(): void
    {
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(HealthcheckEvent::class, static function (HealthcheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $response = (new HealthcheckController($dispatcher))();

        self::assertSame('OK', $response->getContent());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertCount(1, $events);
        self::assertSame($response, $events[0]->getResponse());
    }

    public function testListenerCanMutateTheInitialResponse(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(HealthcheckEvent::class, static function (HealthcheckEvent $event): void {
            $event->getResponse()->setContent('{"status":"degraded"}');
            $event->getResponse()->setStatusCode(503);
            $event->getResponse()->headers->set('Content-Type', 'application/json');
        });

        $response = (new HealthcheckController($dispatcher))();

        self::assertSame('{"status":"degraded"}', $response->getContent());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testListenerCanReplaceTheResponse(): void
    {
        $dispatcher = new EventDispatcher();
        $replacement = new Response('Maintenance', 503, ['Retry-After' => '60']);
        $dispatcher->addListener(HealthcheckEvent::class, static function (HealthcheckEvent $event) use ($replacement): void {
            $event->setResponse($replacement);
        });

        self::assertSame($replacement, (new HealthcheckController($dispatcher))());
    }

    public function testListenerExceptionIsNotCaughtByTheController(): void
    {
        $dispatcher = new EventDispatcher();
        $failure = new \RuntimeException('Probe failed');
        $dispatcher->addListener(HealthcheckEvent::class, static function () use ($failure): void {
            throw $failure;
        });

        $this->expectExceptionObject($failure);

        (new HealthcheckController($dispatcher))();
    }
}
