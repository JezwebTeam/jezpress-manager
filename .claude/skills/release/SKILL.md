---
name: release
description: Run the full JezPress release pipeline for this plugin (jezpress-manager) — version bump, mandatory pre-release audit, commit, tag, push, GitHub release workflow (which also uploads to JezPress), per-version changelog, dashboard Description/Changelog tab patches, LEDGER append, live updater verification, and the Google Chat announcement. Use when the user says "release X.Y.Z", "ship X.Y.Z", or "roll X.Y.Z". Pass the target version (and optionally a one-line summary) as the argument.
argument-hint: X.Y.Z [one-line changelog summary]
---

# Release pipeline — jezpress-manager

Target version: **$ARGUMENTS** (first token = `X.Y.Z`; remainder, if any, = changelog hint).

Run these in order. This skill runs in the main session, so you keep full context. The `ask`-gated steps (`curl`/`wget`, `gh repo`, destructive git) prompt for approval — that's intended. `git push`, `gh release` and `jezpress` auto-approve.

Facts for this plugin:
- Slug: `jezpress-manager` · GitHub: `JezwebTeam/jezpress-manager` · Version const: `JEZPRESS_MANAGER_VERSION`
- **Free plugin — no licence.** No licence key, no `/api/v1/license/*`, nothing to activate. Step 11 verifies the updater through the unauthenticated `/api/v1/info` endpoint.
- Token: `~/.jezpress-token` (Bearer JWT for the publishing API, never commit).
- `JEZPRESS_TOKEN` **is set** on this repo, so `release.yml` runs preflight + upload itself on tag push. Step 7 is a verification, not an upload — only upload by hand if CI's upload step failed.
- `GCHAT_WEBHOOK_URL` is deliberately **unset** on the repo, so CI never announces; the card is posted from here (step 12).
- Audit details: `.claude/audit-guide.md`. Google Chat card recipe: the `## Google Chat Notifications` section in this plugin's `CLAUDE.md`.

## 1. Bump the version in all 3 places
`release.yml` gates on exactly these and fails the build if any disagrees:
1. `jezpress-manager.php` plugin header `Version:`
2. `jezpress-manager.php` `define( 'JEZPRESS_MANAGER_VERSION', '…' )`
3. `readme.txt` `Stable tag:`

Also update the `**Version:**` line in `CLAUDE.md`, and regenerate `languages/jezpress-manager.pot` (`php .claude/tools/make-pot.php`) **if** the plugin ships one by then — it does not today.

Verify: `grep -rn "<previous version>" jezpress-manager.php readme.txt CLAUDE.md` returns nothing version-related.

## 2. Update readme.txt
- Add a new `= X.Y.Z =` block under `== Changelog ==` (newest first).
- Add a matching `= X.Y.Z =` entry under `== Upgrade Notice ==`.
- Copy is grounded in the actual work done / the summary argument. **Don't fabricate.**

## 3. Pre-release audit (MANDATORY gate)
Dispatch the `jezpress-code-reviewer` subagent on the release diff (`git diff <prev tag>..HEAD` or the changed files). Apply every CRITICAL/HIGH finding, then re-audit if the fix was material. Do not tag with unresolved Criticals. See `.claude/audit-guide.md`.

Cheap checks worth running first so the agent spends its budget elsewhere:
```bash
php -l jezpress-manager.php && php -l includes/class-jezpress-manager.php && php -l includes/class-jezpress-manager-updater.php
php .claude/tools/php-compat.php   # parses every shipped file on the DECLARED minimum (8.1), not your local PHP
```

## 4. Commit
Write the message to a file (avoids shell-quoting mangling), then commit:
```bash
# write .git/COMMITMSG.txt (subject + body), ending with the Co-Authored-By line
git commit -F .git/COMMITMSG.txt
rm -f .git/COMMITMSG.txt
```

## 5. Tag + push (auto-approves)
```bash
git tag vX.Y.Z
git push origin main
git push origin vX.Y.Z
```

## 6. Watch the release workflow
```bash
gh run list --workflow=release.yml --limit 1 --json databaseId,status -q '.[0]'
gh run watch <id> --exit-status
```
It verifies the three version strings, builds `jezpress-manager-X.Y.Z.zip` (excluding `.git`, `.github`, `.claude`, `CLAUDE.md`, `PLAN.md`, `README.md`), creates the GitHub release, then runs `jezpress plugins preflight` + `upload`. A version-mismatch failure here means step 1 was incomplete.

## 7. Confirm the JezPress upload
```bash
jezpress plugins get jezpress-manager
```
Expect the new version on the `stable` channel. Only if CI's upload step was skipped or failed:
```bash
mkdir -p /tmp/rel<XYZ>
gh release download vX.Y.Z --repo JezwebTeam/jezpress-manager --dir /tmp/rel<XYZ> --clobber
jezpress plugins upload jezpress-manager /tmp/rel<XYZ>/jezpress-manager-X.Y.Z.zip --ver X.Y.Z --channel stable --sync-metadata
```
`--sync-metadata` writes `Tested up to:` from the plugin header; the header has no such line today, so follow with `jezpress plugins update jezpress-manager --tested-wp X.Y --requires-php 8.1` rather than letting it fall back.

## 8. Per-version changelog (CLI)
```bash
jezpress plugins changelog jezpress-manager X.Y.Z "<plain text from the = X.Y.Z = readme block>"
```
**Use ASCII punctuation** — straight quotes `'` and hyphens `-`, NOT em-dashes (—) or curly quotes. They pass through the shell mangled and show as mojibake (`â€"`) in the WP "View details" popup. Never run this in parallel for two versions; the writes clobber each other.

## 9. Dashboard Description + Changelog tabs (raw API)
The dashboard reads `plugin.sections.description` / `plugin.sections.changelog` — separate fields from the per-version changelog, so they go via the raw API. Build both from `readme.txt`:
- **Description** = the `== Description ==` block converted to HTML (`<p>`, `<h3>`, `<ul>/<li>`, `<strong>`, `<code>`).
- **Changelog** = ALL versions, newest-first, as plain text `Version X.Y.Z` headers with `  - bullet` lines.

Write **ONE** temp `.json` file containing BOTH fields (`ensure_ascii=True` so the payload is ASCII-only). Body shape — `sections` carries **both** keys:

```json
{"description":"<html>","changelog":"<text>","sections":{"description":"<html>","changelog":"<text>"}}
```

Then PATCH **once**:

> **Do NOT send two sequential single-field PATCHes.** The server **replaces the whole `plugin.sections` object** on every write, so a changelog-only PATCH wipes the description a prior description-only PATCH set, and vice versa. Reads are also cache-laggy, so a GET straight after a single-field PATCH can show the *other* field as 0 chars and look like a failed write when it actually clobbered.

```bash
curl -s -X PATCH -H "Authorization: Bearer $(cat ~/.jezpress-token)" -H "Content-Type: application/json" \
  --data-binary @<file> "https://updates.jezpress.com/api/dev/plugins/jezpress-manager" -o /dev/null -w "HTTP %{http_code}\n"
```
Delete the temp file after; verify both `sections` lengths are non-zero in a single GET.

## 10. Append to `.claude/LEDGER.md`
Add one row (newest first): version, date, audit verdict, one-line summary, and JezPress + dashboard status. The ledger is the durable per-plugin release history — append every release.

## 11. Live updater verification (MANDATORY)
No licence key is involved — this plugin is free:
```bash
curl -s "https://updates.jezpress.com/api/v1/info?plugin=jezpress-manager" | python3 -m json.tool
```
Expect `success: true` and `version: X.Y.Z`. If the version is stale, the upload didn't land (step 7). On a test site, `wp transient delete jezpress_manager_update_info` before checking for updates — the updater caches for 12 hours. See `.claude/updater-contract.md` for the other failure modes.

## 12. Announce on Google Chat
**This repo's `CLAUDE.md` requires per-release approval before posting** — ask, then post on a yes. (The user-level policy of 2026-06-17 auto-sends; the repo file is more specific and wins here. If Abner confirms the repo file is stale, update `CLAUDE.md` and this step together rather than quietly diverging.)

- Group by plugin + day: **threadKey** = `jezpress-manager-$(TZ='Australia/Sydney' date +%Y-%m-%d)`
- Append to the webhook URL: `&messageReplyOption=REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD`
- Include in the JSON body: `"thread": { "threadKey": "<threadKey>" }`
- Source the webhook from `~/.claude/memory/jezpress-google-chat-webhook.md` at run time — **never commit it**.
- Card copy from the `= X.Y.Z =` readme block (`<br>`-joined, ASCII punctuation); slug `jezpress-manager`, title `JezPress Manager`, repo `JezwebTeam/jezpress-manager`.

See the `## Google Chat Notifications` section in this plugin's `CLAUDE.md` for the cardsV2 curl. After posting, confirm HTTP 200 and report what shipped (with the updater-verify result).

> Note: `curl` is in the `ask` list, so the dashboard PATCH (step 9) and this Google Chat POST are the only steps that prompt during a release — by design, since `curl` can reach any URL. Everything else (push, gh release, jezpress upload) auto-approves.
