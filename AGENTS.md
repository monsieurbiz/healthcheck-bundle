# Contribution notes

Keep this bundle a minimal endpoint for tagged application checks.

## Compatibility

- Keep source compatible with PHP 8.0. Do not introduce `readonly`, enums, or newer attributes without a required compatibility plan.
- Support the Symfony 6–8 component constraints in `composer.json`.
- Run `composer validate` and `composer test` after changes.

## Invariants

- `DoCheckInterface::healthcheck()` returns `bool`. Autoconfiguration applies the `monsieurbiz.healthcheck` tag only to registered services.
- Run checks fail-fast. A `false` result and non-HTTP throwable are logged and produce a generic `503`; rethrow a logged `HttpExceptionInterface` unchanged.
- `HealthcheckListener` handles only main `GET`/`HEAD` requests at the normalized configured path on `kernel.request` with `PHP_INT_MAX`; its native response stops later request listeners without routing or route attributes.
- The endpoint is global, before channel, locale, and security request listeners. Keep checks root-scoped, fast, read-only, and independent of request context.
- Route import is optional and exists for URL generation and router restrictions. Consumers may import `config/routes.php` explicitly.
- `HEALTHCHECK_PATH` uses the first present source during container compilation: `$_ENV`, then `$_SERVER`, then `getenv()`. Empty, null, and non-string values use `/healthcheck`; normalize once and rebuild container and route caches after changes.
- Tests that change `HEALTHCHECK_PATH` must restore all three sources and use a unique route-cache directory.

## Layout

- `src/Check`: public check contract.
- `src/EventListener`: early main-request endpoint listener.
- `src/`: bundle, controller, and dependency-injection extension.
- `config/routes.php`: the `GET`/`HEAD` route definition.
- `tests/Unit` and `tests/Integration`: PHPUnit coverage.

Do not commit `vendor/`, `var/`, Composer lock files, or PHPUnit cache files.
