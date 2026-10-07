# Healthcheck Bundle

Minimal Symfony endpoint running application-defined checks.

`monsieurbiz/healthcheck-bundle` enables a global health endpoint that runs tagged `MonsieurBiz\HealthcheckBundle\Check\DoCheckInterface` services. It includes no checks itself.

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

This package has no Flex recipe. The endpoint is active as soon as the bundle is registered; importing routes is optional. Import this route only when the application needs the `monsieurbiz_healthcheck` route name for URL generation or the router's `GET`/`HEAD` restriction:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // Optional: the listener handles the health endpoint without this import.
    $routes->import('@MonsieurBizHealthcheckBundle/config/routes.php');
};
```

## Endpoint

The global endpoint matches `GET` and `HEAD` on the configured path before routing, security firewalls, and application `kernel.request` listeners. Its listener invokes the healthcheck controller without route attributes or router initialization. It is therefore global, not request-channel or locale scoped: no user authentication or request-context initialization occurs before checks, and checks must not depend on channel or locale context.

```bash
curl -i https://example.test/healthcheck
curl -I https://example.test/healthcheck
```

With no checks, or when every check returns `true`, `GET` returns `200 OK` with the `OK` body and a `text/plain` content type. `HEAD` returns the same status and headers without a response body. Other paths and methods continue through the normal application; without the optional route import, this bundle does not impose a global `405` response.

The listener is registered on `kernel.request` at `PHP_INT_MAX`, ahead of lower-priority request listeners. Listeners at the same priority retain Symfony's registration order. Its response stops later request dispatch, including routing, security/firewall, and application channel or locale listeners. Standard `kernel.response`, `kernel.finish_request`, and exception handling still run. Subrequests are ignored; bootstrap failures, response listeners, and non-main requests remain outside this bypass.

The endpoint bypasses application request security. Keep checks fast and read-only, never expose secrets, and restrict external network access to the health URL when needed. Only this main-request listener bypasses the request pipeline; other controllers and subrequests are unaffected.

## Set the path

Set `HEALTHCHECK_PATH` in your application's `.env` file or process environment before the application container is compiled:

```dotenv
HEALTHCHECK_PATH=/internal/healthcheck
```

The path is compared exactly against `rawurldecode($request->getPathInfo())`. `getPathInfo()` excludes the request base URL and query string. The configured value is normalized once by Symfony's `Route` path handling, then used by both the listener and the optional named route; it is not localized or prefixed.

```bash
curl -i https://example.test/internal/healthcheck
curl -I https://example.test/internal/healthcheck
```

The extension reads the first present source at container compilation: `$_ENV`, then `$_SERVER`, then `getenv()`. An absent, empty, null, or non-string value uses `/healthcheck`; a present blank or null value does not fall through. Symfony normalizes leading slashes through `Route::getPath()`. This avoids unsupported `%env()%` route paths.

The listener and optional route share this frozen compile-time path. Changing `HEALTHCHECK_PATH` after kernel boot—even before the router first loads—cannot create a different listener path. Rebuild the application container and route cache in the same environment after changing it:

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
