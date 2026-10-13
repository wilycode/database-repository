<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository;

/** @internal Shared naming convention for relation reads and writes. */
final class RelationForeignKey
{
    public static function fromOwnerTable(string $ownerTableName): string
    {
        $table = $ownerTableName;
        if (str_ends_with($table, 'ies')) {
            $table = substr($table, 0, -3) . 'y';
        } elseif (str_ends_with($table, 's') && !str_ends_with($table, 'ss')) {
            $table = substr($table, 0, -1);
        }

        return $table . '_id';
    }
}
