# Healthcheck Bundle

Minimal Symfony liveness endpoint with application-defined checks.

`monsieurbiz/healthcheck-bundle` adds a `/healthcheck` route and dispatches a `MonsieurBiz\HealthcheckBundle\Event\HealthcheckEvent` for each request. With no listener, it is only a liveness probe: it does not test a database, queue, or other dependency.

## Requirements

- PHP `>=8.0.2`
- Symfony components `^6.0 || ^7.0 || ^8.0`

The minimum PHP version also depends on Symfony: Symfony 6.0 needs PHP 8.0.2, Symfony 6.4 needs PHP 8.1, Symfony 7.x needs PHP 8.2, and Symfony 8.x needs PHP 8.4.

## Install

```bash
composer require monsieurbiz/healthcheck-bundle
```

Register the bundle in `config/bundles.php` if your application does not already do so:

```php
<?php

return [
    MonsieurBiz\HealthcheckBundle\MonsieurBizHealthcheckBundle::class => ['all' => true],
];
```

This package has no Flex recipe. Import its routes explicitly, for example in `config/routes/monsieurbiz_healthcheck.php`:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('@MonsieurBizHealthcheckBundle/config/routes.php');
};
```

## Endpoint

The named route is `monsieurbiz_healthcheck`. It accepts `GET` and `HEAD` only.

```bash
curl -i https://example.test/healthcheck
curl -I https://example.test/healthcheck
```

Without a listener, `GET` returns `200 OK` with the `OK` body and a `text/plain` content type. `HEAD` returns the same status and headers without a response body.

## Add checks

Listen to `MonsieurBiz\HealthcheckBundle\Event\HealthcheckEvent`. The event holds a mutable `Symfony\Component\HttpFoundation\Response`; a listener can change it or replace it. Register an explicit listener tag when you need a portable Symfony 6–8 configuration:

```yaml
# config/services.yaml
services:
    App\Healthcheck\DependencyHealthcheck:
        tags:
            - { name: kernel.event_listener, event: MonsieurBiz\HealthcheckBundle\Event\HealthcheckEvent, method: onHealthcheck }
```

```php
use MonsieurBiz\HealthcheckBundle\Event\HealthcheckEvent;
use Symfony\Component\HttpFoundation\Response;

public function onHealthcheck(HealthcheckEvent $event): void
{
    // Change the initial response.
    $event->getResponse()->setStatusCode(503);
    $event->getResponse()->setContent('Dependency unavailable');

    // Or replace it completely.
    $event->setResponse(new Response('Maintenance', 503, ['Content-Type' => 'text/plain']));
}
```

To let Symfony handle a failed check through its normal `kernel.exception` flow, throw an HTTP exception. Do not catch it in the listener just to create a package-specific JSON response.

```php
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

throw new ServiceUnavailableHttpException(30, 'Dependency unavailable');
```

Healthcheck endpoints are often public. Do not expose credentials, dependency details, or other internal information in their response bodies. Without a listener, this endpoint remains a liveness probe only.

## Test

```bash
composer install
composer validate
composer test
```

The committed suite currently has 8 tests and 36 assertions. It was run locally with PHP 8.5.1 and Symfony 8.1.8. The Composer constraints declare Symfony 6–8 compatibility; this does not claim that every supported combination was executed locally.
