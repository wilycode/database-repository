<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository;

use Throwable;
use RuntimeException;
use WilyCode\Database\Model\Model;
use WilyCode\Database\Model\ModelMetadata;
use WilyCode\Database\Model\ModelHydrator;
use WilyCode\Database\Query\Enum\ComparisonOperator;
use WilyCode\Database\Query\Enum\LogicalOperator;
use WilyCode\Database\Query\Query;
use WilyCode\Database\Query\Statement\Delete;
use WilyCode\Database\Query\Statement\Select;
use WilyCode\Database\Query\Statement\Update;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\Repository\EagerLoading\EagerLoadCompiler;
use WilyCode\Database\Repository\Persistence\ModelPersister;
use WilyCode\Database\Repository\Persistence\RelationPersister;
use WilyCode\Database\Repository\Exception\ModelNotFoundException;
use WilyCode\Database\Repository\Exception\UnsafeWriteException;

/**
 * Immutable query repository with model CRUD and relationship persistence.
 *
 * Query modifiers return cloned repository instances. Write methods require an
 * explicit WHERE clause unless they operate on a concrete model primary key.
 *
 * @template T of Model
 */
class Repository
{
    protected QueryExecutorInterface $executor;

    /** @var class-string<T> */
    protected string $modelClass;

    protected string $table;

    protected ModelMetadata $metadata;

    protected string $pkDb;

    /** Optional table alias for SELECTs (from ModelMetadata). */
    protected ?string $tableAlias = null;

    /** @var array<int, array{column:string,value:mixed,comparison:ComparisonOperator,logical:LogicalOperator}> */
    protected array $wheres = [];

    /** @var array<int, array{condition:string,params:array<int|string, mixed>,logical:LogicalOperator}> */
    protected array $rawWheres = [];

    /**
     * @var array<int, array{
     *     path: string,
     *     columns: array<int, string>|string,
     *     conditions: array<string, scalar|list<scalar>|null>
     * }>
     */
    protected array $withs = [];

    /** @var array<int, array{column:string,direction:string,raw:bool}> */
    protected array $orders = [];

    /** @var ModelPersister<T> */
    private ModelPersister $modelPersister;
    private RelationPersister $relationPersister;
    private EagerLoadCompiler $eagerLoadCompiler;

    /**
     * @param class-string<T> $modelClass
     */
    public function __construct(QueryExecutorInterface $executor, string $modelClass)
    {
        $this->executor = $executor;
        $this->modelClass = $modelClass;

        $this->metadata = ModelMetadata::for($modelClass);
        $this->table = $this->metadata->getTableName();
        $this->pkDb = $this->metadata->getPrimaryKeyDbName();

        $this->tableAlias = $this->metadata->getTableAlias();

        $this->modelPersister = new ModelPersister($executor, $modelClass, $this->metadata);
        $this->relationPersister = new RelationPersister($executor);
        $this->eagerLoadCompiler = new EagerLoadCompiler();
    }

    /**
     * Run operations in a DB transaction.
     *
     * @template R
     * @param callable(Repository<T>):R $fn
     * @return R
     * @throws Throwable
     */
    public function transaction(callable $fn): mixed
    {
        return $this->executor->transaction(fn (QueryExecutorInterface $_): mixed => $fn($this));
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getPrimaryKeyDbName(): string
    {
        return $this->pkDb;
    }

    /**
     * Returns the table reference used for correlated expressions (alias when present).
     */
    public function getOuterRef(): string
    {
        return $this->tableAlias ?? $this->table;
    }

    /**
     * Add ORDER BY.
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper(trim($direction));
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException('orderBy(): direction must be ASC or DESC.');
        }

        $clone = clone $this;
        $clone->orders[] = ['column' => $column, 'direction' => $direction, 'raw' => false];
        return $clone;
    }

    /**
     * Add raw ORDER BY expression.
     */
    public function orderByRaw(string $expression, string $direction = 'ASC'): static
    {
        $direction = strtoupper(trim($direction));
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException('orderByRaw(): direction must be ASC or DESC.');
        }

        $clone = clone $this;
        $clone->orders[] = ['column' => $expression, 'direction' => $direction, 'raw' => true];
        return $clone;
    }

    /**
     * Eager-load relationships.
     *
     * Examples:
     * - with('category')
     * - with('category.createdBy', ['id','name'])
     * - with('tags', ['name'])
     * - with('translations', ['title', 'slug'], ['locale' => 'pl'])
     *
     * Columns and conditions apply to the last segment only. A list condition
     * value is mapped to IN; scalar and null values use equality semantics.
     *
     * @param array<int, string>|string $columns
     * @param array<string, scalar|Model|list<scalar|Model>|null> $conditions
     */
    public function with(
        string $path,
        array|string $columns = '*',
        array $conditions = [],
    ): static {
        $columns = $this->eagerLoadCompiler->normalizeColumnsArgument($columns);
        $conditions = $this->eagerLoadCompiler->normalizeRelationConditions($conditions);

        $clone = clone $this;
        $clone->withs[] = [
            'path' => $path,
            'columns' => $columns,
            'conditions' => $conditions,
        ];
        return $clone;
    }

    /**
     * Create a cloned instance with additional WHERE condition.
     */
    public function where(
        string $column,
        mixed $value,
        string|ComparisonOperator $comparisonOperator = ComparisonOperator::EQUALS,
        string|LogicalOperator $logicalOperator = LogicalOperator::AND
    ): static {
        $clone = clone $this;
        $clone->wheres[] = [
            'column' => $column,
            'value' => $value,
            'comparison' => $comparisonOperator instanceof ComparisonOperator ? $comparisonOperator : ComparisonOperator::fromString($comparisonOperator),
            'logical' => $logicalOperator instanceof LogicalOperator ? $logicalOperator : LogicalOperator::fromString($logicalOperator),
        ];
        return $clone;
    }

    /**
     * Create a cloned instance with WHERE <col> IN (...).
     *
     * @param array<int, mixed> $values
     */
    public function whereIn(
        string $column,
        array $values,
        string|LogicalOperator $logicalOperator = LogicalOperator::AND
    ): static {
        return $this->where($column, array_values($values), ComparisonOperator::IN, $logicalOperator);
    }

    /**
     * Convenience: WHERE <col> LIKE %value%.
     */
    public function whereLike(
        string $column,
        string $value,
        string|LogicalOperator $logicalOperator = LogicalOperator::AND
    ): static {
        return $this->where($column, '%' . $value . '%', ComparisonOperator::LIKE, $logicalOperator);
    }

    /**
     * Create a cloned instance with additional raw WHERE condition.
     *
     * @param array<int|string, mixed> $params
     */
    public function whereRaw(
        string $condition,
        array $params = [],
        string|LogicalOperator $logicalOperator = LogicalOperator::AND
    ): static {
        $clone = clone $this;
        $clone->rawWheres[] = [
            'condition' => $condition,
            'params' => $params,
            'logical' => $logicalOperator instanceof LogicalOperator ? $logicalOperator : LogicalOperator::fromString($logicalOperator),
        ];
        return $clone;
    }

    /**
     * SELECT all matching rows and hydrate models.
     *
     * @return array<int, T>
     */
    public function findAll(): array
    {
        $q = $this->buildSelect(includeWith: true);
        $rows = $this->executor->fetchAll($q);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydratePersistedModel($row);
        }
        return $items;
    }

    /**
     * SELECT first matching row (LIMIT 1) and hydrate model.
     *
     * @return T|null
     */
    public function findOne(): ?Model
    {
        $q = $this->buildSelect(includeWith: true)->limit(1);
        $row = $this->executor->fetch($q);
        if ($row === []) {
            return null;
        }
        return $this->hydratePersistedModel($row);
    }


    /**
     * SELECT first matching row (LIMIT 1) and hydrate model.
     *
     * @throws ModelNotFoundException
     * @return T
     */
    public function findOneOrThrow(?string $message = null): Model
    {
        $m = $this->findOne();
        if ($m === null) {
            throw ModelNotFoundException::forModel($this->modelClass, $message);
        }
        return $m;
    }

    /**
     * Find by primary key.
     *
     * @param int|string $id
     * @return T|null
     */
    public function find(int|string $id): ?Model
    {
        return $this->where($this->pkDb, $id)->findOne();
    }


    /**
     * Find by primary key or throw.
     *
     * @param int|string $id
     * @throws ModelNotFoundException
     * @return T
     */
    public function findOrThrow(int|string $id, ?string $message = null): Model
    {
        $m = $this->find($id);
        if ($m === null) {
            throw ModelNotFoundException::forModel($this->modelClass, $message, ['id' => $id]);
        }
        return $m;
    }

    /**
     * Paginate results.
     *
     * Deterministic ordering: when no orderBy is provided, orders by PK ASC.
     * When custom sorts are used, PK ASC is appended as a tie-breaker.
     *
     * @return PaginationResult<T>
     */
    public function paginate(int $page = 1, int $perPage = 20): PaginationResult
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('paginate(): page must be at least 1.');
        }
        if ($perPage < 1) {
            throw new \InvalidArgumentException('paginate(): perPage must be at least 1.');
        }

        $offset = ($page - 1) * $perPage;

        // IMPORTANT: count query should NOT include eager-load subqueries.
        $baseNoWith = $this->buildSelect(includeWith: false);
        $countQuery = new \WilyCode\Database\Repository\Query\PaginationCountQuery($baseNoWith);
        $total = (int) ($this->executor->fetchColumn($countQuery) ?? 0);

        $q = $this->buildSelect(includeWith: true)
            ->limit($perPage)
            ->offset($offset);

        $rows = $this->executor->fetchAll($q);
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydratePersistedModel($row);
        }

        return new PaginationResult(
            items: $items,
            total: $total,
            page: $page,
            perPage: $perPage
        );
    }

    /**
     * Hydrate a row returned by this repository.
     *
     * Repository SELECTs always include the mapped primary key. A row returned
     * from the database therefore represents a persisted model and must have a
     * non-null primary key. Treat a missing key as a repository/hydration bug,
     * not as a state callers should defensively handle.
     *
     * @param array<string, mixed> $row
     * @return T
     */
    protected function hydratePersistedModel(array $row): Model
    {
        $model = ModelHydrator::hydrate(
            $this->modelClass,
            $row,
            decodeJson: true,
            mapDbNames: true,
            createReferenceObjects: true,
        );

        if ($model->getPrimaryKeyValue() === null) {
            throw new RuntimeException(sprintf(
                'Hydrated %s model has no primary key.',
                $this->modelClass,
            ));
        }

        return $model;
    }

    // MODEL ops (row-level CRUD by primary key)

    /**
     * Insert a new model and assign its generated primary key when available.
     *
     * @param T $m
     * @return T
     */
    public function createModel(Model $m): Model
    {
        return $this->modelPersister->create($m);
    }

    /**
     * Update dirty fields of an existing model by primary key.
     *
     * @param T $m
     * @return T
     */
    public function updateModel(Model $m): Model
    {
        return $this->modelPersister->update($m);
    }

    /**
     * Delete an existing model by primary key.
     *
     * @param T $m
     */
    public function deleteModel(Model $m): int
    {
        return $this->modelPersister->delete($m);
    }

    /**
     * Insert, update or no-op according to model persistence state.
     *
     * @param T $m
     * @return T
     */
    public function saveModel(Model $m): Model
    {
        return $this->modelPersister->save($m);
    }

    /**
     * @param array<int, int|string|Model> $idsOrModels
     * @return array{attached:int, detached:int}
     */
    public function syncManyToMany(Model $model, string $relName, array $idsOrModels): array
    {
        return $this->relationPersister->syncManyToMany($model, $relName, $idsOrModels);
    }

    /**
     * @param array<int, Model> $models
     * @return array{inserted:int, updated:int, deleted:int, detached:int}
     */
    public function replaceHasMany(Model $model, string $relName, array $models, bool $deleteOrphans = true): array
    {
        return $this->relationPersister->replaceHasMany($model, $relName, $models, $deleteOrphans);
    }

    /** @return array{inserted:int, updated:int, deleted:int, detached:int} */
    public function replaceHasOne(Model $model, string $relName, ?Model $child, bool $deleteOrphan = true): array
    {
        return $this->relationPersister->replaceHasOne($model, $relName, $child, $deleteOrphan);
    }

    /** @return array{relatedInserted:int, relatedUpdated:int, ownerUpdated:int, ownerDetached:int} */
    public function syncHasOne(Model $model, string $relName, ?Model $related): array
    {
        return $this->relationPersister->syncHasOne($model, $relName, $related);
    }

    /**
     * Insert one row and return last insert id (string on PDO).
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values): string
    {
        $q = Query::insert($this->table)->values($values);
        $this->executor->execute($q);
        return (string)$this->executor->getLastInsertId();
    }

    /**
     * Insert many rows.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return $this->executor->execute(Query::insert($this->table)->valuesMany($rows));
    }

    /**
     * Update rows matching current WHERE.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        if (!$this->hasWhere()) {
            throw new UnsafeWriteException('Unsafe UPDATE: missing WHERE clause.');
        }

        $q = Query::update($this->table)->set($values);
        $this->applyWheres($q);
        return $this->executor->execute($q);
    }

    /**
     * Delete rows matching current WHERE.
     */
    public function delete(): int
    {
        if (!$this->hasWhere()) {
            throw new UnsafeWriteException('Unsafe DELETE: missing WHERE clause.');
        }

        $q = Query::delete($this->table);
        $this->applyWheres($q);
        return $this->executor->execute($q);
    }

    /**
     * Build SELECT query with scalar columns of the model and applied WHERE conditions.
     */
    protected function buildSelect(bool $includeWith): Select
    {
        $attr = $this->metadata;

        $q = Query::select($this->table, $this->tableAlias);

        // Select mapped DB columns. ForeignKey+HasOne properties remain scalar FK
        // columns until the full relation is explicitly requested through with().
        $dbProps = $attr->getScalarDbProperties();

        $cols = [];
        foreach ($dbProps as $dbName) {
            $expr = $dbName;
            $cols[$dbName] = $expr;
        }
        $q->columns($cols);

        if ($includeWith && $this->withs !== []) {
            $this->applyWiths($q, $attr);
        }

        $this->applyWheres($q);

        // Default deterministic ordering when no custom orderBy provided.
        // Also ensures stable pagination when custom sorts are used.
        $this->applyOrders($q);

        return $q;
    }

    protected function applyOrders(Select $q): void
    {
        if ($this->orders === []) {
            $q->orderBy($this->pkDb, 'ASC');
            return;
        }

        $hasPk = false;
        foreach ($this->orders as $o) {
            $q->orderBy($o['column'], $o['direction']);

            if (!$o['raw'] && $o['column'] === $this->pkDb) {
                $hasPk = true;
            }
        }

        // Tie-breaker for stable ordering.
        if (!$hasPk) {
            $q->orderBy($this->pkDb, 'ASC');
        }
    }


    protected function applyWiths(Select $q, ModelMetadata $rootAttr): void
    {
        $this->eagerLoadCompiler->apply(
            query: $q,
            rootModelClass: $this->modelClass,
            rootMetadata: $rootAttr,
            rootRef: $this->getOuterRef(),
            withs: $this->withs,
        );
    }

    protected function hasWhere(): bool
    {
        return $this->wheres !== [] || $this->rawWheres !== [];
    }

    /**
     * @param Update|Delete|Select $q
     */
    protected function applyWheres(Update|Delete|Select $q): void
    {
        foreach ($this->wheres as $w) {
            $col = $w['column'];
            $q->where($col, $w['value'], $w['comparison'], $w['logical']);
        }
        foreach ($this->rawWheres as $w) {
            $q->whereRaw($w['condition'], $w['params'], $w['logical']);
        }
    }

}
