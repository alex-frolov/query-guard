<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QueryGuard\Worker;

/**
 * Which ParaTest worker this process is, and where its report goes.
 */
#[CoversClass(Worker::class)]
final class WorkerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN'], $_ENV['UNIQUE_TEST_TOKEN'], $_SERVER['UNIQUE_TEST_TOKEN']);
    }

    public function testAnOrdinaryRunIsNotAWorker(): void
    {
        $worker = Worker::fromEnvironment();

        self::assertFalse($worker->isWorker());
        self::assertSame('', $worker->token);
    }

    public function testTheParaTestTokenIsPickedUpFromTheEnvironment(): void
    {
        $_SERVER['TEST_TOKEN'] = '7';

        $worker = Worker::fromEnvironment();

        self::assertTrue($worker->isWorker());
        self::assertSame('7', $worker->token);
    }

    /**
     * `TEST_TOKEN` is the readable one and comes first; `UNIQUE_TEST_TOKEN` is what is
     * left when a runner sets only that.
     */
    public function testTheUniqueTokenIsTheFallback(): void
    {
        $_SERVER['UNIQUE_TEST_TOKEN'] = 'ab12';

        self::assertSame('ab12', Worker::fromEnvironment()->token);
    }

    /**
     * An empty variable is not a token: ParaTest is not running, something else exported
     * the name.
     */
    public function testAnEmptyTokenIsNoToken(): void
    {
        $_SERVER['TEST_TOKEN'] = '  ';

        self::assertFalse(Worker::fromEnvironment()->isWorker());
    }

    #[DataProvider('paths')]
    public function testThePlaceholderResolvesTheSameWayInBothKindsOfRun(string $token, string $configured, string $expected): void
    {
        $_SERVER['TEST_TOKEN'] = $token;

        self::assertSame($expected, Worker::fromEnvironment()->inPath($configured));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function paths(): iterable
    {
        yield 'a worker takes the token' => ['3', 'var/query-guard-%token%.json', 'var/query-guard-3.json'];

        // the separator goes with it, or a sequential run writes "query-guard-.json"
        yield 'a plain run loses the placeholder and its separator' => ['', 'var/query-guard-%token%.json', 'var/query-guard.json'];
        yield 'an underscore counts as a separator too' => ['', 'var/report_%token%.json', 'var/report.json'];
        yield 'so does a dot' => ['', 'var/report.%token%.json', 'var/report.json'];
        yield 'a path without the placeholder is left alone' => ['3', 'var/query-guard.json', 'var/query-guard.json'];
        yield 'the placeholder can stand on its own' => ['3', 'var/%token%.json', 'var/3.json'];
    }
}
