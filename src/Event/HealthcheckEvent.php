<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Event;

use Symfony\Component\HttpFoundation\Response;

final class HealthcheckEvent
{
    private Response $response;

    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }
}
