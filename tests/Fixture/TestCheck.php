<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Tests\Fixture;

use MonsieurBiz\HealthcheckBundle\Check\DoCheckInterface;

class TestCheck implements DoCheckInterface
{
    public int $calls = 0;

    public function __construct(private bool|\Throwable $result = true)
    {
    }

    public function healthcheck(): bool
    {
        ++$this->calls;
        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
