<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('monsieurbiz_healthcheck', '/healthcheck')
        ->controller('MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController')
        ->methods(['GET', 'HEAD']);
};
