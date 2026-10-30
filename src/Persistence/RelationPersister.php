<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Persistence;

use InvalidArgumentException;
use WilyCode\Database\Repository\RelationForeignKey;
use RuntimeException;
use WilyCode\Database\Model\Attribute\HasMany;
use WilyCode\Database\Model\Attribute\HasManyToMany;
use WilyCode\Database\Model\Attribute\HasOne;
use WilyCode\Database\Model\Model;
use WilyCode\Database\Model\ModelMetadata;
use WilyCode\Database\Model\PersistenceAction;
use WilyCode\Database\Query\Clause\WhereClause;
use WilyCode\Database\Query\Query;
use WilyCode\Database\Query\Statement\Delete;
use WilyCode\Database\Query\Statement\Insert;
use WilyCode\Database\Query\Statement\Update;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;

/**
 * Synchronizes declared model relationships.
 *
 * @internal
 */
final class RelationPersister
{
    public function __construct(private readonly QueryExecutorInterface $executor)
    {
    }

    /**
     * @param array<int, int|string|Model> $idsOrModels
     * @return array{attached:int, detached:int}
     */
    public function syncManyToMany(Model $model, string $relName, array $idsOrModels): array
    {
        $ownerModelClass = $model::class;
        $ownerAttrs = ModelMetadata::for($ownerModelClass);

        if (!$ownerAttrs->hasRelationship($relName)) {
            throw new InvalidArgumentException(sprintf('Unknown relation "%s" on "%s".', $relName, $ownerModelClass));
        }

        $rel = $ownerAttrs->getRelationship($relName);
        if (!$rel instanceof HasManyToMany) {
            throw new InvalidArgumentException(sprintf('Relation "%s" on "%s" is not HasManyToMany.', $relName, $ownerModelClass));
        }

        $ownerPk = $model->getPrimaryKeyValue();
        if ($ownerPk === null) {
            throw new InvalidArgumentException(sprintf('Cannot syncManyToMany("%s") when owner PK is null (%s).', $relName, $ownerModelClass));
        }

        $relatedModel = $rel->getModelName();
        if ($relatedModel === null) {
            throw new InvalidArgumentException(sprintf('HasManyToMany "%s" has no modelName configured.', $relName));
        }

        $pivotTable = $rel->getLinkingTable();
        if ($pivotTable === null) {
            throw new InvalidArgumentException(sprintf('HasManyToMany "%s" has no linkingTable configured.', $relName));
        }

        $linkingColumn = $rel->getLinkingColumn() ?? RelationForeignKey::fromOwnerTable($ownerAttrs->getTableName());
        $foreignKey = $rel->getForeignKey();
        if ($foreignKey === null) {
            throw new InvalidArgumentException(sprintf('HasManyToMany "%s" has no foreignKey configured.', $relName));
        }

        $desired = $this->normalizeRelatedIds($idsOrModels, $relatedModel);

        return $this->executor->transaction(function (QueryExecutorInterface $ex) use (
            $pivotTable,
            $linkingColumn,
            $foreignKey,
            $ownerPk,
            $desired
        ): array {
            // Fetch existing related IDs from pivot.
            $sq = Query::select($pivotTable, 'p');
            $sq->columns(['rid' => 'p.' . $foreignKey]);
            $sq->where('p.' . $linkingColumn, $ownerPk);

            /** @var array<int,array<string,mixed>> $rows */
            $rows = $ex->fetchAll($sq);
            $existing = [];
            foreach ($rows as $r) {
                if (array_key_exists('rid', $r) && $r['rid'] !== null) {
                    $existing[] = (string)$r['rid'];
                }
            }

            $desiredSet = array_values(array_unique(array_map('strval', $desired)));
            $existingSet = array_values(array_unique($existing));

            $toAttach = array_values(array_diff($desiredSet, $existingSet));
            $toDetach = array_values(array_diff($existingSet, $desiredSet));

            if ($toAttach !== []) {
                $rowsToInsert = [];
                foreach ($toAttach as $rid) {
                    $rowsToInsert[] = [
                        $linkingColumn => $ownerPk,
                        $foreignKey => $rid,
                    ];
                }

                /** @var Insert $ins */
                $ins = Query::insert($pivotTable)->valuesMany($rowsToInsert);
                $ex->execute($ins);
            }

            if ($toDetach !== []) {
                /** @var Delete $del */
                $del = Query::delete($pivotTable);
                $del->where($linkingColumn, $ownerPk);
                $this->whereInValues($del, $foreignKey, $toDetach);
                $ex->execute($del);
            }

            return [
                'attached' => count($toAttach),
                'detached' => count($toDetach),
            ];
        });
    }

    /**
     * Replace a has-many relation with the given list of models.
     *
     * - Ensures each child model has its FK set to the owner's PK.
     * - Inserts new children (PK null).
     * - Updates existing children (PK not null) at least to enforce FK.
     * - Deletes or detaches orphans (rows currently linked but not present in the given list).
     *
     * @param Model $model Owner model (must have PK set)
     * @param string $relName Direct relation name on the owner model (HasMany)
     * @param array<int, Model> $models List of related model instances
     * @param bool $deleteOrphans When false, orphans are detached (FK set to NULL) instead of deleted
     * @return array{inserted:int, updated:int, deleted:int, detached:int}
     */
    public function replaceHasMany(Model $model, string $relName, array $models, bool $deleteOrphans = true): array
    {
        $ownerModelClass = $model::class;
        $ownerAttrs = ModelMetadata::for($ownerModelClass);

        if (!$ownerAttrs->hasRelationship($relName)) {
            throw new InvalidArgumentException(sprintf('Unknown relation "%s" on "%s".', $relName, $ownerModelClass));
        }

        $rel = $ownerAttrs->getRelationship($relName);
        if (!$rel instanceof HasMany) {
            throw new InvalidArgumentException(sprintf('Relation "%s" on "%s" is not HasMany.', $relName, $ownerModelClass));
        }

        $ownerPk = $model->getPrimaryKeyValue();
        if ($ownerPk === null) {
            throw new InvalidArgumentException(sprintf('Cannot replaceHasMany("%s") when owner PK is null (%s).', $relName, $ownerModelClass));
        }

        $relatedModel = $rel->getModelName();
        if ($relatedModel === null) {
            throw new InvalidArgumentException(sprintf('HasMany "%s" has no modelName configured.', $relName));
        }
        $relatedAttrs = ModelMetadata::for($relatedModel);

        $relatedTable = $relatedAttrs->getTableName();
        $relatedPkDb = $relatedAttrs->getPrimaryKeyDbName();

        // FK lives on the related table and points to the owner's PK.
        $fkDb = $rel->getForeignKey() ?? RelationForeignKey::fromOwnerTable($ownerAttrs->getTableName());

        return $this->executor->transaction(function (QueryExecutorInterface $ex) use (
            $models,
            $model,
            $relatedModel,
            $relatedAttrs,
            $relatedTable,
            $relatedPkDb,
            $fkDb,
            $ownerPk,
            $deleteOrphans,
            $relName,
            $ownerModelClass
        ): array {
            // Load currently linked child IDs (before changes).
            $currentQ = Query::select($relatedTable, 'c');
            $currentQ->columns(['cid' => 'c.' . $relatedPkDb]);
            $currentQ->where('c.' . $fkDb, $ownerPk);

            /** @var array<int,array<string,mixed>> $currentRows */
            $currentRows = $ex->fetchAll($currentQ);
            $existingIds = [];
            foreach ($currentRows as $r) {
                if (array_key_exists('cid', $r) && $r['cid'] !== null) {
                    $existingIds[] = (string)$r['cid'];
                }
            }
            $existingIds = array_values(array_unique($existingIds));

            $inserted = 0;
            $updated = 0;
            $deleted = 0;
            $detached = 0;

            $desiredIds = [];

            foreach ($models as $child) {
                if (!is_a($child, $relatedModel)) {
                    throw new InvalidArgumentException(sprintf(
                        'replaceHasMany("%s"): expected instances of "%s", got "%s" (owner: %s).',
                        $relName,
                        $relatedModel,
                        $child::class,
                        $ownerModelClass
                    ));
                }

                $this->assignOwner($child, $relatedAttrs, $fkDb, $model);

                $childPk = $child->getPrimaryKeyValue();

                if ($childPk === null) {
                    $data = $this->normalizeWriteValues($child->getInsertDbValues(withExtraData: true));
                    $data[$fkDb] = $ownerPk;

                    /** @var Insert $ins */
                    $ins = Query::insert($relatedTable)->values($data);
                    $ex->execute($ins);

                    $newId = $ex->getLastInsertId();
                    if ($newId !== '' && $child->getPrimaryKeyValue() === null) {
                        $pkProp = $relatedAttrs->getPrimaryKeyPropertyName();
                        $child->setProperty($pkProp, ctype_digit($newId) ? (int)$newId : $newId);
                    }

                    $childPk = $child->getPrimaryKeyValue();
                    if ($childPk !== null) {
                        $desiredIds[] = (string)$childPk;
                    }

                    $child->markHydrated(withExtraData: true);
                    $inserted++;
                    continue;
                }

                // Existing row: update at least the FK, optionally other dirty fields.
                $data = $this->normalizeWriteValues($child->getUpdateDbValues(onlyDirty: true, withExtraData: true));
                $data[$fkDb] = $ownerPk;

                /** @var Update $u */
                $u = Query::update($relatedTable)->set($data);
                $u->where($relatedPkDb, $childPk);
                $ex->execute($u);

                $desiredIds[] = (string)$childPk;
                $child->markHydrated(withExtraData: true);
                $updated++;
            }

            $desiredIds = array_values(array_unique($desiredIds));
            $orphans = array_values(array_diff($existingIds, $desiredIds));

            if ($orphans !== []) {
                if ($deleteOrphans) {
                    /** @var Delete $del */
                    $del = Query::delete($relatedTable);
                    $del->where($fkDb, $ownerPk);
                    $this->whereInValues($del, $relatedPkDb, $orphans);
                    $ex->execute($del);
                    $deleted = count($orphans);
                } else {
                    /** @var Update $u */
                    $u = Query::update($relatedTable)->set([$fkDb => null]);
                    $u->where($fkDb, $ownerPk);
                    $this->whereInValues($u, $relatedPkDb, $orphans);
                    $ex->execute($u);
                    $detached = count($orphans);
                }
            }

            return [
                'inserted' => $inserted,
                'updated' => $updated,
                'deleted' => $deleted,
                'detached' => $detached,
            ];
        });
    }

    /**
     * Replace a has-one relation.
     *
     * Use this when the related table holds the FK pointing to the owner (classic HasOne).
     * If your HasOne property is also marked as #[ForeignKey] on the owner, then you're
     * in a belongs-to style and you likely want syncHasOne() instead.
     *
     * @param Model $model Owner model (must have PK set)
     * @param string $relName Direct relation name on the owner model (HasOne)
     * @param Model|null $child Related model instance (or null to remove)
     * @param bool $deleteOrphan When false, orphan(s) are detached (FK set to NULL) instead of deleted
     * @return array{inserted:int, updated:int, deleted:int, detached:int}
     */
    public function replaceHasOne(Model $model, string $relName, ?Model $child, bool $deleteOrphan = true): array
    {
        $ownerModelClass = $model::class;
        $ownerAttrs = ModelMetadata::for($ownerModelClass);

        if (!$ownerAttrs->hasRelationship($relName)) {
            throw new InvalidArgumentException(sprintf('Unknown relation "%s" on "%s".', $relName, $ownerModelClass));
        }

        $rel = $ownerAttrs->getRelationship($relName);
        if (!$rel instanceof HasOne) {
            throw new InvalidArgumentException(sprintf('Relation "%s" on "%s" is not HasOne.', $relName, $ownerModelClass));
        }

        // If this property is also a foreign-key column on the owner, treat it as belongs-to.
        if ($ownerAttrs->hasForeignKey($relName)) {
            throw new InvalidArgumentException(sprintf(
                'replaceHasOne("%s") cannot be used when "%s::$%s" is marked as #[ForeignKey]. Use syncHasOne() instead.',
                $relName,
                $ownerModelClass,
                $relName
            ));
        }

        $ownerPk = $model->getPrimaryKeyValue();
        if ($ownerPk === null) {
            throw new InvalidArgumentException(sprintf('Cannot replaceHasOne("%s") when owner PK is null (%s).', $relName, $ownerModelClass));
        }

        $relatedModel = $rel->getModelName();
        if ($relatedModel === null) {
            throw new InvalidArgumentException(sprintf('HasOne "%s" has no modelName configured.', $relName));
        }
        $relatedAttrs = ModelMetadata::for($relatedModel);

        $relatedTable = $relatedAttrs->getTableName();
        $relatedPkDb = $relatedAttrs->getPrimaryKeyDbName();

        // FK lives on the related table and points to the owner's PK.
        $fkDb = $rel->getForeignKey() ?? RelationForeignKey::fromOwnerTable($ownerAttrs->getTableName());

        return $this->executor->transaction(function (QueryExecutorInterface $ex) use (
            $child,
            $model,
            $relatedModel,
            $relatedAttrs,
            $relatedTable,
            $relatedPkDb,
            $fkDb,
            $ownerPk,
            $deleteOrphan,
            $relName,
            $ownerModelClass
        ): array {
            // Load current children linked to owner (should be max 1, but we handle "dirty" data).
            $currentQ = Query::select($relatedTable, 'c');
            $currentQ->columns(['cid' => 'c.' . $relatedPkDb]);
            $currentQ->where('c.' . $fkDb, $ownerPk);

            /** @var array<int,array<string,mixed>> $currentRows */
            $currentRows = $ex->fetchAll($currentQ);
            $existingIds = [];
            foreach ($currentRows as $r) {
                if (array_key_exists('cid', $r) && $r['cid'] !== null) {
                    $existingIds[] = (string)$r['cid'];
                }
            }
            $existingIds = array_values(array_unique($existingIds));

            $inserted = 0;
            $updated = 0;
            $deleted = 0;
            $detached = 0;

            $desiredId = null;

            if ($child !== null) {
                if (!is_a($child, $relatedModel)) {
                    throw new InvalidArgumentException(sprintf(
                        'replaceHasOne("%s"): expected instance of "%s", got "%s" (owner: %s).',
                        $relName,
                        $relatedModel,
                        $child::class,
                        $ownerModelClass
                    ));
                }

                $this->assignOwner($child, $relatedAttrs, $fkDb, $model);

                $childPk = $child->getPrimaryKeyValue();
                if ($childPk === null) {
                    $data = $this->normalizeWriteValues($child->getInsertDbValues(withExtraData: true));
                    $data[$fkDb] = $ownerPk;

                    /** @var Insert $ins */
                    $ins = Query::insert($relatedTable)->values($data);
                    $ex->execute($ins);

                    $newId = $ex->getLastInsertId();
                    if ($newId !== '' && $child->getPrimaryKeyValue() === null) {
                        $pkProp = $relatedAttrs->getPrimaryKeyPropertyName();
                        $child->setProperty($pkProp, ctype_digit($newId) ? (int)$newId : $newId);
                    }

                    $childPk = $child->getPrimaryKeyValue();
                    $desiredId = $childPk !== null ? (string)$childPk : null;
                    $child->markHydrated(withExtraData: true);
                    $inserted++;
                } else {
                    $data = $this->normalizeWriteValues($child->getUpdateDbValues(onlyDirty: true, withExtraData: true));
                    $data[$fkDb] = $ownerPk;

                    /** @var Update $u */
                    $u = Query::update($relatedTable)->set($data);
                    $u->where($relatedPkDb, $childPk);
                    $ex->execute($u);

                    $desiredId = (string)$childPk;
                    $child->markHydrated(withExtraData: true);
                    $updated++;
                }
            }

            $orphans = $desiredId === null
                ? $existingIds
                : array_values(array_diff($existingIds, [$desiredId]));

            if ($orphans !== []) {
                if ($deleteOrphan) {
                    /** @var Delete $del */
                    $del = Query::delete($relatedTable);
                    $del->where($fkDb, $ownerPk);
                    $this->whereInValues($del, $relatedPkDb, $orphans);
                    $ex->execute($del);
                    $deleted = count($orphans);
                } else {
                    /** @var Update $u */
                    $u = Query::update($relatedTable)->set([$fkDb => null]);
                    $u->where($fkDb, $ownerPk);
                    $this->whereInValues($u, $relatedPkDb, $orphans);
                    $ex->execute($u);
                    $detached = count($orphans);
                }
            }

            return [
                'inserted' => $inserted,
                'updated' => $updated,
                'deleted' => $deleted,
                'detached' => $detached,
            ];
        });
    }

    /**
     * Sync a belongs-to style HasOne relation (owner holds the FK).
     *
     * This is designed for the pattern:
     *   #[ForeignKey('category_id')] #[HasOne] private ?Category $category;
     *
     * The method ensures the related model is persisted (insert/update) so it has a PK,
     * then (if owner is already persisted) updates the owner's FK column.
     *
     * @param Model $model Owner model
     * @param string $relName Direct relation name on the owner model (HasOne)
     * @param Model|null $related Related model (or null to detach)
     * @return array{relatedInserted:int, relatedUpdated:int, ownerUpdated:int, ownerDetached:int}
     */
    public function syncHasOne(Model $model, string $relName, ?Model $related): array
    {
        $ownerModelClass = $model::class;
        $ownerAttrs = ModelMetadata::for($ownerModelClass);

        if (!$ownerAttrs->hasRelationship($relName)) {
            throw new InvalidArgumentException(sprintf('Unknown relation "%s" on "%s".', $relName, $ownerModelClass));
        }

        $rel = $ownerAttrs->getRelationship($relName);
        if (!$rel instanceof HasOne) {
            throw new InvalidArgumentException(sprintf('Relation "%s" on "%s" is not HasOne.', $relName, $ownerModelClass));
        }

        if (!$ownerAttrs->hasForeignKey($relName)) {
            throw new InvalidArgumentException(sprintf(
                'syncHasOne("%s") requires that "%s::$%s" is also marked as #[ForeignKey] so the owner FK column is known. Use replaceHasOne() instead.',
                $relName,
                $ownerModelClass,
                $relName
            ));
        }

        $fkDb = $rel->getForeignKey();
        if ($fkDb === null) {
            // ModelMetadata tries to keep HasOne::foreignKey consistent with #[ForeignKey], so this is unexpected.
            throw new RuntimeException(sprintf('syncHasOne("%s"): foreignKey is not configured on relation.', $relName));
        }

        $relatedModel = $rel->getModelName();
        if ($relatedModel === null) {
            throw new InvalidArgumentException(sprintf('HasOne "%s" has no modelName configured.', $relName));
        }

        $ownerPk = $model->getPrimaryKeyValue();
        $ownerPkDb = $ownerAttrs->getPrimaryKeyDbName();
        $ownerTable = $ownerAttrs->getTableName();

        return $this->executor->transaction(function (QueryExecutorInterface $ex) use (
            $model,
            $related,
            $relatedModel,
            $fkDb,
            $ownerPk,
            $ownerPkDb,
            $ownerTable,
            $relName
        ): array {
            $relatedInserted = 0;
            $relatedUpdated = 0;
            $ownerUpdated = 0;
            $ownerDetached = 0;

            if ($related === null) {
                if ($ownerPk !== null) {
                    /** @var Update $u */
                    $u = Query::update($ownerTable)->set([$fkDb => null]);
                    $u->where($ownerPkDb, $ownerPk);
                    $ex->execute($u);
                    $ownerDetached = 1;
                }

                $model->setRelationship($relName, null);

                return [
                    'relatedInserted' => 0,
                    'relatedUpdated' => 0,
                    'ownerUpdated' => 0,
                    'ownerDetached' => $ownerDetached,
                ];
            }

            if (!is_a($related, $relatedModel)) {
                throw new InvalidArgumentException(sprintf(
                    'syncHasOne(): expected instance of "%s", got "%s".',
                    $relatedModel,
                    $related::class
                ));
            }

            // Save related first if needed, so it has PK.
            $actionBefore = $related->getPersistenceAction();
            $relatedPersister = new ModelPersister($ex, $relatedModel, ModelMetadata::for($relatedModel));
            $relatedPersister->save($related);

            if ($actionBefore === PersistenceAction::Insert) {
                $relatedInserted = 1;
            } elseif ($actionBefore === PersistenceAction::Update) {
                $relatedUpdated = 1;
            }

            $rid = $related->getPrimaryKeyValue();
            if ($rid === null) {
                throw new RuntimeException('syncHasOne(): related model has no primary key after save.');
            }

            // Ensure owner points to related. If owner is already persisted, write FK immediately.
            if ($ownerPk !== null) {
                /** @var Update $u */
                $u = Query::update($ownerTable)->set([$fkDb => $rid]);
                $u->where($ownerPkDb, $ownerPk);
                $ex->execute($u);
                $ownerUpdated = 1;
            }

            // Keep the in-memory owner consistent with the relation just saved.
            $model->setRelationship($relName, $related);

            return [
                'relatedInserted' => $relatedInserted,
                'relatedUpdated' => $relatedUpdated,
                'ownerUpdated' => $ownerUpdated,
                'ownerDetached' => 0,
            ];
        });
    }

    private function assignOwner(Model $child, ModelMetadata $metadata, string $foreignKey, Model $owner): void
    {
        $property = $metadata->mapDbNameToPropertyName($foreignKey);
        if ($property === null) {
            $child->setExtraData($foreignKey, $owner->getPrimaryKeyValue());
            return;
        }

        if ($metadata->hasRelationship($property)) {
            $child->setRelationship($property, $owner);
            return;
        }

        $child->setProperty($property, $owner->getPrimaryKeyValue());
    }

    /**
     * @param array<int, int|string|Model> $idsOrModels
     * @return array<int, int|string>
     */
    private function normalizeRelatedIds(array $idsOrModels, string $relatedModelClass): array
    {
        $out = [];
        foreach ($idsOrModels as $v) {
            if ($v instanceof Model) {
                if (!is_a($v, $relatedModelClass)) {
                    throw new InvalidArgumentException(sprintf(
                        'Expected instances of "%s" for relation, got "%s".',
                        $relatedModelClass,
                        $v::class
                    ));
                }

                $id = $v->getPrimaryKeyValue();
                if ($id === null) {
                    throw new InvalidArgumentException(sprintf('Related model "%s" has null primary key.', $v::class));
                }

                $out[] = $id;
                continue;
            }

            $out[] = $v;
        }

        return $out;
    }

    /**
     * Let the query builder quote identifiers and expand IN values for the active dialect.
     *
     * @param array<int, string|int> $values
     */
    private function whereInValues(WhereClause $q, string $column, array $values): void
    {
        $q->where($column, array_values($values), \WilyCode\Database\Query\Enum\ComparisonOperator::IN);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     * @throws \JsonException
     */
    private function normalizeWriteValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value) || $value instanceof \stdClass) {
                $values[$key] = json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            }
        }

        return $values;
    }
}
