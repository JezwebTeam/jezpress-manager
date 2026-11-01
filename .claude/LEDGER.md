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

> Rows up to 1.1.4 were **reconstructed on 2026-09-21**, when this ledger was added — from `readme.txt`, the git tags
> (dates are tag dates) and a live `GET /api/dev/plugins/jezpress-manager`. `Audit` reads `not recorded` because no
> contemporaneous verdict exists; 1.1.4 did follow a security review, but this ledger only records verdicts it witnessed,
> so nothing is backfilled. `Dashboard` reads `not recorded` for the same reason — the description/changelog tabs are
> populated on the server today, but that is plugin-level state and cannot be attributed to a particular release.
> 1.0.0 is `n/a` on JezPress: only 1.1.0+ exist on the update server. From the next release on, the row is appended
> live as step 10 of the `/release` skill.

| Version | Date | Audit | Shipped | JezPress | Dashboard |
|---|---|---|---|---|---|
| 1.1.4 | 2026-06-13 | not recorded | Hardened the dashboard activate/deactivate AJAX handler against path traversal | uploaded | not recorded |
| 1.1.3 | 2026-04-23 | not recorded | Lowered the minimum PHP requirement from 8.2 to 8.1 | uploaded | not recorded |
| 1.1.2 | 2026-04-16 | not recorded | Fixed the updater to use the /api/v1/ endpoints and map requires_wp / tested_wp | uploaded | not recorded |
| 1.1.1 | 2026-04-16 | not recorded | Added the Status toggle column and detection of inactive JezPress plugins | uploaded | not recorded |
| 1.1.0 | 2026-04-10 | not recorded | Added the JezPress updater for automatic updates | uploaded | not recorded |
| 1.0.0 | 2026-04-10 | not recorded | Initial release - central dashboard, licence badges, support and quick links | n/a | not recorded |
