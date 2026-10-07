<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Tests\Unit;

use MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController;
use MonsieurBiz\HealthcheckBundle\EventListener\HealthcheckListener;
use MonsieurBiz\HealthcheckBundle\Tests\Fixture\RecordingLogger;
use MonsieurBiz\HealthcheckBundle\Tests\Fixture\TestCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HealthcheckListenerTest extends TestCase
{
    public function testMainGetAndHeadRunChecksAndStopRequestPropagationWithoutSettingARoute(): void
    {
        $check = new TestCheck();
        $logger = new RecordingLogger();
        $listener = new HealthcheckListener(new HealthcheckController([$check], $logger), '/healthcheck');

        foreach (['GET', 'HEAD'] as $method) {
            $event = $this->requestEvent(Request::create('/healthcheck?probe=1', $method));
            $listener($event);

            self::assertTrue($event->hasResponse());
            self::assertTrue($event->isPropagationStopped());
            self::assertSame(200, $event->getResponse()->getStatusCode());
            self::assertSame('text/plain', $event->getResponse()->headers->get('Content-Type'));
            if ('GET' === $method) {
                self::assertSame('OK', $event->getResponse()->getContent());
            }
            self::assertFalse($event->getRequest()->attributes->has('_route'));
        }
        self::assertSame(2, $check->calls);
        self::assertSame([], $logger->records);
    }

    public function testSubrequestsOtherPathsAndOtherMethodsDoNotRunChecksOrSetAResponse(): void
    {
        $check = new TestCheck();
        $logger = new RecordingLogger();
        $controller = new HealthcheckController([$check], $logger);
        foreach ([
            ['GET', '/other', HttpKernelInterface::MAIN_REQUEST, '/healthcheck'],
            ['GET', '/healthcheck/extra', HttpKernelInterface::MAIN_REQUEST, '/healthcheck'],
            ['GET', '/healthcheck/', HttpKernelInterface::MAIN_REQUEST, '/healthcheck'],
            ['POST', '/healthcheck', HttpKernelInterface::MAIN_REQUEST, '/healthcheck'],
            ['PUT', '/healthcheck', HttpKernelInterface::MAIN_REQUEST, '/healthcheck'],
            ['GET', '/healthcheck', HttpKernelInterface::SUB_REQUEST, '/healthcheck'],
            ['HEAD', '/healthcheck', HttpKernelInterface::SUB_REQUEST, '/healthcheck'],
            ['GET', '/health+check', HttpKernelInterface::MAIN_REQUEST, '/health check'],
        ] as [$method, $uri, $type, $path]) {
            $event = $this->requestEvent(Request::create($uri, $method), $type);
            (new HealthcheckListener($controller, $path))($event);

            self::assertFalse($event->hasResponse(), $method.' '.$uri);
            self::assertFalse($event->isPropagationStopped());
        }
        self::assertSame(0, $check->calls);
        self::assertSame([], $logger->records);
    }

    public function testMatchingUsesRawDecodedPathInfoNotBaseUrlOrQueryAndPreservesPlus(): void
    {
        foreach ([['/ready now', '/ready%20now'], ['/ready+now', '/ready+now']] as [$path, $encoded]) {
            $check = new TestCheck();
            $logger = new RecordingLogger();
            $request = Request::create('http://localhost/mounted/index.php'.$encoded.'?probe=1', 'GET', [], [], [], [
                'SCRIPT_NAME' => '/mounted/index.php',
                'SCRIPT_FILENAME' => '/srv/mounted/index.php',
                'PHP_SELF' => '/mounted/index.php'.$encoded,
            ]);
            self::assertSame('/mounted/index.php', $request->getBaseUrl());
            self::assertSame($encoded, $request->getPathInfo());
            $event = $this->requestEvent($request);

            (new HealthcheckListener(new HealthcheckController([$check], $logger), $path))($event);

            self::assertTrue($event->hasResponse());
            self::assertSame('OK', $event->getResponse()->getContent());
            self::assertSame(1, $check->calls);
            self::assertSame([], $logger->records);
            self::assertFalse($request->attributes->has('_route'));
        }
    }

    public function testCheckHttpExceptionIsLoggedOnceAndRethrownUnchangedWithoutAResponse(): void
    {
        $original = new HttpException(429, 'Probe quota reached', null, ['Retry-After' => '17']);
        $check = new TestCheck($original);
        $skipped = new TestCheck();
        $logger = new RecordingLogger();
        $listener = new HealthcheckListener(new HealthcheckController([$check, $skipped], $logger), '/healthcheck');
        $event = $this->requestEvent(Request::create('/healthcheck'));
        $caught = null;
        try {
            $listener($event);
        } catch (HttpException $exception) {
            $caught = $exception;
        }

        self::assertSame($original, $caught);
        self::assertFalse($event->hasResponse());
        self::assertSame([1, 0], [$check->calls, $skipped->calls]);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame($original, $logger->records[0]['context']['exception']);
    }

    private function requestEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
