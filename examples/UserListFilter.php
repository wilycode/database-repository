<?php

declare(strict_types=1);

namespace App\ListQuery;

use WilyCode\Database\Repository\Repository;
use WilyCode\Database\Repository\ListQuery\AbstractListFilter;
use WilyCode\Database\Repository\ListQuery\AllowedFilter;
use WilyCode\Database\Repository\ListQuery\AllowedInclude;
use WilyCode\Database\Repository\ListQuery\AllowedSort;

/**
 * Example Spatie-like list filter for a User repository.
 *
 * Supported query string examples:
 * - ?filter[name]=john&sort=-createdAt&include=profile,roles
 * - ?filter[role]=admin&filter[hasVoucher]=true
 * - ?filter[createdAt][from]=2026-01-01&filter[createdAt][to]=2026-01-31
 * - ?include=roles.permissions:id|name
 */
final class UserListFilter extends AbstractListFilter
{
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::partial('name'),
            AllowedFilter::exact('status'),
            AllowedFilter::dateRange('createdAt', 'created_at'),

            // Example boolean flag.
            AllowedFilter::bool('active', 'is_active'),

            // Custom: filter users by role (EXISTS example).
            AllowedFilter::callback('role', static function (Repository $repo, mixed $value): Repository {
                if ($value === null || (string)$value === '') {
                    return $repo;
                }

                $owner = $repo->getOuterRef();
                $pk = $repo->getPrimaryKeyDbName();

                // Adjust table/columns to your schema.
                return $repo->whereRaw(
                    "EXISTS (SELECT 1 FROM user_role ur WHERE ur.user_id = {$owner}.{$pk} AND ur.role = ?)",
                    [(string)$value]
                );
            }),

            // Custom: users that have at least one voucher.
            AllowedFilter::callback('hasVoucher', static function (Repository $repo, mixed $value): Repository {
                $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($bool !== true) {
                    return $repo;
                }

                $owner = $repo->getOuterRef();
                $pk = $repo->getPrimaryKeyDbName();

                // Adjust table/columns to your schema.
                return $repo->whereRaw(
                    "EXISTS (SELECT 1 FROM voucher_user vu WHERE vu.user_id = {$owner}.{$pk})"
                );
            }),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('name'),
            AllowedSort::field('createdAt', 'created_at'),
        ];
    }

    protected function defaultSorts(): array
    {
        return ['-createdAt', 'name'];
    }

    protected function allowedIncludes(): array
    {
        return [
            AllowedInclude::simple('profile'),
            // Allows nested includes: roles.permissions, roles.permissions.createdBy, etc.
            AllowedInclude::tree('roles'),
        ];
    }
}
