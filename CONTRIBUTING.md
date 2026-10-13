# Contributing to WilyCode Database Repository

Thanks for considering a contribution.

## Local setup

1. Install PHP 8.4+, Composer, `ext-ctype`, `ext-mbstring`, and a MySQL-capable PDO driver.
2. Run `composer install` and `composer validate --strict`.
3. Run `composer test`. Database integration tests run only when `WILYCODE_TEST_DSN` is defined.

For database integration tests, use a **disposable** MySQL/MariaDB schema. Tests create and drop tables. For example:

```bash
export WILYCODE_TEST_DSN='mysql:host=127.0.0.1;dbname=wilycode_test;charset=utf8mb4'
export WILYCODE_TEST_USER='root'
export WILYCODE_TEST_PASS='your-test-password'
composer test
```

## Pull requests

- Keep the public API focused and compatible with the `wilycode/database-model`, `database-query`, and `database-query-executor` contracts.
- Add or adjust tests for behavior changes, including database-backed cases for persistence and eager loading.
- Update the English README and `CHANGELOG.md` for user-visible changes.
- Avoid committing `.env` files, credentials, generated `vendor/` files, or development caches.
- Add no `AGENTS.md` by default.

## Reporting issues

Please include PHP and dependency versions, a minimal reproduction and a sanitized SQL/schema example if the issue concerns queries. Never include secrets or production data.

See the [WilyCode PHP Ecosystem](https://github.com/wilycode/wilycode) for related packages.
