# Repository guidance

Read [.agents/INDEX.md](.agents/INDEX.md), the relevant domain reference and [shared policies](.agents/policies/project.md) before making changes.

Run the compatibility checks documented in [README.md](README.md) for PHP/WordPress behavior changes. Keep fixture operations confined to disposable local sites. After verification, update the owning reference when setup, architecture or limitations change; dated results belong under that domain's `references/audits/`.

Keep project knowledge Git tracked under `.agents/`, with one authoritative reference per functionality. Domain skills are routing entrypoints; root instructions must not accumulate feature implementation details.
