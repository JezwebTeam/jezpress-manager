# `.claude/` — JezPress plugin knowledge bundle (jezpress-manager)

Portable, committed, repo-safe knowledge that Claude Code auto-loads when you open the project (via the `@import` lines in the repo-root `CLAUDE.md`).

Adapted from the `jezpress-smtp` bundle on 2026-09-21. **The licence layer is deliberately not carried over** — `jezpress-manager` is a free plugin with no licence validation, so there is no `<X>_License` class, no hashed `jzwb_lic_*` option, no licence cron, no licence tab, and no `license_key` on any request. Every doc here is the *free-plugin variant*; the licensed-plugin rules (the `license_key`-in-URL contract, the licence points of the scaffold checklist, the licence phase of the playbook) live in the `jezpress-smtp` bundle and the user-level memories, not here. The dashboard's `license_status` badge is just a value other plugins pass into `JezPress_Manager::register()` — this plugin never validates or stores a key.

## What's here

| File | Purpose |
|---|---|
| `build-playbook.md` | The ordered 7-phase sequence for building a JezPress plugin end-to-end. Start here. |
| `scaffold-standard.md` | Acceptance checklist + canonical-source pointers (free-plugin variant). |
| `updater-contract.md` | What this plugin's updater actually calls (`/api/v1/info`, unauthenticated, 12-hour transient) and how to verify it. |
| `audit-guide.md` | How to run the phase-end / pre-release audit gates. |
| `PLAN.template.md` | Skeleton for a repo-root `PLAN.md` (the live phase checklist for a build). |
| `LEDGER.template.md` | Skeleton for `LEDGER.md`. |
| `LEDGER.md` | This plugin's append-only release + audit log, one row per shipped version. |
| `skills/release/SKILL.md` | The `/release X.Y.Z` skill — bump → audit → tag → push → CI upload → changelog → dashboard tabs → LEDGER → updater verify → Google Chat. |
| `agents/jezpress-code-reviewer.md` | Reusable senior WP code-reviewer subagent (auto-discovered) for the audit gates. |
| `settings.json` | Scoped permission allowlist — auto-approves routine dev + the release steps (push/release/jezpress upload); still prompts for `curl`/`wget`, `gh repo`, and destructive git/deletes. |
| `tools/php-compat.php` | Lints every **shipped** file with a real PHP binary of the **declared** minimum (8.1), which `php -l` on your local PHP does not do. |
| `tools/make-pot.php` | Token-based `.pot` generator (`languages/jezpress-manager.pot`). Only needed once the plugin ships translatable strings through a `.pot`. |

Not carried over from the source bundle: `tools/smoke.php`, `tools/xref.php` and `tools/phpmailer-compat.php` (hardcoded to the SMTP plugin's autoloader, provider registry and PHPMailer subclass — nothing here to point them at) and `reference/` (an audit of the plugin SMTP was rebuilt from).

> `settings.local.json` (if present) is the harness's machine-local permission cache — git-ignored, NOT part of this bundle. Don't copy it.

## Hard rule: NO SECRETS in this folder

This repo is public on GitHub. **Never** put licence keys, the Google Chat webhook URL, or any credential here. Secrets stay in the machine-local, git-ignored `~/.claude/memory/` and are sourced at run time only. This bundle is the *non-secret* half of the standards.

> Source of truth: these are repo-local copies of the JezPress standards. The authoritative originals live in `~/.claude/memory/` (user-level) and the `wp-plugin-template` repo. Keep this bundle in sync when the standards evolve — and when you sync, re-check that nothing licence-related creeps back in.
