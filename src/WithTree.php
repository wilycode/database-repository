<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository;

/**
 * Internal representation of eager-load paths.
 *
 * @internal
 */
final class WithTree
{
    /** @var array<string, WithNode> */
    private array $roots = [];

    /**
     * @param array<int, string>|string $columns
     * @param array<string, scalar|list<scalar>|null> $conditions
     */
    public function add(string $path, array|string $columns = '*', array $conditions = []): void
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('with(): path cannot be empty.');
        }

        $segments = array_values(array_filter(explode('.', $path), static fn(string $s) => trim($s) !== ''));
        if ($segments === []) {
            throw new \InvalidArgumentException('with(): invalid path: ' . $path);
        }

        $current = &$this->roots;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $i => $seg) {
            if (!isset($current[$seg])) {
                $current[$seg] = new WithNode(
                    $seg,
                    $i === $lastIndex ? $columns : '*',
                    $i === $lastIndex ? $conditions : [],
                );
            } elseif ($i === $lastIndex) {
                $current[$seg]->touch($columns, $conditions);
            }

            $node = $current[$seg];
            $current = &$node->children;
        }
    }

    /** @return array<string, WithNode> */
    public function roots(): array
    {
        return $this->roots;
    }

    public function isEmpty(): bool
    {
        return $this->roots === [];
    }
}
