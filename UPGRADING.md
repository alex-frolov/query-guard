# Upgrading

What breaks between versions, and what to do about it. Every entry here also appears in
[CHANGELOG.md](CHANGELOG.md); this file exists to carry the instructions, which a
changelog entry has no room for.

0.3.0 drew the public API, listed below, and 1.0 freezes it. Since 0.3.0 CI runs
[Roave BC Check](https://github.com/Roave/BackwardCompatibilityCheck) against the most
recent tag on every pull request and fails the build on a break, so a break reaches a
release only as a deliberate decision — and lands here before it reaches anyone's upgrade.
Something public that has to go is deprecated in a minor release first, and removed only
in the next major.

## What is public

The public API is what a project touches without reading this package's source. Semantic
versioning covers all of it; Roave BC Check in CI covers the classes, and the rest is kept
by the changelog discipline below:

- **Configuration.** The parameter names and defaults in `phpunit.xml`, the
  `QUERY_GUARD_GENERATE_BASELINE` variable, the `%token%` placeholder and the rule ids
  (`n-plus-one`, `duplicate-query`, …) used in `#[IgnoreRule]` and in the configuration.
- **Wiring.** `QueryGuard\Extension`, `QueryGuard\Adapter\Doctrine\Middleware` and
  `QueryGuard\Adapter\Eloquent\QueryGuardServiceProvider` — by class name; the middleware
  is built with no arguments.
- **Per-test attributes.** `#[AllowQueries]` and `#[IgnoreRule]`.
- **Feeding queries by hand.** `QueryGuard::collector()`, `QueryCollector` and its
  `record()`/`isRecording()`, the constructor and properties of `QueryEvent`, and
  `Callsite`. `QueryCollector` is public for calling, not for implementing: nothing outside
  the package can put its own collector in place, so a method may be added to it in a minor
  release. The keys of `QueryEvent`'s `annotations` are not part of it.
- **Files.** The JSON report and the baseline, versioned by their `format-version`. The
  number goes up only when a field is removed or starts to mean something else; a new field
  does not raise it. What goes *into* the fields is another matter — see below.

Everything else is marked `@internal`, which Roave BC Check honours, and may change in any
release. That includes `OrmAdapter`, `Explainer`, `QueryEnricher`, `PlanProvider`,
`PlatformDriver` and `Rule`, although they look like extension points. They are seams
inside the package: nothing lets code outside it register an implementation — the
adapters come from a fixed list in `AdapterSet::detect()`, the platform drivers from one in
`PlatformDrivers::for()`, and the rules are constructed in `Extension`. Publishing them
would freeze interfaces that have already changed on purpose while giving nobody a way to
use them. If a real extension point is what you need — your own adapter, your own rule —
open an issue: making an internal class public breaks nothing, and it is done together
with the way to plug one in.

### What a minor release may still change

Two things are left out of the promise on purpose, because holding them until the next
major would mean holding back the detection itself. When a release changes either, its
entry below says so and names the rules affected.

**Finding signatures.** A baseline entry is keyed by `rule|file|fingerprint`, and the
fingerprint is the query's SQL after normalisation. Normalisation gets better, and each
improvement changes the fingerprint of the queries it touches. The first one expected is
the SQL dialect: the same DQL becomes `CONCAT(...)` and `CAST(... AS CHAR)` on MySQL but
`... || ...` and `CAST(... AS VARCHAR)` on PostgreSQL, and on one schema checked on all
three platforms 2 findings of 114 differed that way.

A changed signature does not change the file's format, so `format-version` stays where it
is — what changes is which keys match. It shows up as known findings arriving as new, and
the same number of entries reporting that they silenced nothing. Regenerate from a full
run, on the platform CI runs on, and check that the diff moves entries rather than adds
them:

```bash
QUERY_GUARD_GENERATE_BASELINE=1 vendor/bin/phpunit
git diff --stat tests/query-guard-baseline.json
```

The README recipe "Keeping a baseline healthy through a refactor" covers reading that diff.

**Severity can rise.** A finding becomes `error` when the tool has proved it rather than
guessed it from the shape of the query, and a rule that learns to prove more reports more
errors. The first one expected is Eloquent: its `n-plus-one` findings are all `warning`
today, because the adapter does not yet recognise lazy loading in the stack the way the
Doctrine one does. When it does, they become `error` in a minor release.

With the default `fail-on="warning"` that changes nothing: the same finding already failed
the run as a warning. With `fail-on="error"` a run can start failing on findings it
used to print and pass — on Eloquent, a run that could not fail at all. Severity is not
part of the signature, so a finding already in the baseline stays silenced; what fails is
what was never baselined. Before such an upgrade, run once with `mode="report"` and look at
the `[error]` lines, or regenerate the baseline if they are all known.

## Unreleased

### `duplicate-threshold` is now `duplicate-query-threshold`

Rename the parameter in `phpunit.xml`:

```xml
<parameter name="duplicate-query-threshold" value="5"/>
```

Nothing breaks if you do not: the old name is still read until 2.0, and every run says so
in the summary. It is not dropped at once because PHPUnit gives an extension no way to list
the parameters that were written — an unknown name is ignored, and the rule would quietly
fall back to its default of 5 without a word. If both names are set, the new one wins and
the summary says the old one was ignored.

## 0.3.0

### Classes outside the public API are marked `@internal`

Nothing was moved, renamed or removed; code that uses these classes directly keeps working
today. It is no longer covered by the version number, and Roave BC Check reports each of
them once as having become internal. If your project depends on one, open an issue saying
what for — that is how an extension point gets designed rather than inherited by accident.

### `Middleware` takes no arguments

The optional `QueryEnricher` parameter is gone. Nothing in the documentation passed one,
and a replacement enricher could at best reproduce the built-in one: the only reader of the
annotations is `n-plus-one`, and it reads the keys `DoctrineEnricher` writes. If your
container configuration passes an argument to `QueryGuard\Adapter\Doctrine\Middleware`,
delete it: passed by position it is now silently ignored — PHP accepts extra arguments,
which is also why Roave BC Check does not report this removal — and passed by name
(`$enricher:`) it fails the container build. Enrichment stays on.

### `format-version` in the baseline and the JSON report

Nothing to do. A baseline without the field is read as format 1, so a committed file keeps
working without regenerating. A file regenerated by this release carries the field, and
0.2.1 reads that file too — it ignores fields it does not know. A release that meets a
format it cannot read applies none of the entries and says so in the summary.
