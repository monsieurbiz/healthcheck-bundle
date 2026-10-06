<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // Native env read: Symfony Router 6-8 rejects %env()% in the route path.
    // First present source wins (array_key_exists, so explicit null does not fall through);
    // absent, empty, or non-string values use the default.
    if (\array_key_exists('HEALTHCHECK_PATH', $_ENV)) {
        $path = $_ENV['HEALTHCHECK_PATH'];
    } elseif (\array_key_exists('HEALTHCHECK_PATH', $_SERVER)) {
        $path = $_SERVER['HEALTHCHECK_PATH'];
    } else {
        $path = getenv('HEALTHCHECK_PATH');
    }

    $routes->add('monsieurbiz_healthcheck', \is_string($path) && '' !== $path ? $path : '/healthcheck')
        ->controller('MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController')
        ->methods(['GET', 'HEAD']);
};
