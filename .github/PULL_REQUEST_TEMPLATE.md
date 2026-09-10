## What this changes

<!-- One or two sentences. The why belongs in the code, next to the decision. -->

## Why

<!--
What made the change necessary — the measurement, the run against a real project, the
misdiagnosis. This package documents reasons rather than mechanics, and a reviewer needs
the same reason the code will end up carrying.
-->

## Checklist

- [ ] `composer check` passes (`phpunit`, `phpstan` over `src` and over `tests`, `php-cs-fixer`)
- [ ] New behaviour is covered by a test that fails without the change
- [ ] Both READMEs are updated, or neither needed it — `README.md` and `README.ru.md` are
      kept heading for heading, and a change reaching only one will be lost
- [ ] `CHANGELOG.md` has an entry under `Unreleased`
- [ ] If the Roave BC job reports a break, it is in `CHANGELOG.md` and `UPGRADING.md`.
      **A break is not a blocker before 1.0; a break missing from those files is.**

## For a change to a rule

<!-- Delete this section if it does not apply. -->

- [ ] What it would newly report, and what it would stop reporting
- [ ] What could look identical and be fine — the false positive it has to avoid
- [ ] Whether it can judge on every supported platform, and what it says where it cannot.
      Silence and "all clear" must never be the same output.
