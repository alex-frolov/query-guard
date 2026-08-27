<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Doctrine;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\Doctrine\Middleware;

/**
 * The whole chain inside a real PHPUnit run: DBAL middleware → collector → trace
 * boundaries → rule → summary. Nothing faked except sqlite standing in for MySQL.
 */
final class DoctrineTracedTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);

        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );

        // fixtures: 1 DDL plus 5 inserts. They must not reach the trace
        $this->connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        for ($id = 1; $id <= 5; $id++) {
            $this->connection->executeStatement('INSERT INTO users (id, name) VALUES (?, ?)', [$id, 'user ' . $id]);
        }
    }

    public function testNPlusOneShape(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            $this->connection->fetchAssociative('SELECT name FROM users WHERE id = ?', [$id]);
        }

        self::assertTrue(true);
    }
}
