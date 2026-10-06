<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Tests\Unit;

use MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController;
use MonsieurBiz\HealthcheckBundle\Tests\Fixture\RecordingLogger;
use MonsieurBiz\HealthcheckBundle\Tests\Fixture\TestCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class HealthcheckControllerTest extends TestCase
{
    public function testEmptyCheckListReturnsPlainOkWithoutLogging(): void
    {
        $logger = new RecordingLogger();
        $response = (new HealthcheckController([], $logger))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertSame([], $logger->records);
    }

    public function testAllHealthyChecksAreInvokedOnceWithoutLogging(): void
    {
        $first = new TestCheck();
        $second = new TestCheck();
        $logger = new RecordingLogger();
        $response = (new HealthcheckController(new \ArrayIterator([$first, $second]), $logger))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
        self::assertSame([1, 1], [$first->calls, $second->calls]);
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertSame([], $logger->records);
    }

    public function testFalseResultLogsFailureAndStopsAfterTheHealthyCheck(): void
    {
        $healthy = new TestCheck();
        $failed = new FailedCheck(false);
        $skipped = new TestCheck();
        $logger = new RecordingLogger();

        $failure = $this->invokeFailure([$healthy, $failed, $skipped], $logger);

        self::assertInstanceOf(ServiceUnavailableHttpException::class, $failure);
        self::assertSame(503, $failure->getStatusCode());
        self::assertArrayNotHasKey('Retry-After', $failure->getHeaders());
        self::assertContains($failure->getMessage(), ['Healthcheck failed.', 'Check returned false.']);
        self::assertSame([1, 1, 0], [$healthy->calls, $failed->calls, $skipped->calls]);
        $this->assertFailureWasLogged($logger, $failed);
    }

    public function testCustomHttpExceptionIsLoggedAndRethrownUnchanged(): void
    {
        $original = new HttpException(429, 'Probe quota reached', null, ['Retry-After' => '17', 'X-Probe' => 'quota']);
        $failed = new FailedCheck($original);
        $skipped = new TestCheck();
        $logger = new RecordingLogger();

        $failure = $this->invokeFailure([$failed, $skipped], $logger);

        self::assertSame($original, $failure);
        self::assertSame(429, $failure->getStatusCode());
        self::assertSame(['Retry-After' => '17', 'X-Probe' => 'quota'], $failure->getHeaders());
        self::assertSame([1, 0], [$failed->calls, $skipped->calls]);
        $this->assertFailureWasLogged($logger, $failed, $original);
    }

    public function testRuntimeExceptionBecomesGeneric503AndLogsTheOriginal(): void
    {
        $this->assertNonHttpFailure(new \RuntimeException('Private database failure details'));
    }

    public function testPhpErrorBecomesGeneric503AndLogsTheOriginal(): void
    {
        $this->assertNonHttpFailure(new \Error('Private PHP error details'));
    }

    private function assertNonHttpFailure(\Throwable $original): void
    {
        $failed = new FailedCheck($original);
        $skipped = new TestCheck();
        $logger = new RecordingLogger();

        $failure = $this->invokeFailure([$failed, $skipped], $logger);

        self::assertInstanceOf(ServiceUnavailableHttpException::class, $failure);
        self::assertSame(503, $failure->getStatusCode());
        self::assertSame($original, $failure->getPrevious());
        self::assertNotSame('', $failure->getMessage());
        self::assertStringNotContainsString($original->getMessage(), $failure->getMessage());
        self::assertSame([1, 0], [$failed->calls, $skipped->calls]);
        $this->assertFailureWasLogged($logger, $failed, $original);
    }

    private function invokeFailure(iterable $checks, RecordingLogger $logger): \Throwable
    {
        $controller = new HealthcheckController($checks, $logger);
        try {
            $controller();
        } catch (\Throwable $failure) {
            return $failure;
        }

        self::fail('An unhealthy check must throw through Symfony\'s exception flow.');
    }

    private function assertFailureWasLogged(RecordingLogger $logger, TestCheck $check, ?\Throwable $original = null): void
    {
        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        self::assertSame(LogLevel::ERROR, $record['level']);
        self::assertSame(get_class($check), $record['context']['check']);
        self::assertInstanceOf(\Throwable::class, $record['context']['exception']);
        self::assertStringContainsString((new \ReflectionClass($check))->getShortName(), $record['message']);

        if (null !== $original) {
            self::assertSame($original, $record['context']['exception']);
            self::assertStringContainsString($original->getMessage(), $record['message']);
        } else {
            $reason = $record['context']['exception']->getMessage();
            self::assertNotSame('', $reason);
            self::assertTrue(strpos($record['message'], $reason) !== false || stripos($record['message'], 'false') !== false);
        }
    }
}

final class FailedCheck extends TestCheck
{
}
