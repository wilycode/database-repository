<?php

declare(strict_types=1);

namespace WilyCode\Database\Repository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use WilyCode\Database\Model\ModelMetadata;
use WilyCode\Database\Repository\EagerLoading\EagerLoadCompiler;
use WilyCode\Database\Repository\Repository;
use WilyCode\Database\Repository\Tests\Fixtures\Read\Article;
use WilyCode\Database\Repository\Tests\Fixtures\Read\ArticleWithExplicitTagAlias;
use WilyCode\Database\Repository\Tests\Fixtures\Read\User;

require_once __DIR__ . '/../bootstrap.php';

final class RelationAliasResolutionTest extends TestCase
{
    public function test_with_uses_relationship_table_alias_from_model_attribute_manager(): void
    {
        $repo = $this->newRepoWithoutConstructor(Article::class);

        $repo = $repo->with('tags');

        $select = $this->invokeProtected($repo, 'buildSelect', [true]);
        $compiled = $select->compile();

        $sql = $this->compiledSql($compiled);

        // tags => alias "t" (derived from property name)
        $this->assertMatchesRegularExpression('/\bfrom\s+`?tag`?\s+(?:as\s+)?`?t`?\b/i', $sql);
        $this->assertMatchesRegularExpression('/\bjoin\s+`?article_tag`?\s+(?:as\s+)?`?p_t`?\b/i', $sql);
    }

    public function test_with_prefers_explicit_table_alias_on_relationship_attribute(): void
    {
        $repo = $this->newRepoWithoutConstructor(ArticleWithExplicitTagAlias::class);

        $repo = $repo->with('tags');

        $select = $this->invokeProtected($repo, 'buildSelect', [true]);
        $compiled = $select->compile();

        $sql = $this->compiledSql($compiled);

        // Explicit: tags => alias "x"
        $this->assertMatchesRegularExpression('/\bfrom\s+`?tag`?\s+(?:as\s+)?`?x`?\b/i', $sql);
        $this->assertMatchesRegularExpression('/\bjoin\s+`?article_tag`?\s+(?:as\s+)?`?p_x`?\b/i', $sql);
    }

    public function test_with_conditions_are_bound_and_use_the_relation_alias(): void
    {
        $repo = $this->newRepoWithoutConstructor(Article::class)
            ->with('tags', ['name'], ['name' => ['php', 'db']]);

        $select = $this->invokeProtected($repo, 'buildSelect', [true]);
        $compiled = $select->compile();
        $sql = $this->compiledSql($compiled);

        $this->assertMatchesRegularExpression('/\bt\.name\s+IN\s*\([^)]*:[a-zA-Z0-9_]+[^)]*\)/', $sql);
        $this->assertSame(['php', 'db'], array_values($compiled->params));
        $this->assertStringNotContainsString("'php'", $sql);
        $this->assertStringNotContainsString("'db'", $sql);
    }

    public function test_with_condition_accepts_a_persisted_relation_model(): void
    {
        $repo = $this->newRepoWithoutConstructor(Article::class)
            ->with('tags', ['name'], ['createdBy' => new User(['id' => 9])]);

        $select = $this->invokeProtected($repo, 'buildSelect', [true]);
        $compiled = $select->compile();
        $sql = $this->compiledSql($compiled);

        $this->assertMatchesRegularExpression('/\bt\.created_by_id\s*=\s*:[a-zA-Z0-9_]+/', $sql);
        $this->assertSame([9], array_values($compiled->params));
    }

    public function test_with_condition_rejects_a_model_without_an_identifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a model without a primary key');

        $this->newRepoWithoutConstructor(Article::class)
            ->with('tags', ['name'], ['createdBy' => new User()]);
    }

    public function test_with_rejects_an_unknown_relation_condition_field(): void
    {
        $repo = $this->newRepoWithoutConstructor(Article::class)
            ->with('tags', '*', ['missing' => 'value']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown condition field "missing"');

        $this->invokeProtected($repo, 'buildSelect', [true]);
    }

    /**
     * @param class-string $modelClass
     */
    private function newRepoWithoutConstructor(string $modelClass): Repository
    {
        $ref = new ReflectionClass(Repository::class);
        /** @var Repository $repo */
        $repo = $ref->newInstanceWithoutConstructor();

        $attr = ModelMetadata::for($modelClass);

        $this->setProtected($repo, 'modelClass', $modelClass);
        $this->setProtected($repo, 'metadata', $attr);
        $this->setProtected($repo, 'table', $attr->getTableName());
        $this->setProtected($repo, 'pkDb', $attr->getPrimaryKeyDbName());

        $this->setProtected($repo, 'tableAlias', $attr->getTableAlias());
        $this->setProtected($repo, 'eagerLoadCompiler', new EagerLoadCompiler());

        return $repo;
    }

    private function invokeProtected(object $obj, string $method, array $args = []): mixed
    {
        $m = new ReflectionMethod($obj, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    private function setProtected(object $obj, string $prop, mixed $value): void
    {
        $r = new ReflectionObject($obj);
        while (!$r->hasProperty($prop) && ($parent = $r->getParentClass())) {
            $r = $parent;
        }

        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    private function compiledSql(object $compiled): string
    {
        // Try common APIs first.
        foreach (['getSql', 'sql', 'toSql'] as $m) {
            if (method_exists($compiled, $m)) {
                $val = $compiled->{$m}();
                if (is_string($val) && $val !== '') {
                    return $val;
                }
            }
        }

        if (method_exists($compiled, '__toString')) {
            $val = (string)$compiled;
            if ($val !== '') {
                return $val;
            }
        }

        // Try common property name.
        if (property_exists($compiled, 'sql')) {
            $val = $compiled->sql;
            if (is_string($val) && $val !== '') {
                return $val;
            }
        }

        // Last resort: reflect properties and find a string that looks like SQL.
        $r = new ReflectionObject($compiled);
        foreach ($r->getProperties() as $p) {
            $p->setAccessible(true);
            $v = $p->getValue($compiled);
            if (is_string($v) && stripos($v, 'select') !== false) {
                return $v;
            }
        }

        $this->fail('Could not extract SQL from compiled query object of class: ' . $compiled::class);
    }
}
