# Upgrading

What breaks between versions, and what to do about it. Every entry here also appears in
[CHANGELOG.md](CHANGELOG.md); this file exists to carry the instructions, which a
changelog entry has no room for.

While the package is `0.x` the public API is still being cut, and breaks are allowed
between minor versions. CI runs
[Roave BC Check](https://github.com/Roave/BackwardCompatibilityCheck) against the most
recent tag on every pull request, so a break is visible in the change that causes it —
and lands here before it reaches anyone's upgrade.

**Who this affects.** Almost nothing here touches a project that installs the extension,
configures it in `phpunit.xml` and reads the summary. The seams that move are the ones
used by code extending the package: a custom `OrmAdapter`, a hand-fed collector, a rule
of your own.

## Unreleased

### `OrmAdapter::notices()` is new and required

Any class implementing `QueryGuard\Adapter\OrmAdapter` must now provide it. Adapters
built inside this package have it already; a custom one does not.

Return an empty list unless the adapter has something to say about a run that *did*
collect queries — a listener that reached one connection of several, two databases the
adapter cannot tell apart. `installationHint()` still covers the other case, where
nothing was collected at all.

```php
public function notices(): array
{
    return [];
}
```

### `DoctrineAdapter::markConnected()` became `register()`

Called only from `QueryGuard\Adapter\Doctrine\Driver`, so this matters if you build the
DBAL wrappers yourself. The new method takes an endpoint alongside the label and returns
the name the connection is actually known under:

```php
// before
DoctrineAdapter::markConnected($name, $platform, $connection);

// after — the returned name may be suffixed when two databases share a name
$name = DoctrineAdapter::register($label, $endpoint, $platform, $connection);
```

`$label` is what a developer recognises (the database name); `$endpoint` is what
identifies it (database, host, port, user). A second endpoint claiming a taken label is
registered as `label#2`, and the summary says so.

### Removed: unused public API

None of these was called from inside the package, and removing them before 1.0 is
cheaper than supporting them afterwards. If you were using one, the replacement is in
brackets:

- `Plan::estimatedRows()` and the `Plan::$cost` constructor argument — plan cost is no
  longer parsed at all (sum `PlanNode::$estimatedRows` yourself; cost has no replacement)
- `Finding::withSignature()` (construct a `Finding` with the signature you want)
- `RuleEngine::ruleIds()` (map over the rules you passed in)
- `Callsite::equals()` (compare `file` and `line`, or the `__toString()` values)
- `Trace::durationMs()` and `Trace::isEmpty()` (sum `QueryEvent::$durationMs` over
  `events()`; `[] === $trace->events()`)
- `Platform\Json::float()` (nothing used it once cost went)

`Plan::__construct()` lost its third parameter, so a positional
`new Plan($platform, $nodes, $cost, $connection)` becomes
`new Plan($platform, $nodes, $connection)`.

### Fingerprints changed shape

`Fingerprint::of()` now strips comments before normalising, so a statement carrying one
fingerprints the same as the statement without it. This is a fix — a per-request comment
used to give the same query a different fingerprint every run, and the grouping rules saw
nothing at all — but it changes the value.

**A baseline keyed on a commented statement will stop matching.** Regenerate it from a
full run, and expect the diff to show entries moving rather than appearing:

```bash
QUERY_GUARD_GENERATE_BASELINE=1 vendor/bin/phpunit
git diff --stat tests/query-guard-baseline.json
```

Projects whose SQL carries no comments — most of them — see no change.
