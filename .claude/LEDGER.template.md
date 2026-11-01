# Release ledger — JezPress Manager

**Slug:** `jezpress-manager`

> Dev-only, append-only **governance log**. Excluded from the release ZIP (the whole `.claude/` folder is excluded by `release.yml`). One row per shipped version, **newest first**, appended at Phase 6 of every release once the pre-release audit verdict is known and the dashboard patches are confirmed.
>
> **Why it exists:** the `Audit` and `JezPress`/`Dashboard` columns are the point — they are the only durable record that the audit gate ran and the ship steps completed (git tags + `readme.txt` don't capture that). `Version`/`Date`/`Shipped` are just anchors that also live in git + `readme.txt`; keep `Shipped` to **one line** — do not reproduce the changelog here.

## Audit column values
- `clean` — pre-release audit found no Critical/High.
- `fixes (N H)` / `fixes (N C)` — N High/Critical found and fixed before the tag; re-audited if material.
- `blocked → fixed` — a Critical was present; shipped only after it was resolved (note what in the row).
- `manual` — subagents unavailable, manual self-review only (per `audit-guide.md` — say so explicitly).
- `not recorded` — released before this ledger existed. Never backfill a verdict you didn't witness.

## JezPress / Dashboard column values
- **JezPress**: `uploaded` · `skipped` (no token) · `n/a`
- **Dashboard**: `patched` (description + changelog tabs) · `pending` · `n/a`

## Ledger
| Version | Date | Audit | Shipped | JezPress | Dashboard |
|---|---|---|---|---|---|
| <X.Y.Z> | <YYYY-MM-DD> | clean | <one-line summary of what this release does> | uploaded | patched |
