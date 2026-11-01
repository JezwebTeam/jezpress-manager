# JezPress plugin build playbook (7 phases)

Follow these phases **in order** when scaffolding/building a JezPress plugin. Each phase has an audit gate. Don't reorder; don't skip the audits. This sequences *when* to do things — `scaffold-standard.md` is *how*.

> **This copy is the free-plugin variant.** `jezpress-manager` ships with **no licence layer** — no `<X>_License` class, no hashed `jzwb_lic_*` option, no licence cron, no licence tab, no `license_key` on any request. The licence phase of the standard playbook is deliberately omitted here; if you find yourself adding licence code to this plugin, stop — that is a scope change, not a gap in the docs. (The dashboard *displays* a `license_status` badge that other plugins pass into `JezPress_Manager::register()`; it never validates or stores a key of its own.)

## Phase 0 — Plan & scope *(no audit)*
- Define the **single thing** the plugin does. Resist scope creep.
- Pick: slug (`jezpress-<thing>`), fn prefix (`<x>_`), class/const prefix (`<X>_`), namespace segment.
- Create the GitHub repo **under the JezwebTeam org** (not a personal account). Actions auto-enable.

## Phase 1 — Scaffold *(full phase-end audit)*
- Start from `wp-plugin-template` (clone or copy the local working copy). Drop the template `.git/` before `git init`.
- Bootstrap, updater, admin, security baseline, uninstall, manager registration are pre-wired — do NOT rebuild these. **Strip the template's licence wiring for a free plugin.**
- Substitute placeholders (name, slug, prefixes, description). Rename files first, then content-replace. Verify zero leftover `{[A-Z_]*}` tokens (case-sensitive).
- Write the plugin's own `CLAUDE.md`, a repo-root `PLAN.md` (see `PLAN.template.md`), and seed `.claude/LEDGER.md` from `LEDGER.template.md`.
- Run the acceptance checklist (`scaffold-standard.md`) as a verification pass.

## Phase 2 — Settings + admin *(full audit)*
- A single settings option + one sanitiser that clamps every key (allowlists for enums as `const`s).
- Tabs: General / Documentation. (No License tab in a free plugin.)

## Phase 3 — Core feature *(full audit)*
- The thing the plugin does. AJAX pattern: `check_ajax_referer` FIRST → ownership/capability validation → `wp_unslash` + sanitize every superglobal → escape on output.
- Templates via a `get_template()` helper (child → parent → plugin).

## Phase 4 — Updater *(full audit + live `curl` test)*
- The updater silently fails open: a broken one shows no error, it just never offers an update. See `updater-contract.md` for the endpoints this plugin uses and the verification curl.
- Live-verify against `updates.jezpress.com` before declaring done.

## Phase 5 — Release infrastructure *(full audit)*
- `.github/workflows/release.yml` is the canonical release path — substitute placeholders; do NOT write one from scratch, and do NOT copy one out of another plugin repo (that is how two "canonical" copies drifted five weeks apart, the older one missing a changelog-extraction fix). Keep the security invariant in its header: never interpolate `${{ }}` into a `run:` block.
- Write `readme.txt` (Stable tag + all sections), `uninstall.php` (remove all options/transients/cron + any custom tables), and a `.pot` if the plugin ships translatable strings (`php .claude/tools/make-pot.php`).
- Set repo secret `JEZPRESS_TOKEN` (set on this repo — CI uploads to JezPress on tag push). Leave `GCHAT_WEBHOOK_URL` **unset** — the Google Chat card is posted from the release flow, not CI, so keeping the secret unset avoids double-posting.

## Phase 6 — Release *(pre-release audit MANDATORY)*
- Bump the version across: plugin header `Version:`, `<PREFIX>_VERSION`, `readme.txt` `Stable tag:`, `.pot` (if present), CLAUDE.md.
- Run the pre-release audit (non-negotiable gate). Apply Critical/High → re-audit if material.
- Tag → push → watch the workflow. Then per-version changelog + dashboard tab patches + Google Chat — all orchestrated by the **`/release` skill** (`.claude/skills/release/`).
- **Append a row to `.claude/LEDGER.md`** (newest first): version, date, audit verdict, one-line summary, JezPress + dashboard status. Every release, not just the first.

## Phase 7 — First deployment *(no audit)*
- Add a `## Known deployments` row to CLAUDE.md (prod URL, contact, owner, settings, quirks).
- File bugs as ERPNext issues with `client_management: <domain>`.

## Audit gates
| Phase | Audit |
|---|---|
| 0 Plan | — |
| 1 Scaffold | Full (architecture) |
| 2 Settings | Full (persistence) |
| 3 Core | Full (attack surface) |
| 4 Updater | Full + live curl |
| 5 Release infra | Full (pipeline) |
| 6 Release | **Pre-release (mandatory)** |
| 7 Onboarding | — |

Audits = dispatch a senior WP plugin code-review subagent (read-only, CRITICAL/HIGH/MEDIUM/LOW with file:line). If subagents are unavailable, do a manual self-review and **say so explicitly**.
