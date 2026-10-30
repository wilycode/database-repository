# Changelog

All notable changes to WilyCode Database Repository will be documented here.

## [Unreleased]

### Changed (planned for v0.2.1)

- Defer pagination count compilation until the executor supplies the connection dialect; PostgreSQL no longer receives MySQL backticks.
- Require database-query and database-query-executor ^0.2 for automatic MySQL, PostgreSQL and SQLite dialect selection.
- Use dialect-aware conditions for repository IN/LIKE filters and relation correlations/persistence.
- Run CRUD, eager loading, pagination and relation transaction integration tests on MySQL, PostgreSQL and SQLite.

## [0.2.0]

### Breaking changes

- Removed the HTTP/list-query layer (`AbstractListFilter`, `AllowedFilter`, `AllowedSort`, `AllowedInclude`, `ListQueryRequest`, `ListQueryInterface`) and `Repository::paginateBy()`.
- Applications using list filters must own their request parsing and allowlists and build repository queries with `where()`, `orderBy()`, `with()` and `paginate()`. The previous implementation remains available from the `v0.1.1` tag for migration reference.
- Removed the direct `psr/http-message` dependency and the `nyholm/psr7` development dependency; repository CRUD, relation persistence and `PaginationResult` remain available.

### Changed

- Updated the PDO development dependency to `wilycode/database-pdo`.
- Updated installation examples and related-package links to the renamed database packages.

## [0.1.0] - 2026-10-13

### Added

- Initial public release of immutable model repository query modifiers and persisted read operations.
- Eager loading and nested relationship hydration through JSON subqueries.
- Bulk writes with safeguards against unfiltered UPDATE and DELETE queries.
- Model and relationship persistence for HasOne, HasMany and HasManyToMany relationships.
- Pagination and request-driven filtering, sorting and included relations with allowlists.
- PHPUnit unit and MySQL integration tests, automated with GitHub Actions.
- Expanded English README documentation and WilyCode ecosystem references.
