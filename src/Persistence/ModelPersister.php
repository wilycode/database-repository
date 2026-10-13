<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Persistence;

use InvalidArgumentException;
use WilyCode\Database\Model\Model;
use WilyCode\Database\Model\ModelMetadata;
use WilyCode\Database\Model\PersistenceAction;
use WilyCode\Database\Query\Query;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;

/**
 * Persists a single model class while keeping Repository focused on query state.
 *
 * @internal
 * @template T of Model
 */
final class ModelPersister
{
    /** @param class-string<T> $modelClass */
    public function __construct(
        private readonly QueryExecutorInterface $executor,
        private readonly string $modelClass,
        private readonly ModelMetadata $metadata,
    ) {
    }

    /**
     * @param T $model
     * @return T
     */
    public function create(Model $model): Model
    {
        $this->assertModelClass($model);

        if ($model->isPersisted()) {
            throw new InvalidArgumentException('Cannot createModel() for a persisted model. Use updateModel() or saveModel().');
        }

        $values = $this->normalizeWriteValues($model->getInsertDbValues());
        $query = Query::insert($this->metadata->getTableName())->values($values);
        $this->executor->execute($query);
        $insertId = (string) $this->executor->getLastInsertId();

        if ($model->getPrimaryKeyValue() === null && $insertId !== '' && $insertId !== '0') {
            $model->setPrimaryKeyValue(ctype_digit($insertId) ? (int) $insertId : $insertId);
        }

        $model->markHydrated();

        return $model;
    }

    /**
     * @param T $model
     * @return T
     */
    public function update(Model $model): Model
    {
        $this->assertModelClass($model);

        $primaryKey = $model->getPrimaryKeyValue();
        if ($primaryKey === null) {
            throw new InvalidArgumentException('Cannot updateModel() when primary key is null.');
        }

        $values = $this->normalizeWriteValues($model->getUpdateDbValues(onlyDirty: true));
        if ($values === []) {
            $model->markHydrated();
            return $model;
        }

        $query = Query::update($this->metadata->getTableName())
            ->set($values)
            ->where($this->metadata->getPrimaryKeyDbName(), $primaryKey);

        $this->executor->execute($query);
        $model->markHydrated();

        return $model;
    }

    /** @param T $model */
    public function delete(Model $model): int
    {
        $this->assertModelClass($model);

        $primaryKey = $model->getPrimaryKeyValue();
        if ($primaryKey === null) {
            throw new InvalidArgumentException('Cannot deleteModel() when primary key is null.');
        }

        $query = Query::delete($this->metadata->getTableName())
            ->where($this->metadata->getPrimaryKeyDbName(), $primaryKey);

        $affectedRows = $this->executor->execute($query);
        $model->markPersisted(false);

        return $affectedRows;
    }

    /**
     * @param T $model
     * @return T
     */
    public function save(Model $model): Model
    {
        $this->assertModelClass($model);

        return match ($model->getPersistenceAction()) {
            PersistenceAction::Insert => $this->create($model),
            PersistenceAction::Update => $this->update($model),
            PersistenceAction::None => $model,
        };
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

    /** @param T $model */
    private function assertModelClass(Model $model): void
    {
        if (!is_a($model, $this->modelClass)) {
            throw new InvalidArgumentException(sprintf(
                'Repository expects model of class "%s", got "%s".',
                $this->modelClass,
                $model::class,
            ));
        }
    }
}
