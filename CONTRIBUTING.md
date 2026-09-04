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

No local PHP is needed for any of it — `./dev.sh` in the repository root runs the same
commands in a container:

```bash
QG_IMAGE=php:8.4-cli ./dev.sh php vendor/bin/phpunit
```

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

**Backward compatibility is checked, and does not block — yet.** CI runs
[Roave BC Check](https://github.com/Roave/BackwardCompatibilityCheck) against the most
recent tag and prints what a release from this branch would break. While the package is
0.x that job is informational: the seams are still being cut, and `OrmAdapter` and
`PlanProvider` have already changed on purpose. The job exists so a break is visible in
the pull request that causes it and reaches the CHANGELOG, rather than reaching a user's
upgrade. **A reported break is not a blocker; a reported break missing from the CHANGELOG
is.** The findings are rendered into the run summary, grouped as added / changed /
removed — read them there rather than in the log. At 1.0, drop the `3` from the exit-code
condition in the workflow and breaks start failing the build.

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
- The step reads Roave's exit code itself instead of using `continue-on-error`. Roave
  answers "I found breaks" with `3`, which before 1.0 is information rather than failure,
  so the step treats `3` as success and anything else as the tool falling over. The
  one-line `continue-on-error` version was tried first and is worse: it paints the check
  red on every run that finds anything, and a check that is always red is a check nobody
  reads by the time 1.0 makes it mean something.

`--install-development-dependencies` is needed because the ORMs are dev dependencies
here: without them the adapters' parent classes cannot be resolved, and the tool skips
those classes instead of judging them.

**Public API is removed before 1.0, not after.** Roave BC Check reports what a release
from the branch would break, and while the package is 0.x that is information rather than
a blocker — but the window closes. A method nothing calls is a method the project will be
supporting for years if it is still there at 1.0, so an unused seam is deleted rather
than kept "in case". Whatever goes gets an entry in `CHANGELOG.md` under `Removed` and,
if there is anything to say about migrating, in `UPGRADING.md`.

**Both READMEs are edited together.** `README.md` and `README.ru.md` are kept heading for
heading; a change to one that does not reach the other is a change that will be lost.

**Expected plan flags are written by hand.** The fixtures in `tests/Fixture/Explain` are
real `EXPLAIN` output captured from the stand in `tools/stand`, and the expectations in
`PlanParsingTest` were derived by reading that output — never by recording what the parser
produced. A misunderstanding of a plan must not be able to land in the code and in its
test at the same time.
