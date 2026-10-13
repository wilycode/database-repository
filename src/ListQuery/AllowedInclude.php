<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\ListQuery;

use Closure;
use WilyCode\Database\Model\Model;
use WilyCode\Database\Repository\Repository;

/**
 * Defines a single allowed include.
 */
final readonly class AllowedInclude
{
    public string $name;

    private bool $tree;

    private Closure $apply;

    private function __construct(string $name, Closure $apply, bool $tree = false)
    {
        $this->name = $name;
        $this->apply = $apply;
        $this->tree = $tree;
    }

    /**
     * Simple include mapping to $repo->with().
     */
    public static function simple(string $name, ?string $path = null): self
    {
        $p = $path ?? $name;
        return new self($name, static function (Repository $repo, string $requestedName, array|string|null $columns) use ($p): Repository {
            if ($columns === null) {
                return $repo->with($p);
            }
            return $repo->with($p, $columns);
        });
    }

    /**
     * Tree include: allows nested includes starting with the given name.
     *
     * Example:
     * - AllowedInclude::tree('roles') allows: include=roles,roles.permissions
     * - AllowedInclude::tree('roles', path: 'userRoles') maps roles.permissions -> userRoles.permissions
     */
    public static function tree(string $name, ?string $path = null): self
    {
        $basePath = $path ?? $name;

        return new self(
            $name,
            static function (Repository $repo, string $requestedName, array|string|null $columns) use ($name, $basePath): Repository {
                $withPath = $basePath;
                if (str_starts_with($requestedName, $name . '.')) {
                    $suffix = substr($requestedName, strlen($name) + 1);
                    if ($suffix !== '') {
                        $withPath = $basePath . '.' . $suffix;
                    }
                }

                if ($columns === null) {
                    return $repo->with($withPath);
                }
                return $repo->with($withPath, $columns);
            },
            true
        );
    }

    /**
     * Custom include callback with the exact signature:
     * fn(Repository $repo, string $requestedName, array|string|null $columns): Repository
     */
    public static function callback(string $name, callable $callback): self
    {
        $apply = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return new self($name, $apply);
    }

    public function matches(string $includeName): bool
    {
        if ($this->tree) {
            return $includeName === $this->name || str_starts_with($includeName, $this->name . '.');
        }

        return $includeName === $this->name;
    }

    /**
     * @template T of Model
     * @param Repository<T> $repo
     * @param array<int, string>|string|null $columns
     * @return Repository<T>
     */
    public function apply(Repository $repo, string $requestedName, array|string|null $columns): Repository
    {
        /** @var Repository<T> $result */
        $result = ($this->apply)($repo, $requestedName, $columns);
        return $result;
    }
}
