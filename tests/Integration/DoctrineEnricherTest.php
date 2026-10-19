<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\Doctrine\Dbal;
use QueryGuard\Adapter\Doctrine\DoctrineAdapter;
use QueryGuard\Adapter\Doctrine\DoctrineEnricher;
use QueryGuard\Adapter\Doctrine\Middleware;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\Trace;
use QueryGuard\QueryGuard;
use QueryGuard\Rule\NPlusOneRule;
use QueryGuard\Rule\QueryInLoopRule;
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
            // touching the association is what triggers the lazy load under study
            self::assertNotSame('', (string) $order->customer?->name);
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
     * Nothing from the stack may survive in the trace: it lives until the end of the
     * test, and 200 frames per query cost ~106 MB per 1000 queries. The callsite is
     * resolved while recording and is the only thing kept.
     */
    public function testTheTraceKeepsNoStackAtAll(): void
    {
        $this->seed();
        $trace = $this->openTrace();

        foreach ($this->em->getRepository(Customer::class)->findAll() as $customer) {
            $customer->orders->count();
        }

        self::assertNotEmpty($trace->events());

        $resolver = CallsiteResolver::default();
        $resolved = 0;

        foreach ($trace->events() as $event) {
            self::assertSame([], $event->stack, $event->sql);

            if (null !== $event->callsite($resolver)) {
                ++$resolved;
            }
        }

        // the point of resolving early is that the callsite still arrives
        self::assertGreaterThan(0, $resolved);
    }

    /**
     * One `flush()` writes every pending change from one line: here twelve INSERTs of two
     * shapes. Counted query by query that was a `query-in-loop` finding; it is one unit
     * of work, and every statement of it carries the same commit.
     */
    public function testOneFlushIsOneUnitOfWork(): void
    {
        $trace = $this->openTrace();

        for ($i = 1; $i <= 6; ++$i) {
            $customer = new Customer('customer '.$i);
            $order = new Order('order '.$i);
            $order->customer = $customer;
            $this->em->persist($customer);
            $this->em->persist($order);
        }

        $this->em->flush();

        $commits = [];

        foreach ($trace->events() as $event) {
            $commit = $event->annotation(DoctrineEnricher::COMMIT);
            self::assertIsInt($commit, $event->sql);
            $commits[$commit] = true;
        }

        self::assertCount(12, $trace->events());
        self::assertCount(1, $commits);
        self::assertSame([], iterator_to_array((new QueryInLoopRule(CallsiteResolver::default()))->check($trace), false));
    }

    /**
     * The other half: a `flush()` inside a loop is a loop, and each pass is its own commit.
     */
    public function testFlushInsideALoopIsStillReported(): void
    {
        $trace = $this->openTrace();

        for ($i = 1; $i <= 6; ++$i) {
            $customer = new Customer('customer '.$i);
            $order = new Order('order '.$i);
            $order->customer = $customer;
            $this->em->persist($customer);
            $this->em->persist($order);
            $this->em->flush();
        }

        $findings = iterator_to_array((new QueryInLoopRule(CallsiteResolver::default()))->check($trace), false);

        self::assertCount(1, $findings);
        self::assertSame('12 queries of 2 different shapes from one place, in 6 units of work (a Doctrine flush counts as one)', $findings[0]->message);
    }

    /**
     * Under a test transaction that is rolled back afterwards every flush is nested, and
     * DBAL nests it as a savepoint. Those statements belong to the harness — production
     * opens a plain transaction that never shows up here — so they stay out of the trace,
     * while each of them still starts a new commit.
     */
    public function testSavepointsOfANestedTransactionAreLeftOutOfTheTrace(): void
    {
        // DBAL 4 always nests with savepoints; DBAL 3 does so only when asked, which is
        // what `doctrine.dbal.use_savepoints: true` does in a Symfony project
        if (!Dbal::isVersion4()) {
            $this->em->getConnection()->setNestTransactionsWithSavepoints(true);
        }

        $this->em->getConnection()->beginTransaction();
        $trace = $this->openTrace();

        for ($i = 1; $i <= 6; ++$i) {
            $customer = new Customer('customer '.$i);
            $order = new Order('order '.$i);
            $order->customer = $customer;
            $this->em->persist($customer);
            $this->em->persist($order);
            $this->em->flush();
        }

        $this->em->getConnection()->rollBack();

        foreach ($trace->events() as $event) {
            self::assertStringNotContainsStringIgnoringCase('savepoint', $event->sql);
        }

        $findings = iterator_to_array((new QueryInLoopRule(CallsiteResolver::default()))->check($trace), false);

        self::assertCount(12, $trace->events());
        self::assertSame('12 queries of 2 different shapes from one place, in 6 units of work (a Doctrine flush counts as one)', $findings[0]->message);
    }

    /**
     * Without savepoints a nested flush starts nothing the driver sees, so it cannot be
     * told from the flush before it. The loop then goes unreported — and the summary says so.
     */
    public function testFlushesNestedWithoutSavepointsAreAnnounced(): void
    {
        if (Dbal::isVersion4()) {
            self::markTestSkipped('DBAL 4 always nests with savepoints');
        }

        // DBAL 3's default — setting it explicitly is itself deprecated
        self::assertFalse($this->em->getConnection()->getNestTransactionsWithSavepoints());

        $this->flushInALoopInsideATransaction();

        self::assertStringContainsString(
            'nests transactions without savepoints',
            implode("\n", (new DoctrineAdapter())->notices()),
        );
    }

    public function testFlushesNestedWithSavepointsAreNotAnnounced(): void
    {
        if (!Dbal::isVersion4()) {
            $this->em->getConnection()->setNestTransactionsWithSavepoints(true);
        }

        $this->flushInALoopInsideATransaction();

        self::assertSame([], (new DoctrineAdapter())->notices());
    }

    private function flushInALoopInsideATransaction(): void
    {
        $this->em->getConnection()->beginTransaction();
        $this->openTrace();

        for ($i = 1; $i <= 3; ++$i) {
            $this->em->persist(new Customer('customer '.$i));
            $this->em->flush();
        }

        $this->em->getConnection()->rollBack();
    }

    public function testAReadIsNotPartOfACommit(): void
    {
        $this->seed();
        $trace = $this->openTrace();

        $this->em->getRepository(Customer::class)->findAll();

        foreach ($trace->events() as $event) {
            self::assertNull($event->annotation(DoctrineEnricher::COMMIT), $event->sql);
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
