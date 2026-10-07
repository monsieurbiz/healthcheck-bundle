<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Tests\Integration;

use MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController;
use MonsieurBiz\HealthcheckBundle\MonsieurBizHealthcheckBundle;
use MonsieurBiz\HealthcheckBundle\Tests\Fixture\RecordingLogger;
use MonsieurBiz\HealthcheckBundle\Tests\Fixture\TestCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class HealthcheckTest extends TestCase
{
    private ?HealthcheckTestKernel $kernel = null;
    private mixed $exceptionHandler = null;
    private array $pathSources = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pathSources = [
            'env' => array_intersect_key($_ENV, ['HEALTHCHECK_PATH' => true]),
            'server' => array_intersect_key($_SERVER, ['HEALTHCHECK_PATH' => true]),
            'process' => getenv('HEALTHCHECK_PATH'),
        ];
        $this->exceptionHandler = set_exception_handler(null);
        restore_exception_handler();
        $this->configurePathSources();
    }

    protected function tearDown(): void
    {
        try {
            if (null !== $this->kernel) {
                $this->kernel->shutdown();
            }
        } finally {
            $this->kernel = null;
            unset($_ENV['HEALTHCHECK_PATH'], $_SERVER['HEALTHCHECK_PATH']);
            $_ENV += $this->pathSources['env'];
            $_SERVER += $this->pathSources['server'];
            $processPath = $this->pathSources['process'];
            putenv(false === $processPath ? 'HEALTHCHECK_PATH' : 'HEALTHCHECK_PATH='.$processPath);

            // Older FrameworkBundle versions install a global handler; preserve PHPUnit's handler.
            $registeredHandler = set_exception_handler(null);
            restore_exception_handler();
            if ($registeredHandler !== $this->exceptionHandler) {
                restore_exception_handler();
            }

            parent::tearDown();
        }
    }

    public function testEndpointAcceptsOnlyGetAndHeadWithAnEmptyApplicationRouteCollection(): void
    {
        $kernel = $this->bootKernel();
        $container = $kernel->getContainer();

        self::assertInstanceOf(LoggerInterface::class, $container->get('test.framework_logger'));

        $request = Request::create('/healthcheck', 'GET');
        $response = $kernel->handle($request);

        self::assertFalse($request->attributes->has('_route'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
        self::assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));

        $response = $kernel->handle(Request::create('/healthcheck', 'HEAD'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'] as $method) {
            self::assertSame(404, $kernel->handle(Request::create('/healthcheck', $method))->getStatusCode(), $method);
        }
        self::assertSame([], $container->get('router')->getRouteCollection()->all());
    }

    public function testControllerIsNotPublicWhileTheEndpointRemainsFunctional(): void
    {
        $kernel = $this->bootKernel('healthy');
        $response = $kernel->handle(Request::create('/healthcheck'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
        $this->expectException(ServiceNotFoundException::class);
        $kernel->getContainer()->get(HealthcheckController::class);
    }

    public function testAutoconfiguredAndExplicitlyTaggedPrivateChecksAreInvoked(): void
    {
        $kernel = $this->bootKernel('healthy');
        $container = $kernel->getContainer();

        $response = $kernel->handle(Request::create('/healthcheck'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
        $checks = $container->get('test.checks');
        self::assertSame([1, 1, 1], [$checks['automatic']->calls, $checks['tagged']->calls, $checks['last']->calls]);
        foreach ([AutomaticCheck::class, TaggedCheck::class, LastCheck::class] as $id) {
            self::assertFalse($container->has($id), 'Checks must work without being public.');
        }
        self::assertSame([], $this->checkLogRecords($container->get('logger')));
    }

    public function testFalseCheckLogsFailureAndStopsLowerPriorityChecks(): void
    {
        $kernel = $this->bootKernel('false');

        $response = $kernel->handle(Request::create('/healthcheck'));

        self::assertSame(503, $response->getStatusCode());
        self::assertNotSame('OK', $response->getContent());
        $checks = $kernel->getContainer()->get('test.checks');
        self::assertSame([0, 1, 0], [$checks['automatic']->calls, $checks['tagged']->calls, $checks['last']->calls]);
        $records = $this->checkLogRecords($kernel->getContainer()->get('logger'));
        self::assertCount(1, $records);
        self::assertSame(LogLevel::ERROR, $records[0]['level']);
        self::assertSame(TaggedCheck::class, $records[0]['context']['check']);
        self::assertInstanceOf(\Throwable::class, $records[0]['context']['exception']);
        self::assertStringContainsString('TaggedCheck', $records[0]['message']);
        $reason = $records[0]['context']['exception']->getMessage();
        self::assertNotSame('', $reason);
        self::assertTrue(strpos($records[0]['message'], $reason) !== false || stripos($records[0]['message'], 'false') !== false);
    }

    public function testUnexpectedThrowablesReachKernelExceptionAsGeneric503WithoutExposingDetails(): void
    {
        foreach (['runtime', 'error'] as $mode) {
            $kernel = $this->bootKernel($mode);
            $original = $kernel->getContainer()->get('test.checks')['failure'];
            $caught = null;
            $kernel->getContainer()->get('event_dispatcher')->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event) use (&$caught): void {
                $caught = $event->getThrowable();
            }, 512);

            $response = $kernel->handle(Request::create('/healthcheck'));

            self::assertSame(503, $response->getStatusCode());
            self::assertInstanceOf(ServiceUnavailableHttpException::class, $caught);
            self::assertSame($original, $caught->getPrevious());
            self::assertStringNotContainsString($original->getMessage(), $response->getContent());
            self::assertNotSame('OK', $response->getContent());
            $this->assertOriginalFailureWasLogged($kernel, $original);
        }
    }

    public function testCustomHttpExceptionIsUnchangedInKernelExceptionAndKeepsStatusAndHeaders(): void
    {
        $kernel = $this->bootKernel('http');
        $original = $kernel->getContainer()->get('test.checks')['failure'];
        $caught = null;
        $kernel->getContainer()->get('event_dispatcher')->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event) use (&$caught): void {
            $caught = $event->getThrowable();
        }, 512);

        $response = $kernel->handle(Request::create('/healthcheck'));

        self::assertSame($original, $caught);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('17', $response->headers->get('Retry-After'));
        self::assertSame('quota', $response->headers->get('X-Probe'));
        $this->assertOriginalFailureWasLogged($kernel, $original);
    }

    public function testEarlyHealthcheckBypassesTheApplicationRequestBlockerBeforeRouting(): void
    {
        $kernel = $this->bootKernel('healthy', true);
        $blocker = $kernel->getContainer()->get(ApplicationRequestBlocker::class);

        foreach (['GET', 'HEAD'] as $method) {
            $request = Request::create('/healthcheck', $method);
            $response = $kernel->handle($request);

            self::assertSame(200, $response->getStatusCode(), $method);
            self::assertSame('GET' === $method ? 'OK' : '', $response->getContent());
            self::assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
            self::assertFalse($request->attributes->has('_route'));
        }
        self::assertSame(0, $blocker->calls);
        $checks = $kernel->getContainer()->get('test.checks');
        self::assertSame([2, 2, 2], [$checks['automatic']->calls, $checks['tagged']->calls, $checks['last']->calls]);
        // Observe at check time only: kernel.finish_request may initialize the router afterwards.
        self::assertSame(false, $checks['router']->routerWasInitialized[0]);
        self::assertSame([], $this->checkLogRecords($kernel->getContainer()->get('logger')));
    }

    public function testEarlyCheckFailuresKeepSymfonyExceptionHandlingWithoutRunningTheApplicationBlocker(): void
    {
        foreach (['false', 'runtime', 'error', 'http'] as $mode) {
            foreach (['GET', 'HEAD'] as $method) {
                $kernel = $this->bootKernel($mode, true);
                $blocker = $kernel->getContainer()->get(ApplicationRequestBlocker::class);
                $caught = null;
                $kernel->getContainer()->get('event_dispatcher')->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event) use (&$caught): void {
                    $caught = $event->getThrowable();
                }, 512);

                $response = $kernel->handle(Request::create('/healthcheck', $method));

                self::assertSame('http' === $mode ? 429 : 503, $response->getStatusCode(), $mode.' '.$method);
                self::assertSame(0, $blocker->calls);
                self::assertNotSame('OK', $response->getContent());
                if ('HEAD' === $method) {
                    self::assertSame('', $response->getContent());
                }
                $checks = $kernel->getContainer()->get('test.checks');
                self::assertSame([false], $checks['router']->routerWasInitialized);
                self::assertSame([0, 1, 0], [$checks['automatic']->calls, $checks['tagged']->calls, $checks['last']->calls]);
                if ('false' === $mode) {
                    self::assertInstanceOf(ServiceUnavailableHttpException::class, $caught);
                    $records = $this->checkLogRecords($kernel->getContainer()->get('logger'));
                    self::assertCount(1, $records);
                    self::assertSame(LogLevel::ERROR, $records[0]['level']);
                    self::assertSame(TaggedCheck::class, $records[0]['context']['check']);
                    self::assertSame($caught, $records[0]['context']['exception']);
                } else {
                    $original = $checks['failure'];
                    self::assertStringNotContainsString($original->getMessage(), $response->getContent());
                    if ('http' === $mode) {
                        self::assertSame($original, $caught);
                        self::assertSame('17', $response->headers->get('Retry-After'));
                        self::assertSame('quota', $response->headers->get('X-Probe'));
                    } else {
                        self::assertInstanceOf(ServiceUnavailableHttpException::class, $caught);
                        self::assertSame($original, $caught->getPrevious());
                    }
                    $this->assertOriginalFailureWasLogged($kernel, $original);
                }
            }
        }
    }

    public function testApplicationRequestBlockerStillRunsForOtherPathsAndMethods(): void
    {
        $kernel = $this->bootKernel('healthy', true);
        $blocker = $kernel->getContainer()->get(ApplicationRequestBlocker::class);
        $caught = null;
        $kernel->getContainer()->get('event_dispatcher')->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event) use (&$caught): void {
            $caught = $event->getThrowable();
        }, 512);

        foreach ([['GET', '/other'], ['GET', '/healthcheck/extra'], ['POST', '/healthcheck'], ['PUT', '/healthcheck']] as [$method, $path]) {
            self::assertSame(500, $kernel->handle(Request::create($path, $method))->getStatusCode());
            self::assertSame($blocker->failure, $caught);
        }
        // Error-rendering subrequests must not be mistaken for main requests.
        self::assertSame(4, $blocker->calls);
        $checks = $kernel->getContainer()->get('test.checks');
        self::assertSame([0, 0, 0], [$checks['automatic']->calls, $checks['tagged']->calls, $checks['last']->calls]);
        self::assertSame([], $checks['router']->routerWasInitialized);
        self::assertSame([], $this->checkLogRecords($kernel->getContainer()->get('logger')));
    }

    public function testPathIsFrozenForListenerUntilTheKernelIsRebuilt(): void
    {
        $this->configurePathSources(" \t///health A\t ");
        $kernel = $this->bootKernel('healthy');
        $firstCacheDir = $kernel->getCacheDir();
        // Change ENV before the first request or explicit router access.
        $this->configurePathSources('health B');

        self::assertSame('/health A', $kernel->getContainer()->getParameter('monsieurbiz.healthcheck.path'));
        $response = $kernel->handle(Request::create('/health%20A?probe=1'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
        self::assertSame(404, $kernel->handle(Request::create('/health%20B'))->getStatusCode());
        self::assertSame('/health A', $kernel->getContainer()->getParameter('monsieurbiz.healthcheck.path'));

        $kernel = $this->bootKernel('healthy');
        self::assertNotSame($firstCacheDir, $kernel->getCacheDir());
        self::assertSame('/health B', $kernel->getContainer()->getParameter('monsieurbiz.healthcheck.path'));
        $response = $kernel->handle(Request::create('/health%20B'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
    }

    public function testPathFromDotenvGlobalsWorksWithoutGetenv(): void
    {
        $this->configurePathSources('/health-from-dotenv', '/health-from-dotenv');
        self::assertFalse(getenv('HEALTHCHECK_PATH'));

        $this->assertCustomPath('/health-from-dotenv');
    }

    public function testPathFromEnvTakesPriorityOverServerAndGetenv(): void
    {
        foreach ([
            '/health-from-env' => '/health-from-env',
            " \t///health probe+now \t" => '/health probe+now',
            '///' => '/',
            '   ' => '/',
        ] as $env => $path) {
            $this->configurePathSources($env, '/health-from-server', '/health-from-process');

            $this->assertCustomPath($path);
        }
    }

    public function testPathFromServerTakesPriorityOverGetenv(): void
    {
        $this->configurePathSources(null, '/health-from-server', '/health-from-process');

        $this->assertCustomPath('/health-from-server');
    }

    public function testPathFromGetenvIsUsedWhenSuperglobalsAreAbsent(): void
    {
        $this->configurePathSources(null, null, '/health-from-process');

        $this->assertCustomPath('/health-from-process');
    }

    public function testPathFallsBackToDefaultWhenAbsentOrBlank(): void
    {
        foreach ([
            'all absent' => [null, null, null],
            'blank ENV' => ['', null, null],
            'blank SERVER' => [null, '', null],
            'blank getenv' => [null, null, ''],
            'blank ENV takes priority' => ['', '/health-from-server', '/health-from-process'],
            'blank SERVER takes priority' => [null, '', '/health-from-process'],
        ] as $case => [$env, $server, $process]) {
            $this->configurePathSources($env, $server, $process);
            $kernel = $this->bootKernel();

            self::assertSame('/healthcheck', $kernel->getContainer()->getParameter('monsieurbiz.healthcheck.path'), $case);
            $response = $kernel->handle(Request::create('/healthcheck'));
            self::assertSame(200, $response->getStatusCode(), $case);
            self::assertSame('OK', $response->getContent(), $case);
        }
    }

    public function testPathFallsBackToDefaultWhenHigherPrioritySourceIsNotAString(): void
    {
        foreach ([null, false] as $value) {
            foreach (['ENV', 'SERVER'] as $source) {
                $this->configurePathSources(null, '/health-from-server', '/health-from-process');
                if ('ENV' === $source) {
                    $_ENV['HEALTHCHECK_PATH'] = $value;
                } else {
                    $_SERVER['HEALTHCHECK_PATH'] = $value;
                }
                $kernel = $this->bootKernel();
                self::assertSame('/healthcheck', $kernel->getContainer()->getParameter('monsieurbiz.healthcheck.path'), $source);
                $response = $kernel->handle(Request::create('/healthcheck'));
                self::assertSame(200, $response->getStatusCode());
                self::assertSame('OK', $response->getContent());
            }
        }
    }

    private function configurePathSources(?string $env = null, ?string $server = null, ?string $process = null): void
    {
        unset($_ENV['HEALTHCHECK_PATH'], $_SERVER['HEALTHCHECK_PATH']);
        if (null !== $env) {
            $_ENV['HEALTHCHECK_PATH'] = $env;
        }
        if (null !== $server) {
            $_SERVER['HEALTHCHECK_PATH'] = $server;
        }
        putenv(null === $process ? 'HEALTHCHECK_PATH' : 'HEALTHCHECK_PATH='.$process);
    }

    private function assertCustomPath(string $path): void
    {
        $kernel = $this->bootKernel();
        self::assertSame($path, $kernel->getContainer()->getParameter('monsieurbiz.healthcheck.path'));

        $uri = str_replace(' ', '%20', $path).'?probe=1';
        $request = Request::create($uri, 'GET');
        $response = $kernel->handle($request);
        self::assertFalse($request->attributes->has('_route'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());

        $response = $kernel->handle(Request::create($uri, 'HEAD'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(404, $kernel->handle(Request::create('/healthcheck'))->getStatusCode());
        self::assertSame(404, $kernel->handle(Request::create($uri, 'POST'))->getStatusCode());
    }

    private function checkLogRecords(RecordingLogger $logger): array
    {
        return array_values(array_filter($logger->records, static function (array $record): bool {
            return array_key_exists('check', $record['context']);
        }));
    }

    private function assertOriginalFailureWasLogged(HealthcheckTestKernel $kernel, \Throwable $original): void
    {
        $records = $this->checkLogRecords($kernel->getContainer()->get('logger'));
        self::assertCount(1, $records);
        self::assertSame(LogLevel::ERROR, $records[0]['level']);
        self::assertSame(TaggedCheck::class, $records[0]['context']['check']);
        self::assertSame($original, $records[0]['context']['exception']);
        self::assertStringContainsString('TaggedCheck', $records[0]['message']);
        self::assertStringContainsString($original->getMessage(), $records[0]['message']);
        $checks = $kernel->getContainer()->get('test.checks');
        self::assertSame([0, 1, 0], [$checks['automatic']->calls, $checks['tagged']->calls, $checks['last']->calls]);
    }

    private function bootKernel(string $checkMode = 'none', bool $observeRouter = false): HealthcheckTestKernel
    {
        if (null !== $this->kernel) {
            $this->kernel->shutdown();
        }
        $this->kernel = new HealthcheckTestKernel('test', false, $checkMode, $observeRouter);
        $this->kernel->boot();

        return $this->kernel;
    }
}

final class HealthcheckTestKernel extends Kernel
{
    use MicroKernelTrait;

    private string $testCacheId;

    public function __construct(string $environment, bool $debug, private string $checkMode = 'none', private bool $observeRouter = false)
    {
        $this->testCacheId = bin2hex(random_bytes(8));
        parent::__construct($environment, $debug);
    }

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
        return $this->getProjectDir().'/var/cache/'.$this->getEnvironment().'/'.$this->testCacheId;
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
        // Model HTTP requests under CLI without replacing Symfony's production error renderer.
        $container->parameters()->set('kernel.runtime_mode.web', true);

        $container->extension('framework', [
            'secret' => 'healthcheck-tests',
            'http_method_override' => false,
            'router' => ['utf8' => true],
        ]);

        $services = $container->services();
        $services->alias('test.framework_logger', 'logger')->public();
        if ('none' === $this->checkMode) {
            return;
        }

        $services->set('logger', RecordingLogger::class)->public();
        $services->set(AutomaticCheck::class)->autoconfigure();
        $tagged = $services->set(TaggedCheck::class)->tag('monsieurbiz.healthcheck', ['priority' => 10]);
        $services->set(LastCheck::class)->tag('monsieurbiz.healthcheck', ['priority' => -10]);
        $probe = [
            'automatic' => service(AutomaticCheck::class),
            'tagged' => service(TaggedCheck::class),
            'last' => service(LastCheck::class),
        ];
        if ($this->observeRouter) {
            $services->set(ApplicationRequestBlocker::class)->public()->tag('kernel.event_listener', [
                'event' => KernelEvents::REQUEST,
                'priority' => PHP_INT_MAX - 1,
            ]);
            $services->set(RouterObservingCheck::class)->args([service('service_container')])->tag('monsieurbiz.healthcheck', ['priority' => 20]);
            $probe['router'] = service(RouterObservingCheck::class);
        }
        if ('false' === $this->checkMode) {
            $tagged->args([false]);
        } elseif ('healthy' !== $this->checkMode) {
            if ('http' === $this->checkMode) {
                $services->set('test.failure', HttpException::class)->args([429, 'Private probe quota details', null, ['Retry-After' => '17', 'X-Probe' => 'quota']]);
            } else {
                $services->set('test.failure', 'error' === $this->checkMode ? \Error::class : \RuntimeException::class)->args(['Private check failure details']);
            }
            $tagged->args([service('test.failure')]);
            $probe['failure'] = service('test.failure');
        }
        $services->set('test.checks', \ArrayObject::class)->args([$probe])->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}

final class AutomaticCheck extends TestCheck
{
}

final class TaggedCheck extends TestCheck
{
}

final class LastCheck extends TestCheck
{
}

final class RouterObservingCheck extends TestCheck
{
    public array $routerWasInitialized = [];

    public function __construct(private ContainerInterface $container)
    {
        parent::__construct();
    }

    public function healthcheck(): bool
    {
        $this->routerWasInitialized[] = $this->container->initialized('router');

        return parent::healthcheck();
    }
}

final class ApplicationRequestBlocker
{
    public int $calls = 0;
    public \RuntimeException $failure;

    public function __construct()
    {
        $this->failure = new \RuntimeException('Channel/locale request initialization failed before routing');
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            ++$this->calls;
            throw $this->failure;
        }
    }
}
