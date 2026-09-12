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

    /**
     * The same DQL becomes different SQL per dialect, and that difference reaches the
     * signature. The file says which platform produced it so a later run on another one
     * can explain why known findings look new.
     */
    public function testThePlatformIsStampedAndReadBack(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'qg') ?: '';

        self::assertTrue(Baseline::empty()->save($this->path, 'mysql'));
        self::assertSame('mysql', Baseline::fromFile($this->path)->platform());
    }

    /**
     * A file written before the field existed, and a run that never opened a connection,
     * both say nothing rather than guessing.
     */
    public function testAFileWithoutAPlatformSaysNothing(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'qg') ?: '';
        file_put_contents($this->path, '{"findings": {}}');

        self::assertSame('', Baseline::fromFile($this->path)->platform());
        self::assertSame('', Baseline::empty()->platform());
    }

    /**
     * The file is committed and outlives the release that wrote it; the number is how a
     * later reader tells a file it understands from one it would only misread.
     */
    public function testTheFormatVersionIsStamped(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'qg') ?: '';

        self::assertTrue(Baseline::empty()->save($this->path));

        $decoded = json_decode((string) file_get_contents($this->path), true);

        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['format-version']);
    }

    /**
     * Every baseline committed before the field existed has to keep working.
     */
    public function testAFileWithoutAFormatVersionIsReadAsTheFirstFormat(): void
    {
        $finding = $this->finding('n-plus-one', '/project/src/Repo.php', 'select a');
        $this->path = $this->savedWith($finding, static function (array $decoded): array {
            unset($decoded['format-version']);

            return $decoded;
        });

        $loaded = Baseline::fromFile($this->path);

        self::assertNull($loaded->unreadableFormat());
        self::assertTrue($loaded->contains($finding));
    }

    /**
     * A file from a newer release may mean something else by the same keys. Matching them
     * anyway would silence findings by accident; the caller explains the empty result.
     */
    public function testAFormatThisReleaseCannotReadSilencesNothing(): void
    {
        $finding = $this->finding('n-plus-one', '/project/src/Repo.php', 'select a');

        foreach (['2' => 2, '"1"' => '1', '0' => 0, 'null' => null] as $expected => $format) {
            $this->path = $this->savedWith($finding, static function (array $decoded) use ($format): array {
                $decoded['format-version'] = $format;

                return $decoded;
            });

            $loaded = Baseline::fromFile($this->path);

            self::assertSame((string) $expected, $loaded->unreadableFormat());
            self::assertSame(0, $loaded->count());
            self::assertFalse($loaded->contains($finding));
            self::assertSame('', $loaded->platform());
        }
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

    /**
     * A baseline holding one finding, saved and then rewritten by hand.
     *
     * @param callable(array<array-key, mixed>): array<array-key, mixed> $edit
     */
    private function savedWith(Finding $finding, callable $edit): string
    {
        $path = '' === $this->path ? (tempnam(sys_get_temp_dir(), 'qg') ?: '') : $this->path;

        $baseline = Baseline::empty();
        $baseline->add($finding);
        self::assertTrue($baseline->save($path, 'mysql'));

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        file_put_contents($path, json_encode($edit($decoded)));

        return $path;
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
