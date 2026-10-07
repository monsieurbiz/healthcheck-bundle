<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // Matching is handled by the early kernel.request listener; this named route keeps
    // URL generation and the GET/HEAD restriction for applications importing it.
    $routes->add('monsieurbiz_healthcheck', '%monsieurbiz.healthcheck.path%')
        ->controller('MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController')
        ->methods(['GET', 'HEAD']);
};
