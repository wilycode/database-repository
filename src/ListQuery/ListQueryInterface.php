<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\ListQuery;

use WilyCode\Database\Model\Model;
use WilyCode\Database\Repository\Repository;

/**
 * A composable, testable description of a list query (filters/sorts/includes + pagination).
 */
interface ListQueryInterface
{
    /**
     * @template T of Model
     * @param Repository<T> $repo
     * @return Repository<T>
     */
    public function applyTo(Repository $repo): Repository;

    public function page(): int;

    public function perPage(): int;

    public function withPagination(int $total): static;

    /**
     * @return array{page: int, pageCount: int, perPage: int, total: int, from: int, to: int}
     */
    public function getPagination(): array;
}
