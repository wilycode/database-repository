# WilyCode Database Repository

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://github.com/wilycode/database-repository/actions/workflows/tests.yml/badge.svg)](https://github.com/wilycode/database-repository/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/wilycode/database-repository)](https://packagist.org/packages/wilycode/database-repository)

**Attribute-based model repositories for PHP 8.4+.** Query models, eager-load relations, perform safe writes, synchronize relationships, and paginate results without depending on HTTP request handling.

> **WilyCode PHP Ecosystem**
>
> Part of **[WilyCode](https://github.com/wilycode/wilycode)** — independent, reusable PHP components with their own repositories, releases, documentation and Composer packages.
>
> **[Explore the WilyCode ecosystem →](https://github.com/wilycode/wilycode)**

## Table of contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Creating a repository](#creating-a-repository)
- [Reading models](#reading-models)
  - [Filtering and ordering](#filtering-and-ordering)
  - [Fetching one or many](#fetching-one-or-many)
  - [Raw conditions](#raw-conditions)
- [Eager loading relationships](#eager-loading-relationships)
  - [Nested relations and selected columns](#nested-relations-and-selected-columns)
  - [Filtering related records](#filtering-related-records)
  - [References versus loaded relations](#references-versus-loaded-relations)
- [Pagination](#pagination)
- [Writing database rows](#writing-database-rows)
- [Persisting models](#persisting-models)
- [Synchronizing relationships](#synchronizing-relationships)
- [Transactions](#transactions)
- [API reference](#api-reference)
- [Errors and safety](#errors-and-safety)
- [Architecture and driver integration](#architecture-and-driver-integration)
- [Related packages](#related-packages)
- [Development and testing](#development-and-testing)
- [Contributing and license](#contributing-and-license)

## Overview

`wilycode/database-repository` connects three independent WilyCode packages:

```text
  database-model         Model metadata, attributes and hydration
        |                      |
        +---- database-repository ---- QueryExecutorInterface
                 |                          |
          database-query             database-query-executor
         (SQL generation)                    |
                                      Driver + Binder
                                        (PDO/MySQLi)
```

The repository provides a small subclassing API for model-specific repositories. It delegates model writes to `ModelPersister`, relation writes to `RelationPersister`, and eager-load SQL to `EagerLoadCompiler`.

**The repository does not open a database connection or implement a database driver.** Inject a configured `QueryExecutorInterface` and a model class. It also does not include an identity map, lazy-loading proxy, HTTP request parser or controller.

Query modifiers such as `where()`, `orderBy()` and `with()` return **cloned repositories**, allowing safe reuse of a base repository configuration. Write operations work through the injected executor and have database side effects.

## Requirements

| Requirement | Version / purpose |
| --- | --- |
| PHP | 8.4 or newer |
| `ext-ctype`, `ext-mbstring` | Required PHP extensions |
| `wilycode/database-model` | `^0.1` — model metadata and hydration |
| `wilycode/database-query` | `^0.1` — SQL query builder |
| `wilycode/database-query-executor` | `^0.1` — query execution interface |

The concrete PDO or MySQLi driver is installed separately. Eager-loading queries use **MySQL-oriented JSON SQL functions**, so use a compatible database and verify the generated SQL on your server.

## Installation

```bash
composer require wilycode/database-repository
```

Choose a database adapter, for example:

```bash
composer require wilycode/database-pdo
```

Or use `wilycode/database-mysqli` with its corresponding binder. Installing the repository alone does not establish a database connection.

## Quick start

This example assumes an existing MySQL database with an `articles` table containing `id`, `title` and `status` columns.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use WilyCode\Database\DriverPdo\PdoBinder;
use WilyCode\Database\DriverPdo\PdoDatabase;
use WilyCode\Database\Model\Attribute\PrimaryKey;
use WilyCode\Database\Model\Attribute\Property;
use WilyCode\Database\Model\Attribute\Table;
use WilyCode\Database\Model\Model;
use WilyCode\Database\QueryExecutor\QueryExecutor;
use WilyCode\Database\Repository\Repository;

#[Table('articles')]
final class Article extends Model
{
    #[PrimaryKey]
    private ?int $id = null;

    #[Property]
    private ?string $title = null;

    #[Property]
    private ?string $status = null;

    public function getId(): ?int { return $this->id; }
    public function getTitle(): ?string { return $this->title; }
    public function getStatus(): ?string { return $this->status; }
}

$database = PdoDatabase::connect(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'app_user',
    'example-password',
);

$executor = new QueryExecutor($database, new PdoBinder());
$articles = new Repository($executor, Article::class);

$published = $articles
    ->where('status', 'published')
    ->orderBy('id', 'DESC')
    ->findAll();

foreach ($published as $article) {
    echo $article->getTitle();
}
```

Use configuration or environment variables for real credentials; the password here is illustrative.

## Creating a repository

For application code, create a dedicated repository per model:

```php
namespace App\Repository;

use App\Model\Article;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\Repository\Repository;

/** @extends Repository<Article> */
final class ArticleRepository extends Repository
{
    public function __construct(QueryExecutorInterface $executor)
    {
        parent::__construct($executor, Article::class);
    }
}
```

The generic PHPDoc annotation helps static analysis. The constructor depends on `QueryExecutorInterface` rather than on PDO or MySQLi, so an application may inject a profiling or caching decorator without changing this repository.

## Reading models

### Filtering and ordering

```php
$results = $articles
    ->where('status', 'published')
    ->whereLike('title', 'PHP')
    ->whereIn('id', [10, 20, 30])
    ->orderBy('id', 'DESC')
    ->findAll();
```

`where()` supports a comparison operator as its third argument and a logical operator as its fourth. `whereIn()` with an empty list matches nothing. `whereLike()` adds `%` on both sides of the supplied string. `orderBy()` accepts `ASC` and `DESC` (case-insensitive); `orderByRaw()` exists for trusted SQL expressions.

**SQL shape** (illustrative; placeholder names and quoting are determined by `database-query`):

```sql
SELECT id, title, status
FROM articles
WHERE status = :param_1
  AND title LIKE :param_2
  AND id IN (:param_3, :param_4, :param_5)
ORDER BY id DESC
```

The repository adds the primary key as a deterministic order tie-breaker **only if** the query does not already sort by that column. Here `orderBy('id', 'DESC')` already includes the primary key, so no tie-breaker is added.

### Fetching one or many

| Method | Result |
| --- | --- |
| `findAll()` | List of hydrated model instances (`[]` when empty) |
| `findOne()` | First matching model or `null` |
| `findOneOrThrow()` | First matching model or `ModelNotFoundException` |
| `find($id)` | Find by the mapped primary key; model or `null` |
| `findOrThrow($id)` | Find by primary key or throw `ModelNotFoundException` |

```php
$article = $articles->find(42);                    // Article|null
$required = $articles->findOrThrow(42);           // Article or exception
$first = $articles->where('status', 'published')->findOne();
```

Repository reads require a valid primary key in every hydrated model. If a returned row lacks the mapped key, hydration fails with `RuntimeException`; the repository does not return an invalid persisted model.

### Raw conditions

```php
$results = $articles
    ->whereRaw('published_at <= ? AND deleted_at IS NULL', ['2026-10-01 12:00:00'])
    ->findAll();
```

`whereRaw()` is for **trusted SQL fragments**. Always bind untrusted values as separate parameters. SQL identifiers and expressions cannot be safely parameterized; explicitly validate them or choose from an allowlist.

## Eager loading relationships

Call `with()` for explicit eager loading. The repository compiles relation projections into **correlated JSON subqueries**, and the model hydrator turns the resulting JSON into related objects or collections. It does not rely on flattened column prefixes such as `categoryName` to construct relations.

### Nested relations and selected columns

The following example assumes the model metadata declares `category`, `tags`, `comments` and `comments.createdBy` relations:

```php
$article = $articles
    ->with('category', ['id', 'name'])
    ->with('tags', ['id', 'name'])
    ->with('comments.createdBy', ['id', 'name'])
    ->findOrThrow(42);
```

Columns apply to the **last segment** of a relation path. Required primary and foreign keys are included automatically, even if the selected columns omit them. Unknown relations throw an exception.

### Filtering related records

The optional third `with()` argument filters related rows without filtering out the owner:

```php
$article = $articles
    ->with('translations', ['locale', 'title', 'slug'], ['locale' => 'en'])
    ->with('tags', ['name'], ['name' => ['php', 'database']])
    ->findOrThrow(42);
```

Conditions accept model property names or mapped database names. A scalar uses equality, `null` uses `IS NULL`, and lists use `IN`. Persisted model instances are accepted as values and converted to their primary keys; a model without an ID is rejected.

### References versus loaded relations

A plain read still selects mapped foreign-key columns. A `#[ForeignKey]` + `#[HasOne]` relation can therefore be represented by a **lightweight reference** containing only the related primary key.

```php
$article = $articles->findOrThrow(42);

$article->isRelationReference('category'); // May be true when only FK was read
$article->isRelationLoaded('category');    // false unless fully hydrated

$withCategory = $articles->with('category')->findOrThrow(42);
$withCategory->isRelationLoaded('category'); // true when relation was hydrated
```

Loaded relation state and a foreign-key-only reference are intentionally different; do not assume a reference has all of the related model's fields.

## Pagination

HTTP query parameters, user-supplied filter and sort allowlists, and pagination links belong in the application layer. Use `where()`, `whereIn()`, `whereLike()`, `orderBy()` and `with()` to construct the query before calling `paginate()`.

When a sort column comes from a user-facing filter, map it to an **allowlist** of known database columns before calling `orderBy()`:

```php
$sortColumns = ['title' => 'title', 'newest' => 'created_at'];
$column = $sortColumns[$requestedSort] ?? 'created_at';
$direction = $requestedDirection === 'asc' ? 'ASC' : 'DESC';

$page = $articles->orderBy($column, $direction)->paginate(page: 1, perPage: 20);
```

The repository deliberately does **not** parse the HTTP request or validate application-specific filter names. Do not pass untrusted SQL column identifiers directly to `orderBy()` or `whereRaw()`.

```php
$page = $articles
    ->where('status', 'published')
    ->orderBy('id', 'DESC')
    ->paginate(page: 2, perPage: 20);

foreach ($page->items as $article) {
    // Hydrated Article models
}

echo $page->total;
echo $page->page;
echo $page->perPage;
echo $page->lastPage();
```

`paginate()` runs a separate count query **without eager-load subqueries**, then fetches one page. `page` and `perPage` must be positive integers. The pagination result is `PaginationResult`, with `items`, `total`, `page`, `perPage` and `lastPage()`.

The repository uses deterministic ordering: a default primary-key order, or a primary-key tie-breaker for custom sorting. `paginate()` does not automatically clamp an out-of-range page to the last available page.

## Writing database rows

Bulk row operations return the driver's affected-row count; they do not hydrate models or track their dirty fields.

```php
$id = $articles->insert([
    'title' => 'Getting started',
    'status' => 'draft',
]); // string: last inserted ID

$inserted = $articles->insertMany([
    ['title' => 'One', 'status' => 'draft'],
    ['title' => 'Two', 'status' => 'published'],
]); // int: affected rows

$updated = $articles
    ->where('status', 'draft')
    ->update(['status' => 'published']);

$deleted = $articles
    ->whereIn('id', [10, 11])
    ->delete();
```

`insertMany([])` returns `0`. **`update()` and `delete()` refuse to run without an explicit WHERE condition**, throwing `UnsafeWriteException`. This protects against accidental unfiltered writes, but it does not prove that a user-provided condition is safe or suitably selective.

## Persisting models

Model persistence uses the `database-model` change-tracking state.

```php
$article = new Article([
    'title' => 'My first article',
    'status' => 'draft',
]);

$articles->saveModel($article); // Insert and assign generated ID

$article->setTitle('Updated article');
$articles->saveModel($article); // Update only dirty fields

$affected = $articles->deleteModel($article);
```

| Method | Purpose |
| --- | --- |
| `createModel($model)` | Insert a new model and set its primary key |
| `updateModel($model)` | Update dirty fields on a persisted model |
| `saveModel($model)` | Insert, update or no-op based on persistence state |
| `deleteModel($model)` | Delete the model by primary key; return affected rows |

Do not mix set-based writes with already-loaded models and assume those objects will be refreshed. Reload them when necessary.

## Synchronizing relationships

Relation helpers validate relationship metadata and perform multi-step operations in a transaction.

```php
// HasManyToMany: make pivot rows match the provided IDs/models.
$changes = $articles->syncManyToMany($article, 'tags', [4, 8, 15]);
// ['attached' => ..., 'detached' => ...]

// HasMany: upsert supplied children and remove omitted ones.
$changes = $articles->replaceHasMany($article, 'comments', $comments);
// ['inserted' => ..., 'updated' => ..., 'deleted' => ..., 'detached' => ...]

// HasOne, FK stored on the child model.
$articles->replaceHasOne($article, 'coverImage', $image);

// HasOne, FK stored on the owner model.
$articles->syncHasOne($article, 'category', $category);
```

`replaceHasMany(..., deleteOrphans: false)` and `replaceHasOne(..., deleteOrphan: false)` detach omitted children instead of deleting them.

**Important transaction detail:** the database can roll back while PHP objects have already been mutated in memory. After a failed relation operation, discard or reload affected objects. `syncHasOne()` also supports owners that have not been inserted yet and records a detached relation as a loaded `null`.

## Transactions

```php
$articles->transaction(function (ArticleRepository $repo): void {
    $repo->insert(['title' => 'First', 'status' => 'draft']);
    $repo->insert(['title' => 'Second', 'status' => 'draft']);
});
```

The callback receives the **same repository instance**. The underlying executor controls transaction lifecycle and rollback. When using a transaction-aware decorator, follow that decorator's documented semantics for joining an existing transaction.

## API reference

| Method | Arguments (main) | Returns / behavior |
| --- | --- | --- |
| `__construct()` | `QueryExecutorInterface`, model class-string | Repository for a model |
| `where()` | column, value, comparison, logical | Cloned repository |
| `whereIn()` | column, values, logical | Cloned repository |
| `whereLike()` | column, value, logical | Cloned repository |
| `whereRaw()` | SQL condition, params, logical | Cloned repository |
| `orderBy()` / `orderByRaw()` | column/expression, direction | Cloned repository |
| `with()` | relation path, columns, conditions | Cloned repository |
| `findAll()` / `findOne()` | — | Model list / optional model |
| `find()` / `findOrThrow()` | model ID | Optional model / model or exception |
| `findOneOrThrow()` | optional error message | Model or exception |
| `paginate()` | page, perPage | `PaginationResult` |
| `insert()` / `insertMany()` | row / rows | Last ID string / affected rows |
| `update()` / `delete()` | values / — | Affected rows; requires WHERE |
| `createModel()` / `updateModel()` | model | Persisted model |
| `saveModel()` / `deleteModel()` | model | Model / affected rows |
| `syncManyToMany()` | model, relation, IDs/models | Attached/detached counts |
| `replaceHasMany()` | model, relation, children, deleteOrphans | Insert/update/delete/detach counts |
| `replaceHasOne()` | model, relation, child, deleteOrphan | Insert/update/delete/detach counts |
| `syncHasOne()` | model, relation, model/null | Owner and relation change counts |
| `transaction()` | callback | Callback result |
| `getTable()` | — | Mapped table name |
| `getPrimaryKeyDbName()` | — | Mapped PK column |
| `getOuterRef()` | — | Root SELECT alias or table name |

## Errors and safety

| Situation | Behavior |
| --- | --- |
| `findOrThrow()` / `findOneOrThrow()` with no row | `ModelNotFoundException` |
| Persisted read hydrates missing primary key | `RuntimeException` |
| Unfiltered `update()` or `delete()` | `UnsafeWriteException` |
| Invalid sort direction or pagination value | `InvalidArgumentException` |
| Unknown relation or condition field in `with()` | Exception from relation compiler |
| Driver, query or transaction failure | Underlying executor/driver exception propagates |

Do not expose raw SQL, error details or sensitive values to public HTTP responses. Log only appropriately sanitized diagnostic information. Supply values via bound parameters; only use application-controlled, trusted SQL fragments and column names with `whereRaw()` / `orderByRaw()`.

`update()`/`delete()` protect against an **absent** WHERE clause, not against an always-true condition such as `1=1` supplied by application code.

## Architecture and driver integration

```text
App repository (extends Repository)
           |
           +-- ModelMetadata + ModelHydrator (database-model)
           +-- EagerLoadCompiler (database-query / JSON SQL)
           +-- ModelPersister + RelationPersister
           |
     QueryExecutorInterface (database-query-executor)
           |
       QueryExecutor
           |
     PdoBinder + PdoDatabase
           or
     MysqliBinder + MysqliDatabase
```

The core repository depends on the executor interface, not a particular adapter. Optional query-cache or profiling executors may be injected when their semantics fit your application. Eager-loading SQL uses MySQL JSON functions; it should not be assumed portable to every SQL backend without testing.

## Related packages

| Package | Purpose |
| --- | --- |
| [`wilycode/database-model`](https://github.com/wilycode/database-model) | Attribute-based models, metadata and hydration |
| [`wilycode/database-query`](https://github.com/wilycode/database-query) | SQL query builder and compiled parameter representation |
| [`wilycode/database-query-executor`](https://github.com/wilycode/database-query-executor) | Driver-independent query execution interface |
| [`wilycode/database-pdo`](https://github.com/wilycode/database-pdo) | PDO driver and binder |
| [`wilycode/database-mysqli`](https://github.com/wilycode/database-mysqli) | MySQLi driver and binder |
| [`wilycode/database-query-executor-cache`](https://github.com/wilycode/database-query-executor-cache) | Optional cached query executor |
| [`wilycode/database-query-executor-profiler`](https://github.com/wilycode/database-query-executor-profiler) | Optional profiling decorators |

## Development and testing

```bash
composer install
composer validate --strict
composer test
```

Unit tests run without a database. Integration tests require a **dedicated disposable MySQL/MariaDB schema** and can create or drop test tables:

```bash
export WILYCODE_TEST_DSN='mysql:host=127.0.0.1;dbname=wilycode_test;charset=utf8mb4'
export WILYCODE_TEST_USER='root'
export WILYCODE_TEST_PASS='your-test-password'
composer test
```

If `WILYCODE_TEST_DSN` is absent, integration tests are skipped. GitHub Actions runs the suite with MySQL 8.4 and the `pdo_mysql` extension so those tests are exercised automatically.

Source tests remain in the repository but are excluded from Composer distribution archives.

## Contributing and license

Issues and pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) or open an [issue](https://github.com/wilycode/database-repository/issues).

MIT © 2026 Simon Piter. See [LICENSE](LICENSE).
