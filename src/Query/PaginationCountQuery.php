<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Query;

use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Dialect\DialectInterface;
use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\Query\Statement\Select;

/** @internal Defer compilation until the executor supplies the connection dialect. */
final readonly class PaginationCountQuery implements QueryInterface
{
    public function __construct(private Select $select)
    {
    }

    public function compile(?DialectInterface $dialect = null): CompiledQuery
    {
        return $this->select->forPaginationCount(countAlias: 'total', dialect: $dialect);
    }
}
