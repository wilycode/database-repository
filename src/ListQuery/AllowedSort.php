<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\ListQuery;

use Closure;
use WilyCode\Database\Model\Model;
use WilyCode\Database\Repository\Repository;


/**
 * Defines a single allowed sort.
 */
final readonly class AllowedSort
{
    public string $name;

    private Closure $apply;

    private function __construct(string $name, Closure $apply)
    {
        $this->name = $name;
        $this->apply = $apply;
    }

    public static function field(string $name, ?string $column = null): self
    {
        $col = $column ?? $name;
        return new self($name, static function (Repository $repo, bool $desc) use ($col): Repository {
            return $repo->orderBy($col, $desc ? 'DESC' : 'ASC');
        });
    }

    /**
     * Custom sort callback.
     *
     * Callable signature: fn(Repository $repo, bool $desc): Repository
     */
    public static function callback(string $name, callable $callback): self
    {
        $apply = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
        return new self($name, $apply);
    }

    /**
     * @template T of Model
     * @param Repository<T> $repo
     * @return Repository<T>
     */
    public function apply(Repository $repo, bool $desc): Repository
    {
        /** @var Repository<T> $result */
        $result = ($this->apply)($repo, $desc);
        return $result;
    }
}
