# Pest stand

A throwaway project that installs the package the way a Pest user does: `composer
require --dev`, a `<bootstrap>` node in `phpunit.xml`, nothing else. It exists because
Pest broke the extension in a way no test inside this repository could see.

`bin/pest` sets `COLLISION_PRINTER` and `Pest\Plugins\Printer` then appends `--no-output`
to PHPUnit's arguments unconditionally — printing is Collision's job there. Read as "the
user asked for silence", that made the extension bail out of every Pest run: green suite,
no `report-json`, not a line printed, and nothing to tell it apart from "no findings".
Found on a real Laravel suite, fixed in `Extension::printerReplaced()`, and kept fixed by
the `pest` job in CI — which runs this
stand and fails if the report comes back empty.

The tests feed the collector by hand rather than through an ORM: what is under test is
the runner's own wiring, and a real Doctrine or Eloquent between the two would only add
something else that can break.

`ChainTest.php` is the exception to that in one respect, and deliberately: it is written
as a Pest closure and records through a helper, because that is the only shape in which
the runner's own frames reach a finding's call chain. A `TestCase` method leaves no
application frame at all — PHPUnit invokes it by reflection — and a closure recording
inline leaves none either, since `debug_backtrace()` there describes where the closure
was called from. With one frame in between, the callsite is the test's own line and Pest
sits directly above it, which is where four frames of `TestCaseMethodFactory` →
`Testable::__callClosure` used to be reported as the callers. The `pest` job fails if
they come back.

    composer update
    vendor/bin/pest
    vendor/bin/pest --parallel

Each run writes `var/query-guard-<token>.json` — `%token%` in the configured path is
replaced with the ParaTest worker's token, and with nothing at all in a sequential run.
