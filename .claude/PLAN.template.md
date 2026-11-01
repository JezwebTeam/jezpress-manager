# PLAN.md — JezPress Manager

**Slug:** `jezpress-manager` · **Class prefix:** `JezPress_Manager` · **Const prefix:** `JEZPRESS_MANAGER_` · **Text domain:** `jezpress-manager`
**Status:** <phase> — updated <YYYY-MM-DD>.

> Dev-only file. Excluded from the release ZIP (`release.yml` excludes `PLAN.md`). Mirrors `.claude/build-playbook.md`; tick items as each phase passes its audit gate.
>
> **No licence layer.** This plugin is free — no licence class, option, cron or key. The licence rows of the standard template are deliberately absent; don't reinstate them.

## The single thing it does
<one paragraph — the one job; resist scope creep>

## Confirmed decisions (locked)
1. <decision + why; rejected alternatives>
2. ...

## Build phases (playbook-aligned)
- [ ] **Phase 0 — Plan & scope.** Naming locked. Repo under JezwebTeam org. *(no audit)*
- [ ] **Phase 1 — Scaffold.** Placeholders substituted (verify 0 leftovers), CLAUDE.md + PLAN.md + `.claude/LEDGER.md` written, checklist pass. *(full audit)*
- [ ] **Phase 2 — Settings + admin.** *(full audit)*
- [ ] **Phase 3 — Core feature.** *(full audit)*
- [ ] **Phase 4 — Updater.** `/api/v1/info`, no licence; live curl test. *(full audit + live test)*
- [ ] **Phase 5 — Release infra.** release.yml + readme.txt + uninstall.php (+ .pot if translatable strings ship). *(full audit)*
- [ ] **Phase 6 — Release.** Pre-release audit; tag → workflow → upload → changelog → dashboard patches → LEDGER row. *(pre-release audit)*
- [ ] **Phase 7 — Deployment.** Known deployments row; ERPNext issues. *(no audit)*

## Data footprint
Every option / transient / cron event / table this plugin creates. Fill it in **as you add each one**, not at the end — this table is what `uninstall.php` gets checked against, so a missing row becomes an orphaned row in `wp_options` that nothing will ever surface.

| Kind | Name | Created by | Cleaned by `uninstall.php` |
|---|---|---|---|
| option | `jezpress_manager_version` | activation hook | ⬜ confirm in `uninstall.php` |
| transient | `jezpress_manager_update_info` | updater cache (12 h) | ⬜ confirm explicit delete |
| transient | `jezpress_manager_activation_redirect` | activation hook (30 s) | ⬜ confirm explicit delete |
| cron | *none* | — | — |
| table | *none* | — | — |

> Custom tables: avoid unless genuinely required. If one is unavoidable — `dbDelta()` + a `jezpress_manager_db_version` option + an explicit `DROP TABLE` in `uninstall.php`, and add both rows here.
>
> Watch the naming rule: a LIKE sweep only catches transients prefixed consistently. Anything named otherwise needs its own explicit delete.

## Acceptance criteria
5–8 statements that are true when the work is done, each with *how it gets checked*. These are **functional** criteria — the pre-release audit is a code review and will not verify behaviour, so these become the feature-specific rows of the `## Testing Checklist` in `CLAUDE.md`.

| # | Criterion | Verified by |
|---|---|---|
| 1 | <observable behaviour, stated so it can only be true or false> | <staging click-through / `curl` / WP-CLI> |
| 2 | Dashboard renders correctly with zero registered plugins **and** with several | staging: deactivate all JezPress plugins, reload |
| 3 | The activate/deactivate toggle rejects a forged `plugin_file` (nonce + `validate_plugin()`) | crafted admin-ajax POST returns an error, no filesystem access |
| 4 | Deactivate → reactivate leaves no duplicate cron or orphaned option | options diff + `wp cron event list` |

> Bias these toward the failures that produce **no error message** — those are the ones no audit gate catches. Anything that throws a PHP warning or a 500 will be found anyway.

## Open items / to confirm
- <...>

## Audit-gate note
<record any deferred/manual audits and why>
