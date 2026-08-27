# Contributing

Bug reports, ideas and pull requests are welcome.

## Before opening a pull request

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix
```

CI runs the same three checks across PHP 8.2–8.5, both Doctrine DBAL generations
and, for tier 2, live MySQL and PostgreSQL.

## Two things worth knowing

**The wrappers exist in two versions on purpose.** `Adapter/Doctrine/Dbal3` and
`Adapter/Doctrine/Dbal4` differ because `Statement::bindValue()`, `Statement::execute()`
and `Connection::exec()` changed signatures between DBAL 3 and 4. The choice is made at
runtime by `enum_exists(ParameterType::class)`. Static analysis excludes whichever one
does not match the installed version.

**Expected plan flags are written by hand.** The fixtures in `tests/Fixture/Explain` are
real `EXPLAIN` output captured from the stand in `tools/stand`, and the expectations in
`PlanParsingTest` were derived by reading that output — never by recording what the parser
produced. A misunderstanding of a plan must not be able to land in the code and in its
test at the same time.
