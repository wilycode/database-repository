<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Exception;

use RuntimeException;

/**
 * Thrown when a repository lookup was expected to return a model but returned none.
 */
final class ModelNotFoundException extends RuntimeException
{
    /**
     * @param class-string $modelClass
     * @param array<string, scalar|null> $criteria
     */
    public static function forModel(string $modelClass, ?string $message = null, array $criteria = []): self
    {
        if ($message !== null && $message !== '') {
            return new self($message);
        }

        $base = sprintf('Model not found: %s', $modelClass);

        if ($criteria === []) {
            return new self($base);
        }

        $pairs = [];
        foreach ($criteria as $k => $v) {
            $pairs[] = sprintf('%s=%s', (string)$k, self::stringify($v));
        }

        return new self($base . ' (' . implode(', ', $pairs) . ')');
    }

    private static function stringify(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        $s = (string)$v;
        $s = mb_substr($s, 0, 80);
        return '"' . $s . '"';
    }
}
