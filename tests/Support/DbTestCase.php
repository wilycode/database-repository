<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Support;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\DriverPdo\PdoBinder;
use WilyCode\Database\DriverPdo\PdoDatabase;
use WilyCode\Database\Query\Query;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\QueryExecutor\QueryExecutor;

abstract class DbTestCase extends TestCase
{
    protected static ?QueryExecutorInterface $executor = null;
    protected static ?string $skipReason = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$executor === null) {
            $dsn  = getenv('WILYCODE_TEST_DSN') ?: '';
            $user = getenv('WILYCODE_TEST_USER') ?: null;
            $pass = getenv('WILYCODE_TEST_PASS') ?: null;

            if ($dsn === '') {
                self::$skipReason = 'Set WILYCODE_TEST_DSN (and optionally WILYCODE_TEST_USER/WILYCODE_TEST_PASS) to run DB integration tests.';
            } else {
                $db = PdoDatabase::connect($dsn, $user ?: null, $pass ?: null);
                $binder = new PdoBinder();
                self::$executor = new QueryExecutor($db, $binder);
            }
        }

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    protected function execSql(string $sql): void
    {
        self::$executor?->execute(Query::raw($sql));
    }

    /** @return array<int, array<string, mixed>> */
    protected function fetchAll(string $sql): array
    {
        return self::$executor?->fetchAll(Query::raw($sql)) ?? [];
    }

    protected function fetchColumn(string $sql): mixed
    {
        return self::$executor?->fetchColumn(Query::raw($sql));
    }
}
