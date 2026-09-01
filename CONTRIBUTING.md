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

CI runs it across PHP 8.2–8.5, both Doctrine DBAL generations and, for tier 2, live
MySQL and PostgreSQL. It also measures coverage on one job — `composer coverage`
locally, which needs pcov or Xdebug installed.

**Coverage is a signal, not a gate, and the number reads low on purpose.** The
end-to-end tests launch PHPUnit in a child process, and a child process contributes
nothing to the parent's coverage — so `Extension` and the subscribers, which those tests
exercise more thoroughly than anything else, show up as barely covered. Read the figure
for `src/Rule` and `src/Platform`; ignore it for the wiring.

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

**Both READMEs are edited together.** `README.md` and `README.ru.md` are kept heading for
heading; a change to one that does not reach the other is a change that will be lost.

**Expected plan flags are written by hand.** The fixtures in `tests/Fixture/Explain` are
real `EXPLAIN` output captured from the stand in `tools/stand`, and the expectations in
`PlanParsingTest` were derived by reading that output — never by recording what the parser
produced. A misunderstanding of a plan must not be able to land in the code and in its
test at the same time.
