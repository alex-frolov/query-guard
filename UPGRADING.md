# Upgrading

What breaks between versions, and what to do about it. Every entry here also appears in
[CHANGELOG.md](CHANGELOG.md); this file exists to carry the instructions, which a
changelog entry has no room for.

While the package is `0.x` the public API is still being cut, and breaks are allowed
between minor versions. CI runs
[Roave BC Check](https://github.com/Roave/BackwardCompatibilityCheck) against the most
recent tag on every pull request, so a break is visible in the change that causes it —
and lands here before it reaches anyone's upgrade.

