<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Check;

interface DoCheckInterface
{
    /**
     * @return bool true when healthy, false when unhealthy.
     *
     * A thrown HTTP exception customizes the response through Symfony's exception flow;
     * any other Throwable is reported as an unhealthy check.
     */
    public function healthcheck(): bool;
}
