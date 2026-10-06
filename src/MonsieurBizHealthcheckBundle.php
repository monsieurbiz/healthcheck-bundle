<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MonsieurBizHealthcheckBundle extends Bundle
{
    public function getPath(): string
    {
        // Modern root layout: the bundle class lives in src/, config/ is at the package root,
        // so @MonsieurBizHealthcheckBundle/... resources must resolve to the package root.
        return \dirname(__DIR__);
    }
}
