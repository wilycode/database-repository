# Changelog

All notable changes to WilyCode Database Repository will be documented here.

## [Unreleased]

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
