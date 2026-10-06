<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class HealthcheckController
{
    public function __construct(private iterable $checks, private LoggerInterface $logger)
    {
    }

    public function __invoke(): Response
    {
        foreach ($this->checks as $check) {
            try {
                $healthy = $check->healthcheck();
            } catch (\Throwable $exception) {
                $this->logger->error(\sprintf('Healthcheck failed: %s: %s', \get_class($check), $exception->getMessage()), [
                    'check' => \get_class($check),
                    'exception' => $exception,
                ]);
                if ($exception instanceof HttpExceptionInterface) {
                    throw $exception;
                }

                throw new ServiceUnavailableHttpException(null, 'Healthcheck failed.', $exception);
            }

            if (!$healthy) {
                $failure = new ServiceUnavailableHttpException(null, 'Check returned false.');
                $this->logger->error(\sprintf('Healthcheck failed: %s returned false.', \get_class($check)), [
                    'check' => \get_class($check),
                    'exception' => $failure,
                ]);

                throw $failure;
            }
        }

        return new Response('OK', 200, ['Content-Type' => 'text/plain']);
    }
}
