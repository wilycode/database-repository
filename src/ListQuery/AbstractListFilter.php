<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\ListQuery;

use Psr\Http\Message\ServerRequestInterface;
use WilyCode\Database\Model\Model;
use WilyCode\Database\Repository\Repository;

/**
 * Base class for Spatie-like list filters.
 *
 * - Keeps allowlists and custom logic out of the repository.
 * - Stays testable (parsing core uses arrays).
 */
abstract class AbstractListFilter implements ListQueryInterface
{
    protected ListQueryRequest $request;
    protected bool $strict;

    /** @var null|array{page: int, pageCount: int, perPage: int, total: int, from: int, to: int} */
    private ?array $pagination = null;

    final protected function __construct(ListQueryRequest $request, bool $strict = true)
    {
        $this->request = $request;
        $this->strict = $strict;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, bool $strict = true): static
    {
        return new static(ListQueryRequest::fromQuery($query), $strict);
    }

    public static function fromRequest(ServerRequestInterface $request, bool $strict = true): static
    {
        return static::fromQuery($request->getQueryParams(), $strict);
    }

    public function page(): int
    {
        return $this->request->page;
    }

    public function perPage(): int
    {
        return $this->request->perPage;
    }

    /**
     * @return array{page: int, pageCount: int, perPage: int, total: int, from: int, to: int}
     */
    public function getPagination(): array
    {
        if ($this->pagination === null) {
            throw new \LogicException('Pagination has not been resolved. Call withPagination() first.');
        }

        return $this->pagination;
    }

    public function withPagination(int $total): static
    {
        if ($total < 0) {
            throw new \InvalidArgumentException('Pagination total cannot be negative.');
        }

        $perPage = $this->perPage();
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min($this->page(), $pageCount);
        $offset = ($page - 1) * $perPage;

        $pagination = [
            'page' => $page,
            'pageCount' => $pageCount,
            'perPage' => $perPage,
            'total' => $total,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => $total === 0 ? 0 : min($offset + $perPage, $total),
        ];

        $clone = clone $this;
        $clone->request = new ListQueryRequest(
            page: $pagination['page'],
            perPage: $this->perPage(),
            filters: $this->request->filters,
            sort: $this->request->sort,
            includes: $this->request->includes,
        );
        $clone->pagination = $pagination;

        return $clone;
    }

    /**
     * @template T of Model
     * @param Repository<T> $repo
     * @return Repository<T>
     */
    public function applyTo(Repository $repo): Repository
    {
        $filters = $this->indexFilters($this->allowedFilters());

        $sorts = [];
        foreach ($this->allowedSorts() as $sort) {
            if (isset($sorts[$sort->name])) {
                throw new \LogicException('Duplicate allowed sort: ' . $sort->name);
            }
            $sorts[$sort->name] = $sort;
        }

        $includes = $this->indexIncludes($this->allowedIncludes());

        foreach ($this->request->filters as $name => $value) {
            if (!array_key_exists((string)$name, $filters)) {
                if ($this->strict) {
                    throw new \InvalidArgumentException('Filter is not allowed: ' . $name);
                }
                continue;
            }
            /** @var AllowedFilter $f */
            $f = $filters[(string)$name];
            $repo = $f->apply($repo, $value);
        }

        $sortTokens = $this->request->sort !== []
            ? $this->request->sort
            : $this->defaultSorts();

        foreach ($sortTokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $desc = str_starts_with($token, '-');
            $name = ltrim($token, '-');
            if ($name === '') {
                continue;
            }

            if (!array_key_exists($name, $sorts)) {
                if ($this->strict) {
                    throw new \InvalidArgumentException('Sort is not allowed: ' . $name);
                }
                continue;
            }

            /** @var AllowedSort $s */
            $s = $sorts[$name];
            $repo = $s->apply($repo, $desc);
        }

        foreach ($this->request->includes as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            [$name, $cols] = $this->parseIncludeToken($token);

            $matched = $includes[$name] ?? null;
            if ($matched === null) {
                foreach ($includes as $include) {
                    if ($include->matches($name)) {
                        $matched = $include;
                        break;
                    }
                }
            }

            if ($matched === null) {
                if ($this->strict) {
                    throw new \InvalidArgumentException('Include is not allowed: ' . $name);
                }
                continue;
            }

            $repo = $matched->apply($repo, $name, $cols);
        }

        return $repo;
    }

    /** @return list<AllowedFilter> */
    abstract protected function allowedFilters(): array;

    /** @return list<AllowedSort> */
    abstract protected function allowedSorts(): array;

    /** @return list<string> */
    protected function defaultSorts(): array
    {
        return [];
    }

    /** @return list<AllowedInclude> */
    abstract protected function allowedIncludes(): array;

    /**
     * @param list<AllowedFilter> $items
     * @return array<string, AllowedFilter>
     */
    private function indexFilters(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            if (isset($indexed[$item->name])) {
                throw new \LogicException('Duplicate allowed filter: ' . $item->name);
            }
            $indexed[$item->name] = $item;
        }

        return $indexed;
    }

    /**
     * @param list<AllowedInclude> $items
     * @return array<string, AllowedInclude>
     */
    private function indexIncludes(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            if (isset($indexed[$item->name])) {
                throw new \LogicException('Duplicate allowed include: ' . $item->name);
            }
            $indexed[$item->name] = $item;
        }

        return $indexed;
    }

    /**
     * Parses include tokens in the form:
     * - relation
     * - relation:col1|col2
     *
     * @return array{0:string,1:array<int,string>|string|null}
     */
    private function parseIncludeToken(string $token): array
    {
        $name = $token;
        $cols = null;

        // allow include=profile:id|name
        if (str_contains($token, ':')) {
            [$name, $raw] = explode(':', $token, 2);
            $name = trim($name);
            $raw = trim($raw);
            if ($raw !== '') {
                $cols = array_values(array_filter(array_map('trim', explode('|', $raw))));
            }
        }

        return [$name, $cols];
    }
}
