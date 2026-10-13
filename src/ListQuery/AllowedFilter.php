<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\ListQuery;

use Closure;
use WilyCode\Database\Model\Model;
use WilyCode\Database\Repository\Repository;

/**
 * Defines a single allowed filter.
 */
final readonly class AllowedFilter
{
    public string $name;

    private Closure $apply;

    private function __construct(string $name, Closure $apply)
    {
        $this->name = $name;
        $this->apply = $apply;
    }

    /**
     * Exact match. When value is an array, uses WHERE IN.
     */
    public static function exact(string $name, ?string $column = null): self
    {
        $col = $column ?? $name;

        return new self($name, static function (Repository $repo, mixed $value) use ($col): Repository {
            if (is_array($value)) {
                return $repo->whereIn($col, array_values($value));
            }
            return $repo->where($col, $value);
        });
    }

    /**
     * Partial match using LIKE %value%.
     */
    public static function partial(string $name, ?string $column = null): self
    {
        $col = $column ?? $name;

        return new self($name, static function (Repository $repo, mixed $value) use ($col): Repository {
            if ($value === null) {
                return $repo;
            }
            $str = (string)$value;
            if ($str === '') {
                return $repo;
            }
            return $repo->whereLike($col, $str);
        });
    }

    /**
     * Boolean filter.
     *
     * Accepts common "bool-like" values: true/false, 1/0, yes/no, on/off.
     * When $nullCheck is true, maps to IS NULL / IS NOT NULL checks on the column.
     */
    public static function bool(string $name, ?string $column = null, bool $nullCheck = false): self
    {
        $col = $column ?? $name;

        return new self($name, static function (Repository $repo, mixed $value) use ($col, $nullCheck): Repository {
            $b = self::parseBoolLike($value);
            if ($b === null) {
                return $repo;
            }

            if ($nullCheck) {
                return $repo->whereRaw($b ? ($col . ' IS NOT NULL') : ($col . ' IS NULL'));
            }

            return $repo->where($col, $b ? 1 : 0);
        });
    }

    /**
     * Date range filter.
     *
     * Supports:
     * - filter[createdAt][from]=YYYY-MM-DD and/or filter[createdAt][to]=YYYY-MM-DD
     * - filter[createdAt]=from,to
     */
    public static function dateRange(string $name, ?string $column = null, string $fromKey = 'from', string $toKey = 'to'): self
    {
        $col = $column ?? $name;

        return new self($name, static function (Repository $repo, mixed $value) use ($col, $fromKey, $toKey): Repository {
            [$from, $to] = self::extractRange($value, $fromKey, $toKey);

            $from = self::normalizeDateScalar($from);
            $to = self::normalizeDateScalar($to);

            if ($from === null && $to === null) {
                return $repo;
            }

            if ($from !== null && $to !== null) {
                return $repo->whereRaw($col . ' BETWEEN ? AND ?', [$from, $to]);
            }
            if ($from !== null) {
                return $repo->whereRaw($col . ' >= ?', [$from]);
            }
            return $repo->whereRaw($col . ' <= ?', [$to]);
        });
    }

    /**
     * Custom filter callback.
     *
     * Callable signature: fn(Repository $repo, mixed $value): Repository
     */
    public static function callback(string $name, callable $callback): self
    {
        $apply = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
        return new self($name, $apply);
    }

    /**
     * Filter by an allowed set of values (allowlist).
     *
     * Supports both single and multi values:
     * - filter[role]=admin
     * - filter[role][]=worker&filter[role][]=admin
     *
     * By default, throws on invalid values. If you set $throwOnInvalid=false,
     * invalid values are ignored (filtered out) and the query is applied only
     * for the remaining allowed values.
     *
     * @param array<int, string|int> $allowed
     */
    public static function oneOf(
        string $name,
        array $allowed,
        ?string $column = null,
        bool $throwOnInvalid = true
    ): self {
        $col = $column ?? $name;

        // Normalize allowlist to strings.
        $allowed = array_values(array_unique(array_map(static fn($v) => (string)$v, $allowed)));

        return new self($name, static function (Repository $repo, mixed $value) use ($col, $allowed, $throwOnInvalid): Repository {
            // Normalize to list of trimmed strings.
            $values = is_array($value) ? $value : [$value];
            $values = array_values(array_unique(array_filter(array_map(
                static fn($v) => trim((string)$v),
                $values
            ), static fn(string $v) => $v !== '')));

            if ($values === []) {
                return $repo;
            }

            $invalid = array_values(array_diff($values, $allowed));
            if ($invalid !== [] && $throwOnInvalid) {
                throw new \InvalidArgumentException(
                    'Invalid value(s) for filter "' . $col . '": ' . implode(', ', $invalid)
                );
            }

            $values = array_values(array_intersect($values, $allowed));
            if ($values === []) {
                return $repo;
            }

            if (count($values) === 1) {
                return $repo->where($col, $values[0]);
            }

            return $repo->whereIn($col, $values);
        });
    }

    /**
     * @template T of Model
     * @param Repository<T> $repo
     * @return Repository<T>
     */
    public function apply(Repository $repo, mixed $value): Repository
    {
        /** @var Repository<T> $result */
        $result = ($this->apply)($repo, $value);
        return $result;
    }

    private static function parseBoolLike(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }
            if ($value === 0) {
                return false;
            }
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '') {
                return null;
            }

            if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }

        throw new \InvalidArgumentException('Invalid boolean value for filter.');
    }

    /**
     * @return array{0:mixed,1:mixed}
     */
    private static function extractRange(mixed $value, string $fromKey, string $toKey): array
    {
        if (is_array($value)) {
            $from = $value[$fromKey] ?? null;
            $to = $value[$toKey] ?? null;
            return [$from, $to];
        }

        if (is_string($value)) {
            $v = trim($value);
            if ($v === '') {
                return [null, null];
            }

            if (str_contains($v, ',')) {
                [$from, $to] = array_pad(array_map('trim', explode(',', $v, 2)), 2, null);
                return [$from, $to];
            }

            // Single scalar: treat as "from" (>=)
            return [$v, null];
        }

        return [null, null];
    }

    private static function normalizeDateScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }

        // Accept YYYY-MM-DD and common datetime formats without timezone.
        $isDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        $isDateTime = (bool)preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $s);
        if (!$isDate && !$isDateTime) {
            throw new \InvalidArgumentException('Invalid date value for filter.');
        }

        return $s;
    }
}
