# Contribution notes

Keep this bundle a minimal liveness endpoint. Add behavior only when the endpoint or its event contract requires it.

## Compatibility

- Keep source compatible with PHP 8.0. Do not introduce `readonly`, enums, or newer attributes without a required compatibility plan.
- Support the Symfony 6–8 component constraints in `composer.json`.
- Run `composer validate` and `composer test` after changes.

## Invariants

- `HealthcheckEvent` owns a mutable `Response`; listeners may mutate or replace it.
- Do not catch listener exceptions in the controller. They must reach Symfony's standard exception flow.
- Routes are opt-in. Consumers import `config/routes.php` explicitly.

## Layout

- `src/`: bundle, controller, event, and dependency-injection extension.
- `config/routes.php`: the `GET`/`HEAD` route definition.
- `tests/Unit` and `tests/Integration`: PHPUnit coverage.

Do not commit `vendor/`, `var/`, Composer lock files, or PHPUnit cache files.
