# Contributing

Bug reports, ideas and pull requests are welcome.

## Before opening a pull request

```bash
composer update
composer check
```

That is the whole gate, and it is the same one CI runs:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G                                  # src, level max
vendor/bin/phpstan analyse --configuration=tools/phpstan/tests.neon           # tests, level 9
vendor/bin/php-cs-fixer fix --dry-run --diff                                   # `composer fix` applies
```

No local PHP is needed for any of it — run the same commands in a container:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.4-cli php vendor/bin/phpunit
```

See the README's [Development](README.md#development) section for the full set of
commands, including how dependencies are installed and how tier 2 is verified against a
live database.

CI runs it across PHP 8.2–8.5, both Doctrine DBAL generations and, for tier 2, live
MySQL and PostgreSQL. It also measures coverage on one job — `composer coverage`
locally, which needs pcov or Xdebug installed.

**Coverage is a signal, not a gate.** The CI job prints the figure on every run and
enforces no minimum. Roughly where the gaps are, and why each one is where it is:

| | Covered by |
|---|---|
| wiring (`Extension`, `Subscriber/*`, the service provider) | unit tests driving PHPUnit's own event objects — high, and see below for the six statements that cannot be |
| `src/Query`, `src/Rule` | unit tests; these are pure functions over a trace and there is no excuse for a gap |
| `src/Report` | unit tests over a captured stream and a written file |
| `src/Adapter` | integration tests — the Doctrine DBAL wrappers need a live driver, so a chunk only runs where one exists |
| `src/Platform` | the tier 2 stand tests, which skip without a live database |

Do not read a percentage as a verdict, and especially do not read a number written down
here as current — this table says where coverage comes from, which changes far more
slowly than the figure does.

The end-to-end tests count for none of it: they launch PHPUnit in a child process, and a
child process contributes nothing to the parent's coverage, so the reported figure always
understates what the suite exercises. The wiring is covered by `tests/Unit/Subscriber` and
`tests/Unit/ExtensionTest`, which build PHPUnit's own event objects by hand and hand them
to a subscriber directly.

That is a different question from the one the end-to-end suite answers, and it does not
replace it. A unit test can check what a subscriber decides; only a real runner can check
that PHPUnit calls it at all, and at the moment the package expects. That moment is the
decision false positives hinge on — the trace opens on `Test\Prepared`, after `setUp()` —
and it is asserted nowhere else. Never move those tests in-process: running the extension
inside the runner that is running it is not the thing under test.

Six statements in the wiring are deliberately not covered, because reaching them means
breaking the run that would be doing the reaching:

- `strict` mode fails a run from a shutdown function that calls `exit(1)`. In-process,
  that is the test suite's own exit code. `tests/EndToEnd/ExtensionTest.php` asserts it
  against a real runner instead, exit code and all;
- `Extension` gives up when PHPUnit is configured for no output at all — which needs a
  `TextUI` `Configuration` saying so, and that is a final value object whose constructor
  changes shape between PHPUnit versions, so the tests pass the real one of the run in
  progress;
- and when the output stream cannot be opened, which `fopen('php://stdout')` does not do.

`tests/Unit/ExtensionTest.php` still drives `bootstrap()` on every supported version, but
below PHPUnit 13 it can only assert that the collector was activated: the extension facade
became an interface in 13, and before that it is final and writes straight into an event
system that is sealed by the time any test runs. Everything the extension decides has
happened by the point that throws, so the sealing is caught and the rest is skipped.

`composer update` and not `install`: this is a library, `composer.lock` is deliberately
not in the repository, and CI resolves dependencies afresh on every run.

## A few things worth knowing

**The wrappers exist in two versions on purpose.** `Adapter/Doctrine/Dbal3` and
`Adapter/Doctrine/Dbal4` differ because `Statement::bindValue()`, `Statement::execute()`
and `Connection::exec()` changed signatures between DBAL 3 and 4. The choice is made at
runtime by `enum_exists(ParameterType::class)`. Static analysis excludes whichever one
does not match the installed version.

**The suite is analysed too, one level below `src`.** `tools/phpstan/tests.neon` covers
`tests`, because that is where `FakeAdapter` and the hand-fed collector live: they are
written against the same seams the package publishes, and when a seam moves without them
the suite still passes — it is exercising the fixture, not the package. Its `ignoreErrors`
entries are for intentional test idioms only, each with the reason next to it.

**Backward compatibility is checked, and blocks.** CI runs
[Roave BC Check](https://github.com/Roave/BackwardCompatibilityCheck) against the most
recent tag, and a break fails the build. Until 0.3.0 the job was informational, because
the seams were still being cut — `OrmAdapter` and `PlanProvider` changed on purpose more
than once. 0.3.0 drew the public API (see [UPGRADING.md](UPGRADING.md#what-is-public)),
1.0.0 made it stable, and a break has to be a decision rather than a side effect.
The findings are rendered into the run summary, grouped as added / changed / removed —
read them there rather than in the log.

An intended break is recorded, not waved through. Add an `ignored-regex` for each reported
line to `.roave-backward-compatibility-check.xml` in the repository root, in the same pull
request, and describe the break in `CHANGELOG.md` and `UPGRADING.md`. The file is where a
reviewer sees that the break was meant. Such an entry means a major release, so it belongs
in a pull request that is headed for one.

Two details of how it is wired, both learned the hard way:

- It is installed with `composer global require`, not added to `composer.json`. Roave
  brings a large dependency tree of its own, and putting it beside PHPStan and
  php-cs-fixer is how this package would acquire the dependency conflict it currently
  does not have.
- It is **not** the `docker://nyholm/roave-bc-check-ga` action, which is the obvious way
  to run it. That image pins `nikic/php-parser` 4, which cannot read PHP 8.4 syntax, so
  every dependency using it becomes a parse warning — 109 reported "changes" of which 98
  were noise. It also runs as root over a checkout owned by the runner, which git refuses
  to read at all. The global install carries a current parser and runs as the right user;
  the same comparison then reports 14 lines, 12 of them real.
- The step reads Roave's exit code itself instead of leaving it to the shell. Roave
  answers "I found breaks" with `3`, and a failing step still has to write the report into
  the run summary first — otherwise the red check says that something broke without saying
  what. Any other non-zero code is reported as the tool itself falling over. (While the
  job was informational, `continue-on-error` was tried first and was worse: it painted the
  check red on every run that found anything, and a check that is always red is a check
  nobody reads.)

`--install-development-dependencies` is needed because the ORMs are dev dependencies
here: without them the adapters' parent classes cannot be resolved, and the tool skips
those classes instead of judging them.

**Public API is not removed in a minor release.** A method nothing calls is a method the
project supports for years once it is public, which is why 0.3.0 marked everything outside
the list in UPGRADING.md `@internal` rather than keeping it "in case". What is public now
leaves in two steps: deprecated in a minor release, with a notice or a `@deprecated` tag
that says what to use instead, and removed in the next major. `duplicate-threshold` →
`duplicate-query-threshold` is the example: the old name is still read, and the summary
asks for the rename. Both steps get an entry in `CHANGELOG.md` (`Deprecated`, then
`Removed`) and, if there is anything to say about migrating, in `UPGRADING.md`.

**A new class starts `@internal`.** The public API is the short list in
[UPGRADING.md](UPGRADING.md#what-is-public), and Roave BC Check treats any class without
the tag as part of it. Leaving the tag off is how a helper becomes something to support
for years; making a class public later breaks nothing, so that is the cheap direction. A
class that joins the public API is added to that list in the same pull request.

**Both READMEs are edited together.** `README.md` and `README.ru.md` are kept heading for
heading; a change to one that does not reach the other is a change that will be lost.

**Expected plan flags are written by hand.** The fixtures in `tests/Fixture/Explain` are
real `EXPLAIN` output captured from the stand in `tools/stand`, and the expectations in
`PlanParsingTest` were derived by reading that output — never by recording what the parser
produced. A misunderstanding of a plan must not be able to land in the code and in its
test at the same time.
