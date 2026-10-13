<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository;

/**
 * Internal eager-loading tree node.
 *
 * @internal
 */
final class WithNode
{
    /** @var array<string, WithNode> */
    public array $children = [];

    /**
     * @param array<int, string>|string $columns
     * @param array<string, scalar|list<scalar>|null> $conditions
     */
    public function __construct(
        public string $name,
        public array|string $columns = '*',
        public array $conditions = [],
    ) {}

    /**
     * @param array<int, string>|string $columns
     * @param array<string, scalar|list<scalar>|null> $conditions
     */
    public function touch(array|string $columns, array $conditions): void
    {
        // Prefer explicit columns over the default '*'.
        if ($this->columns === '*' && $columns !== '*') {
            $this->columns = $columns;
        }

        foreach ($conditions as $field => $value) {
            if (array_key_exists($field, $this->conditions) && $this->conditions[$field] !== $value) {
                throw new \InvalidArgumentException(sprintf(
                    'with(): conflicting conditions for relation field "%s".',
                    $field,
                ));
            }

            $this->conditions[$field] = $value;
        }
    }
}
