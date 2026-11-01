# Updater contract — jezpress-manager (free, unlicensed)

The updater **silently fails open**: a broken one returns no error, shows no notice, and simply never injects an update into `update_plugins`. WP admin just says "no updates" forever. So it gets verified against the live server, not reasoned about.

> **No licence anywhere in this plugin.** `jezpress-manager` is free — it never sends a `license_key`, never calls `/api/v1/license/*`, and has no licence class, option or cron. The licensed-plugin rule ("`license_key` goes in the URL query param, never a Bearer header") exists in the wider JezPress standard and is **not applicable here**. If you are porting code in from a licensed plugin, strip the licence path rather than wiring a key into this updater.

## What this plugin actually calls

| Endpoint | Method | Auth | Used for |
|---|---|---|---|
| `GET /api/v1/info?plugin=jezpress-manager` | GET | **none** | version, `requires_wp`, `tested_wp`, `requires_php`, `sections`, icons/banners |
| `GET /api/v1/download?plugin=jezpress-manager&version=X.Y.Z` | GET | none | the package URL handed to WordPress as `package` |
| `* /api/dev/plugins/*` (publishing side, never from PHP) | varies | `Authorization: Bearer $(cat ~/.jezpress-token)` — a JWT, not a licence | release-time dashboard patches |

Implementation: `JezPress_Manager_Updater::get_remote_info()` in [includes/class-jezpress-manager-updater.php](../includes/class-jezpress-manager-updater.php). It caches the response in the `jezpress_manager_update_info` transient for **12 hours** and returns `false` on `is_wp_error()`, a non-200, or `success !== true`.

## Verification (run before declaring updater work done)

```bash
curl -s "https://updates.jezpress.com/api/v1/info?plugin=jezpress-manager" | python3 -m json.tool
```

Expect `success: true` and a `version` equal to the release you just shipped. Then, in WP admin, force a real check — the 12-hour transient is the usual reason a correct release looks broken:

```bash
wp transient delete jezpress_manager_update_info
wp plugin list --name=jezpress-manager --fields=name,version,update
```

Failure modes:

- **`success: false` / HTTP 404** → the slug is wrong, or the version was never uploaded to the `stable` channel. Check `jezpress plugins get jezpress-manager`.
- **`version` older than what you just tagged** → the CI upload step didn't run or failed. `JEZPRESS_TOKEN` is set on this repo, so `release.yml` uploads on tag push; check the workflow run before re-uploading by hand.
- **Endpoint fine, WP still shows no update** → the transient is warm (delete it), or the site's installed version is already ≥ remote. Confirm the header `Version:`, `JEZPRESS_MANAGER_VERSION` and `readme.txt` `Stable tag:` all carry the new number — `release.yml` gates on exactly those three.
- **Update offered but the download 404s** → the `version` the info endpoint reports has no artefact on the download endpoint; re-upload that exact version.
- **Wrong folder name on disk** → the slug comes from `plugin_basename( dirname( $plugin_file ) )`. Installed in a folder other than `jezpress-manager`, the updater asks the server about a plugin that doesn't exist. The release ZIP's top-level folder must stay `jezpress-manager`.

## Metadata drift worth checking each release

`--sync-metadata` writes top-level fields from the plugin header, and they have drifted before. After a release, compare what the server serves against the header:

```bash
curl -s "https://updates.jezpress.com/api/v1/info?plugin=jezpress-manager" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print({k:d.get(k) for k in ('version','requires_wp','tested_wp','requires_php')})"
```

As of 2026-09-21 the server reports `requires_php: 8.0` while the plugin header declares **8.1** — the plugin's own bootstrap gate is what actually enforces it, but the served value is what WordPress shows users. Fix with `jezpress plugins update jezpress-manager --requires-php 8.1` (and `--tested-wp X.Y`) when it drifts.

## Bring-up checklist
1. Header `Version:`, `JEZPRESS_MANAGER_VERSION`, `readme.txt` `Stable tag:` all match.
2. No `license_key`, no Bearer header, on any `/api/v1/*` call from PHP.
3. After the release workflow finishes, run the info curl → confirm the new `version`.
4. In WP admin: delete the transient → "Check for updates" → confirm the banner appears.
