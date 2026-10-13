<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository;

use InvalidArgumentException;
use WilyCode\Database\Model\Model;

/**
 * @template T of Model
 */
final readonly class PaginationResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
        if ($total < 0) {
            throw new InvalidArgumentException('Pagination total cannot be negative.');
        }
        if ($page < 1) {
            throw new InvalidArgumentException('Pagination page must be at least 1.');
        }
        if ($perPage < 1) {
            throw new InvalidArgumentException('Pagination perPage must be at least 1.');
        }
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
