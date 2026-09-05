<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\Eloquent\EloquentExplainer;

#[CoversClass(EloquentExplainer::class)]
final class EloquentExplainerTest extends TestCase
{
    private Manager $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Manager();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher(new Container()));
        $this->capsule->bootEloquent();

        $this->capsule->getConnection()->statement('CREATE TABLE t (id INTEGER, name TEXT)');
        $this->capsule->getConnection()->insert('INSERT INTO t (id, name) VALUES (1, ?)', ['Ada']);
    }

    public function testPlatformMatchesTheConnectionDriver(): void
    {
        $explainer = new EloquentExplainer(fn () => $this->capsule->getConnection());

        self::assertSame('sqlite', $explainer->platform());
    }

    /**
     * Eloquent sets `PDO::FETCH_OBJ` on every prepared statement, EXPLAIN included.
     * `Explainer::run()` promises arrays keyed by column name — a caller doing
     * `reset($row)` to grab the first column, the way `PlanProvider` does, would
     * silently get nothing back from a `stdClass`.
     */
    public function testRowsComeBackAsArraysNotStdClass(): void
    {
        $explainer = new EloquentExplainer(fn () => $this->capsule->getConnection());

        $rows = $explainer->run('SELECT id, name FROM t WHERE id = ?', [1]);

        self::assertSame([['id' => 1, 'name' => 'Ada']], $rows);
    }

    /**
     * The whole reason this takes a resolver instead of a `Connection`: see the class
     * docblock. A closure that starts pointing at one connection and is later repointed
     * at another must be followed, not frozen at construction time.
     */
    public function testResolvesTheConnectionAtCallTimeNotConstructionTime(): void
    {
        $second = new Manager();
        $second->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $second->setEventDispatcher(new Dispatcher(new Container()));
        $second->bootEloquent();
        $second->getConnection()->statement('CREATE TABLE u (id INTEGER)');
        $second->getConnection()->insert('INSERT INTO u (id) VALUES (7)');

        $current = $this->capsule->getConnection();
        $explainer = new EloquentExplainer(static function () use (&$current) {
            return $current;
        });

        // repoint after construction — "u" only exists on the second connection, so a
        // frozen reference to the first would fail this query outright
        $current = $second->getConnection();

        self::assertSame([['id' => 7]], $explainer->run('SELECT id FROM u'));
    }
}
