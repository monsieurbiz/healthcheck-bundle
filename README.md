# Healthcheck Bundle

Minimal Symfony endpoint running application-defined checks.

`monsieurbiz/healthcheck-bundle` adds a `/healthcheck` route that runs tagged `MonsieurBiz\HealthcheckBundle\Check\DoCheckInterface` services. It includes no checks itself.

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

With no checks, or when every check returns `true`, `GET` returns `200 OK` with the `OK` body and a `text/plain` content type. `HEAD` returns the same status and headers without a response body.

## Set the path

Set `HEALTHCHECK_PATH` in your application's `.env` file or process environment before the route cache is built:

```dotenv
HEALTHCHECK_PATH=/internal/healthcheck
```

The route keeps the `monsieurbiz_healthcheck` name and `GET`/`HEAD` methods. It does not add a response header.

```bash
curl -i https://example.test/internal/healthcheck
curl -I https://example.test/internal/healthcheck
```

The route loader reads `$_ENV`, then `$_SERVER`, then `getenv()`. An absent, empty, or non-string value uses `/healthcheck`; a higher-priority blank value therefore does not fall through to a lower-priority source. This native read happens while PHP routes load because Symfony does not accept `%env()%` in a route path.

The route path is fixed when routes are loaded and cached. After changing the variable, rebuild the application cache in the same environment:

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
```

`cache:clear` warms Symfony's cache unless it receives `--no-warmup`; if your deployment uses that option, run `APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup` afterwards. If the application uses `composer dump-env` or `.env.local.php`, regenerate its environment dump too. The bundle does not require Flex.

## Add checks

Implement `DoCheckInterface`. Each check returns `true` when healthy or `false` when unhealthy. The first `false` result returns `503` and stops lower-priority checks.

```php
<?php

namespace App\Healthcheck;

use MonsieurBiz\HealthcheckBundle\Check\DoCheckInterface;

final class OpenSslCheck implements DoCheckInterface
{
    public function healthcheck(): bool
    {
        return \extension_loaded('openssl');
    }
}
```

Constructor-inject any existing application or Symfony service a check needs; no bundle-specific service API is required.

Your application must register the class as a service. With the usual service discovery and autoconfiguration, the bundle adds its tag automatically:

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
```

Implementing the interface alone does not create a service. If discovery or autoconfiguration is unavailable, register and tag the service explicitly:

```yaml
services:
    App\Healthcheck\OpenSslCheck:
        tags:
            - { name: monsieurbiz.healthcheck }
```

Checks run in standard Symfony tag priority order; use the tag's `priority` option when order matters.

## Handle failures

The bundle logs every failed check at `error` level through the application's standard `logger`. The message includes the check class and reason; context includes `check` (the check FQCN) and `exception`. Configure the destination through Symfony's normal logging setup, such as `error_log` or Monolog; the bundle adds no logging package or configuration.

An ordinary exception, including a PHP `Error`, is logged then wrapped in a generic `503` `ServiceUnavailableHttpException`, with the original throwable as its previous exception. Do not include secrets in exception messages, and restrict application-log access. Keep `APP_DEBUG=0` in production.

To control the HTTP status and headers, throw a Symfony `HttpExceptionInterface` such as `ServiceUnavailableHttpException`. It is logged and rethrown unchanged, so Symfony preserves its status and headers:

```php
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

throw new ServiceUnavailableHttpException(30, 'Custom reason');
```

Symfony's exception handling renders the message according to the application, environment, and any API error handling. This bundle provides no custom failure exception and does not promise a particular error body format. Healthcheck endpoints are often public: do not expose credentials or internal details in endpoint responses or logs.

## Test

```bash
composer install
composer validate
composer test
```
