# flagpost API

Laravel API for flagpost. The project overview is in the [root README](../README.md) and the HTTP contract is in [docs/api-contract.md](../docs/api-contract.md).

## Local development

Requires PHP 8.4 and Composer, or Docker.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

From the repository root, `docker compose up` starts the API and PostgreSQL.

## Checks

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
./vendor/bin/pest
```

The evaluation core lives in `app/Evaluation/` and must not depend on the framework.
