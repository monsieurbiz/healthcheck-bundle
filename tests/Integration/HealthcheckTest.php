<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Tests\Integration;

use MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController;
use MonsieurBiz\HealthcheckBundle\Event\HealthcheckEvent;
use MonsieurBiz\HealthcheckBundle\MonsieurBizHealthcheckBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class HealthcheckTest extends TestCase
{
    private ?HealthcheckTestKernel $kernel = null;
    private mixed $exceptionHandler = null;

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $this->kernel->shutdown();
            $this->kernel = null;

            // Older FrameworkBundle versions install a global handler; preserve PHPUnit's handler.
            $registeredHandler = set_exception_handler(null);
            restore_exception_handler();
            if ($registeredHandler !== $this->exceptionHandler) {
                restore_exception_handler();
            }
        }

        parent::tearDown();
    }

    public function testImportedRouteUsesThePublicControllerAndAcceptsOnlyGetAndHead(): void
    {
        $kernel = $this->bootKernel();
        $container = $kernel->getContainer();

        self::assertInstanceOf(HealthcheckController::class, $container->get(HealthcheckController::class));
        self::assertSame('/healthcheck', $container->get('router')->generate('monsieurbiz_healthcheck'));

        $request = Request::create('/healthcheck', 'GET');
        $response = $kernel->handle($request);

        self::assertSame('monsieurbiz_healthcheck', $request->attributes->get('_route'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
        self::assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));

        $response = $kernel->handle(Request::create('/healthcheck', 'HEAD'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'] as $method) {
            self::assertSame(405, $kernel->handle(Request::create('/healthcheck', $method))->getStatusCode(), $method);
        }
    }

    public function testControllerUsesTheApplicationEventDispatcher(): void
    {
        $kernel = $this->bootKernel();
        $replacement = new Response('Dependency unavailable', 503, ['Content-Type' => 'text/plain']);
        $kernel->getContainer()->get('event_dispatcher')->addListener(
            HealthcheckEvent::class,
            static function (HealthcheckEvent $event) use ($replacement): void {
                $event->setResponse($replacement);
            }
        );

        $response = $kernel->handle(Request::create('/healthcheck'));

        self::assertSame($replacement, $response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('Dependency unavailable', $response->getContent());
    }

    public function testListenerExceptionReachesKernelExceptionAndSymfonyHandlesIt(): void
    {
        $kernel = $this->bootKernel();
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        $failure = new ServiceUnavailableHttpException(17, 'Probe failed');
        $caught = null;
        $dispatcher->addListener(HealthcheckEvent::class, static function () use ($failure): void {
            throw $failure;
        });
        $dispatcher->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event) use (&$caught): void {
            $caught = $event->getThrowable();
        }, 512);

        $response = $kernel->handle(Request::create('/healthcheck'));

        self::assertSame($failure, $caught);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('17', $response->headers->get('Retry-After'));
        self::assertNotSame('OK', $response->getContent());
    }

    public function testBundleDoesNotExposeTheRouteWithoutAnApplicationImport(): void
    {
        $kernel = $this->bootKernel(false);

        self::assertNull($kernel->getContainer()->get('router')->getRouteCollection()->get('monsieurbiz_healthcheck'));
        self::assertSame(404, $kernel->handle(Request::create('/healthcheck'))->getStatusCode());
    }

    private function bootKernel(bool $importRoutes = true): HealthcheckTestKernel
    {
        $this->kernel = new HealthcheckTestKernel($importRoutes ? 'test' : 'test_without_routes', false);
        $this->exceptionHandler = set_exception_handler(null);
        restore_exception_handler();
        $this->kernel->boot();

        return $this->kernel;
    }
}

final class HealthcheckTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonsieurBizHealthcheckBundle()];
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->getEnvironment();
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'healthcheck-tests',
            'http_method_override' => false,
            'router' => ['utf8' => true],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ('test' === $this->getEnvironment()) {
            $routes->import('@MonsieurBizHealthcheckBundle/config/routes.php');
        }
    }
}
