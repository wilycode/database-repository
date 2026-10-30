<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\EagerLoading;

use WilyCode\Database\Repository\RelationForeignKey;

use InvalidArgumentException;
use RuntimeException;
use WilyCode\Database\Model\Attribute\HasMany;
use WilyCode\Database\Model\Attribute\HasManyToMany;
use WilyCode\Database\Model\Attribute\HasOne;
use WilyCode\Database\Model\Model;
use WilyCode\Database\Model\ModelMetadata;
use WilyCode\Database\Query\Enum\ComparisonOperator;
use WilyCode\Database\Query\Expression\Expression;
use WilyCode\Database\Query\Expression\JsonArraySubquery;
use WilyCode\Database\Query\Expression\JsonObject;
use WilyCode\Database\Query\Expression\Subquery;
use WilyCode\Database\Query\Query;
use WilyCode\Database\Query\Statement\Select;
use WilyCode\Database\Repository\WithNode;
use WilyCode\Database\Repository\WithTree;

/**
 * Compiles declared with() paths into correlated relation projections.
 *
 * @internal
 */
final class EagerLoadCompiler
{
    /**
     * @param class-string<Model> $rootModelClass
     * @param array<int, array{path:string,columns:array<int,string>|string,conditions:array<string,scalar|list<scalar>|null>}> $withs
     */
    public function apply(
        Select $query,
        string $rootModelClass,
        ModelMetadata $rootMetadata,
        string $rootRef,
        array $withs,
    ): void {
        $tree = new WithTree();
        foreach ($withs as $with) {
            $tree->add($with['path'], $with['columns'], $with['conditions']);
        }

        if ($tree->isEmpty()) {
            return;
        }

        foreach ($tree->roots() as $node) {
            $expression = $this->buildRelationExpression(
                ownerModelClass: $rootModelClass,
                ownerAttr: $rootMetadata,
                ownerRef: $rootRef,
                node: $node,
            );

            $query->columnExp($node->name, $expression);
        }
    }

    /**
     * Build an Expression representing the eager-loaded relation.
     */
    private function buildRelationExpression(
        string $ownerModelClass,
        ModelMetadata $ownerAttr,
        string $ownerRef,
        WithNode $node
    ): Expression {
        if (!$ownerAttr->hasRelationship($node->name)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown relationship "%s" on model "%s".',
                $node->name,
                $ownerModelClass
            ));
        }

        $rel = $ownerAttr->getRelationship($node->name);
        $relatedClass = $rel->getModelName();
        if ($relatedClass === null) {
            throw new \RuntimeException('Relationship has no modelName: ' . $ownerModelClass . '::$' . $node->name);
        }

        $relatedAttr = ModelMetadata::for($relatedClass);
        $relatedTable = (string)$rel->getTableName();
        $relatedAlias = $rel->getTableAlias();
        if ($relatedAlias === null || $relatedAlias === '') {
            $relatedAlias = $this->makeAlias($node->name);
        }

        if ($rel instanceof HasOne) {
            $sub = Query::select($relatedTable, $relatedAlias);
            $this->applyRelationConditions($sub, $relatedClass, $relatedAttr, $relatedAlias, $node->conditions);

            $json = $this->buildJsonObject(
                modelClass: $relatedClass,
                attr: $relatedAttr,
                tableRef: $relatedAlias,
                columns: $node->columns,
                children: $node->children
            );

            // BelongsTo style (owner has FK property with the same relationship name)
            if ($ownerAttr->hasForeignKey($node->name)) {
                $fkDb = $ownerAttr->getForeignKeyDbName($node->name);
                $pkDb = $relatedAttr->getPrimaryKeyDbName();

                $sub->columnExp('rel', $json)
                    ->whereColumn($relatedAlias . '.' . $pkDb, $ownerRef . '.' . $fkDb)
                    ->limit(1);

                return new Subquery($sub);
            }

            // HasOne style (related has FK pointing to owner's PK)
            $fkDb = $rel->getForeignKey() ?? RelationForeignKey::fromOwnerTable($ownerAttr->getTableName());
            $pkDb = $ownerAttr->getPrimaryKeyDbName();

            $sub->columnExp('rel', $json)
                ->whereColumn($relatedAlias . '.' . $fkDb, $ownerRef . '.' . $pkDb)
                ->limit(1);

            return new Subquery($sub);
        }

        if ($rel instanceof HasMany) {
            $sub = Query::select($relatedTable, $relatedAlias);
            $this->applyRelationConditions($sub, $relatedClass, $relatedAttr, $relatedAlias, $node->conditions);

            $fkDb = $rel->getForeignKey() ?? RelationForeignKey::fromOwnerTable($ownerAttr->getTableName());
            $pkDb = $ownerAttr->getPrimaryKeyDbName();

            $json = $this->buildJsonObject(
                modelClass: $relatedClass,
                attr: $relatedAttr,
                tableRef: $relatedAlias,
                columns: $node->columns,
                children: $node->children,
                ensureDbColumns: [$fkDb]
            );

            $sub->whereColumn($relatedAlias . '.' . $fkDb, $ownerRef . '.' . $pkDb);

            return JsonArraySubquery::of($sub, $json, coalesceEmptyArray: true, distinct: false);
        }

        // HasOne and HasMany returned above, so the remaining declared relation type is HasManyToMany.
        $pivotTable = $rel->getLinkingTable();
        $pivotColumnOwner = $rel->getLinkingColumn();
        $pivotColumnRelated = $rel->getForeignKey();

        if ($pivotTable === null || $pivotColumnOwner === null || $pivotColumnRelated === null) {
            throw new \RuntimeException(sprintf('HasManyToMany "%s" is missing linkingTable/linkingColumn/foreignKey.', $node->name));
        }

        $pivotAlias = 'p_' . ($relatedAlias !== '' ? $relatedAlias : $this->makeAlias($node->name));
        $relatedPkDb = $relatedAttr->getPrimaryKeyDbName();
        $ownerPkDb = $ownerAttr->getPrimaryKeyDbName();

        $sub = Query::select($relatedTable, $relatedAlias)
            ->join($pivotTable, $pivotAlias, '1 = 1')
            ->whereColumn($pivotAlias . '.' . $pivotColumnRelated, $relatedAlias . '.' . $relatedPkDb);
        $this->applyRelationConditions($sub, $relatedClass, $relatedAttr, $relatedAlias, $node->conditions);

        $json = $this->buildJsonObject(
            modelClass: $relatedClass,
            attr: $relatedAttr,
            tableRef: $relatedAlias,
            columns: $node->columns,
            children: $node->children
        );

        $sub->whereColumn($pivotAlias . '.' . $pivotColumnOwner, $ownerRef . '.' . $ownerPkDb);

        // MariaDB is picky about JSON_ARRAYAGG(DISTINCT JSON_OBJECT(...)) and may throw SQL 1064.
        // In our test schema the pivot does not produce duplicates, so we can safely aggregate without DISTINCT.
        // If you ever expect duplicates, dedupe in the subquery (e.g. DISTINCT/GROUP BY on PK) instead.
        return JsonArraySubquery::of($sub, $json, coalesceEmptyArray: true, distinct: false);
    }

    /**
     * Build JsonObject for a model instance, including nested eager relations.
     *
     * Keys are DB column names for scalar properties, and relationship property names for nested relations.
     *
     * @param array<int, string>|string $columns
     * @param array<string, WithNode> $children
     * @param array<int, string> $ensureDbColumns Always include these DB columns even if not requested.
     */
    private function buildJsonObject(
        string $modelClass,
        ModelMetadata $attr,
        string $tableRef,
        array|string $columns,
        array $children,
        array $ensureDbColumns = [],
    ): JsonObject {
        $dbCols = $this->resolveColumnsToDbNames($attr, $columns);

        // Always include PK.
        $pkDb = $attr->getPrimaryKeyDbName();
        if (!in_array($pkDb, $dbCols, true)) {
            $dbCols[] = $pkDb;
        }

        // Always include requested internal FK columns (e.g., for HasMany/HasOne-child).
        foreach ($ensureDbColumns as $col) {
            if (!in_array($col, $dbCols, true)) {
                $dbCols[] = $col;
            }
        }

        $fields = [];
        foreach ($dbCols as $db) {
            $fields[$db] = $tableRef . '.' . $db;
        }

        foreach ($children as $child) {
            $fields[$child->name] = $this->buildRelationExpression(
                ownerModelClass: $modelClass,
                ownerAttr: $attr,
                ownerRef: $tableRef,
                node: $child
            );
        }

        return JsonObject::of($fields);
    }

    /**
     * @param array<int, string>|string $columns
     * @return list<string> list of DB column names
     */
    private function resolveColumnsToDbNames(ModelMetadata $attr, array|string $columns): array
    {
        if ($columns === '*') {
            $dbProps = $attr->getScalarDbProperties();
            return array_values($dbProps);
        }

        $cols = is_array($columns) ? $columns : [$columns];

        $out = [];
        foreach ($cols as $c) {
            $c = trim((string)$c);
            if ($c === '' || $c === '*') {
                continue;
            }

            if ($attr->hasProperty($c)) {
                $out[] = $attr->getProperty($c)->getDbName();
                continue;
            }

            $out[] = $c;
        }

        return array_values(array_unique($out));
    }

    /**
     * Normalize columns argument so with() can store it safely.
     *
     * @param array<int, string>|string $columns
     * @return array<int, string>|string
     */
    public function normalizeColumnsArgument(array|string $columns): array|string
    {
        if (is_string($columns)) {
            $columns = trim($columns);
            if ($columns === '' || $columns === '*') {
                return '*';
            }

            return [$columns];
        }

        $parts = [];
        foreach ($columns as $c) {
            $c = trim((string)$c);
            if ($c === '' || $c === '*') {
                continue;
            }
            $parts[] = $c;
        }

        return $parts === [] ? '*' : $parts;
    }

    /**
     * @param array<string, scalar|Model|array<array-key, scalar|Model>|null> $conditions
     * @return array<string, scalar|list<scalar>|null>
     */
    public function normalizeRelationConditions(array $conditions): array
    {
        $normalized = [];

        foreach ($conditions as $field => $value) {
            if (trim($field) === '') {
                throw new InvalidArgumentException('with(): relation condition names must be non-empty strings.');
            }

            $field = trim($field);
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'with(): relation condition "%s" must be a scalar, model, null or a list of scalars/models.',
                        $field,
                    ));
                }

                $items = [];
                foreach ($value as $item) {
                    $items[] = $this->normalizeRelationConditionValue($field, $item);
                }
                $value = $items;
            } elseif ($value !== null) {
                $value = $this->normalizeRelationConditionValue($field, $value);
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    private function normalizeRelationConditionValue(string $field, mixed $value): int|string|float|bool
    {
        if ($value instanceof Model) {
            $value = $value->getPrimaryKeyValue();
            if ($value === null) {
                throw new InvalidArgumentException(sprintf(
                    'with(): relation condition "%s" contains a model without a primary key.',
                    $field,
                ));
            }
        }

        if (!is_scalar($value)) {
            throw new InvalidArgumentException(sprintf(
                'with(): relation condition "%s" must contain only scalars or persisted models.',
                $field,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $conditions
     */
    private function applyRelationConditions(
        Select $query,
        string $modelClass,
        ModelMetadata $metadata,
        string $tableAlias,
        array $conditions,
    ): void {
        foreach ($conditions as $field => $value) {
            if ($metadata->hasProperty($field)) {
                $dbName = $metadata->getProperty($field)->getDbName();
            } elseif ($metadata->mapDbNameToPropertyName($field) !== null) {
                $dbName = $field;
            } else {
                throw new InvalidArgumentException(sprintf(
                    'with(): unknown condition field "%s" on model "%s".',
                    $field,
                    $modelClass,
                ));
            }

            $query->where(
                $tableAlias . '.' . $dbName,
                $value,
                is_array($value) ? ComparisonOperator::IN : ComparisonOperator::EQUALS,
            );
        }
    }


    private function makeAlias(string $name): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]+/', '_', $name);
        $alias = strtolower((string) $alias);
        if ($alias === '') {
            $alias = 'r';
        }

        return substr($alias, 0, 20);
    }


}

