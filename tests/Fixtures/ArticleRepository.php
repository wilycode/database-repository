<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Fixtures;

use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\QueryExecutor\QueryExecutor;
use WilyCode\Database\Repository\Repository;

final class ArticleRepository extends Repository
{
    public function __construct(QueryExecutorInterface $executor)
    {
        parent::__construct($executor, Article::class);
    }
}
