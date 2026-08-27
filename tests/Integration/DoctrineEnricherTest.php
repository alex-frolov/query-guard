<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\Doctrine\DoctrineAdapter;
use QueryGuard\Adapter\Doctrine\DoctrineEnricher;
use QueryGuard\Adapter\Doctrine\Middleware;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\Trace;
use QueryGuard\QueryGuard;
use QueryGuard\Rule\NPlusOneRule;
use QueryGuard\Test\Integration\Fixture\Customer;
use QueryGuard\Test\Integration\Fixture\Order;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * Real Doctrine ORM over sqlite: the only way to see that evidence of lazy loading is
 * genuinely taken from the stack rather than from our idea of what the stack looks like.
 */
#[CoversClass(DoctrineEnricher::class)]
final class DoctrineEnricherTest extends TestCase
{
    private EntityManager $em;

    private DefaultQueryCollector $collector;

    protected function setUp(): void
    {
        DoctrineAdapter::reset();

        $configuration = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/Fixture'], true);
        $configuration->setMiddlewares([new Middleware()]);

        // Symfony 8 dropped the LazyGhost generator, so PHP 8.4+ native lazy objects are
        // the only available mode; ORM 4 will make it mandatory
        if (method_exists($configuration, 'enableNativeLazyObjects') && \PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );

        $this->em = new EntityManager($connection, $configuration);

        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(Customer::class),
            $this->em->getClassMetadata(Order::class),
        ]);

        $this->collector = new DefaultQueryCollector();
        QueryGuard::activate($this->collector);
    }

    protected function tearDown(): void
    {
        QueryGuard::deactivate();
        DoctrineAdapter::reset();
    }

    public function testLazyCollectionIsRecognisedWithEntityAndAssociation(): void
    {
        $this->seed();
        $trace = $this->openTrace();

        foreach ($this->em->getRepository(Customer::class)->findAll() as $customer) {
            $customer->orders->count();
        }

        $lazy = $this->annotatedEvents($trace);

        self::assertNotSame([], $lazy);
        self::assertSame(DoctrineEnricher::KIND_COLLECTION, $lazy[0]->annotation(DoctrineEnricher::KIND));
        self::assertSame(Customer::class, $lazy[0]->annotation(DoctrineEnricher::ENTITY));
        self::assertSame('orders', $lazy[0]->annotation(DoctrineEnricher::ASSOCIATION));
    }

    public function testLazyEntityIsRecognised(): void
    {
        $this->seed();
        $trace = $this->openTrace();

        foreach ($this->em->getRepository(Order::class)->findAll() as $order) {
            $order->customer?->name;
        }

        $lazy = $this->annotatedEvents($trace);

        self::assertNotSame([], $lazy);
        self::assertSame(DoctrineEnricher::KIND_ENTITY, $lazy[0]->annotation(DoctrineEnricher::KIND));
        self::assertSame(Customer::class, $lazy[0]->annotation(DoctrineEnricher::ENTITY));
    }

    /**
     * The whole point of enrichment: the finding names the association.
     */
    public function testRuleNamesTheAssociation(): void
    {
        $this->seed();
        $trace = $this->openTrace();

        foreach ($this->em->getRepository(Customer::class)->findAll() as $customer) {
            $customer->orders->count();
        }

        $findings = array_values(iterator_to_array(
            (new NPlusOneRule(CallsiteResolver::default()))->check($trace),
        ));

        self::assertCount(1, $findings);
        self::assertStringContainsString(Customer::class.'::$orders — lazy-loaded association', $findings[0]->message);
    }

    /**
     * An ordinary query is not lazy loading and must not be marked as such.
     */
    public function testPlainQueryIsNotAnnotated(): void
    {
        $this->seed();
        $trace = $this->openTrace();

        $this->em->getRepository(Customer::class)->findAll();

        foreach ($trace->events() as $event) {
            self::assertNull($event->annotation(DoctrineEnricher::KIND), $event->sql);
        }
    }

    /**
     * No entity references may survive in the trace: it lives until the end of the test.
     */
    public function testStackFramesCarryNoObjects(): void
    {
        $this->seed();
        $trace = $this->openTrace();

        foreach ($this->em->getRepository(Customer::class)->findAll() as $customer) {
            $customer->orders->count();
        }

        foreach ($trace->events() as $event) {
            foreach ($event->stack as $frame) {
                self::assertArrayNotHasKey('object', $frame);
            }
        }
    }

    private function seed(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $customer = new Customer('customer '.$i);
            $this->em->persist($customer);

            $order = new Order('order '.$i);
            $order->customer = $customer;
            $this->em->persist($order);
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function openTrace(): Trace
    {
        $this->collector->beginFixtures();

        return $this->collector->beginTrace(
            new TestIdentifier('id', 'DoctrineEnricherTest::test'),
            TestOptions::none(),
        );
    }

    /**
     * @return list<\QueryGuard\Query\QueryEvent>
     */
    private function annotatedEvents(Trace $trace): array
    {
        return array_values(array_filter(
            $trace->events(),
            static fn ($event): bool => null !== $event->annotation(DoctrineEnricher::KIND),
        ));
    }
}
