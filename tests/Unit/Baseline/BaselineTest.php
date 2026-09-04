<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Baseline\Baseline;
use QueryGuard\Finding\Finding;
use QueryGuard\Query\Callsite;
use QueryGuard\TestIdentifier;

#[CoversClass(Baseline::class)]
final class BaselineTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ('' !== $this->path && is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testMissingFileIsAnEmptyBaselineNotAnError(): void
    {
        self::assertSame(0, Baseline::fromFile('/no/such/baseline.json')->count());
    }

    public function testBrokenJsonIsAnEmptyBaseline(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'qg') ?: '';
        file_put_contents($this->path, '{ not json');

        self::assertSame(0, Baseline::fromFile($this->path)->count());
    }

    public function testRoundTrip(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'qg') ?: '';
        $finding = $this->finding('n-plus-one', '/project/src/Repo.php', 'select * from t where id = ?');

        $baseline = Baseline::empty();
        $baseline->add($finding);

        self::assertTrue($baseline->save($this->path));

        $loaded = Baseline::fromFile($this->path);

        self::assertSame(1, $loaded->count());
        self::assertTrue($loaded->contains($finding));
    }

    public function testUnknownFindingIsNotSuppressed(): void
    {
        $baseline = Baseline::empty();
        $baseline->add($this->finding('n-plus-one', '/project/src/Repo.php', 'select a'));

        self::assertFalse($baseline->contains($this->finding('n-plus-one', '/project/src/Repo.php', 'select b')));
    }

    /**
     * The baseline file is committed, and the project root differs in CI. With an
     * absolute path in the key it would work nowhere but the machine that produced it.
     */
    public function testPathsInKeysAreRelativeToTheProjectRoot(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'qg') ?: '';

        $onDeveloperMachine = Baseline::empty('/home/alex/project');
        $onDeveloperMachine->add($this->finding('n-plus-one', '/home/alex/project/src/Repo.php', 'select a'));
        $onDeveloperMachine->save($this->path);

        $inCi = Baseline::fromFile($this->path, '/builds/acme/project');

        self::assertTrue($inCi->contains($this->finding('n-plus-one', '/builds/acme/project/src/Repo.php', 'select a')));
    }

    /**
     * A baseline only ever grows otherwise: the finding gets fixed, the entry stays, and
     * from then on the file silences something that no longer exists.
     */
    public function testEntriesThatSilencedNothingAreReported(): void
    {
        $stillHappening = $this->finding('n-plus-one', '/project/src/Repo.php', 'select a');
        $longSinceFixed = $this->finding('n-plus-one', '/project/src/Legacy.php', 'select b');

        $baseline = Baseline::empty();
        $baseline->add($stillHappening);
        $baseline->add($longSinceFixed);

        self::assertCount(2, $baseline->unmatched());

        $baseline->contains($stillHappening);

        self::assertSame(
            ['n-plus-one|/project/src/Legacy.php|select b'],
            $baseline->unmatched(),
        );
    }

    public function testAskingAboutAnUnknownFindingDoesNotMarkAnythingUsed(): void
    {
        $baseline = Baseline::empty();
        $baseline->add($this->finding('n-plus-one', '/project/src/Repo.php', 'select a'));

        $baseline->contains($this->finding('n-plus-one', '/project/src/Repo.php', 'select b'));

        self::assertCount(1, $baseline->unmatched());
    }

    public function testFindingWithoutSignatureIsNeverStored(): void
    {
        $baseline = Baseline::empty();
        $baseline->add(new Finding('rule', new TestIdentifier('id', 'T::t'), 'message'));

        self::assertSame(0, $baseline->count());
    }

    private function finding(string $rule, string $file, string $fingerprint): Finding
    {
        $callsite = new Callsite($file, 42);

        return new Finding(
            rule: $rule,
            test: new TestIdentifier('id', 'T::t'),
            message: 'message',
            callsite: $callsite,
            signature: Finding::signature($rule, $callsite, $fingerprint),
        );
    }
}
