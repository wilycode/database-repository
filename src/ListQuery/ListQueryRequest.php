<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\ListQuery;

use InvalidArgumentException;

/** Normalized list-query input. */
final readonly class ListQueryRequest
{
    /**
     * @param array<string, mixed> $filters
     * @param list<string> $sort
     * @param list<string> $includes
     */
    public function __construct(
        public int $page,
        public int $perPage,
        public array $filters = [],
        public array $sort = [],
        public array $includes = [],
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Page must be at least 1.');
        }
        if ($perPage < 1) {
            throw new InvalidArgumentException('perPage must be at least 1.');
        }
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(
        array $query,
        int $defaultPerPage = 20,
        int $maxPerPage = 200,
    ): self {
        if ($defaultPerPage < 1 || $maxPerPage < 1 || $defaultPerPage > $maxPerPage) {
            throw new InvalidArgumentException('Invalid pagination defaults.');
        }

        $page = self::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = self::positiveInteger(
            $query['perPage'] ?? null,
            'perPage',
            $defaultPerPage,
        );

        if ($perPage > $maxPerPage) {
            throw new InvalidArgumentException(sprintf(
                'perPage cannot exceed %d.',
                $maxPerPage,
            ));
        }

        $filters = $query['filter'] ?? [];
        if (!is_array($filters)) {
            throw new InvalidArgumentException('filter must be an array.');
        }

        return new self(
            page: $page,
            perPage: $perPage,
            filters: $filters,
            sort: self::csv($query['sort'] ?? null, 'sort'),
            includes: self::csv($query['include'] ?? null, 'include'),
        );
    }

    private static function positiveInteger(
        mixed $value,
        string $name,
        int $default,
    ): int {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
        } else {
            throw new InvalidArgumentException($name . ' must be a positive integer.');
        }

        if ($parsed < 1) {
            throw new InvalidArgumentException($name . ' must be at least 1.');
        }

        return $parsed;
    }

    /** @return list<string> */
    private static function csv(mixed $value, string $name): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($name . ' must be a comma-separated string.');
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
