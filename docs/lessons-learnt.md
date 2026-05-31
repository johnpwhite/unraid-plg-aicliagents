# Lessons Learnt — unraid-plg-aicliagents

---

## 2026-05-28 — Manual deploy workaround when .4 cannot reach factory GitLab

**Context:** The test server at 192.168.1.4 cannot authenticate to the private GitLab at 192.168.1.38 for raw file downloads. `plugin install` gets HTML redirect pages (10580-byte sign-in pages) instead of actual scripts, causing "Modular Engine execution failed".

**Key discovery:** Unraid's `plugin` command (dynamix.plugin.manager) at line 420-421: *"If file already exists, do not overwrite"* — when no `<MD5>` or `<SHA256>` is specified in a PLG `<FILE>` entry, the download is skipped if the file already exists at the destination path. This means you can pre-stage real files at `/tmp/aicli-*.sh` before running `plugin install` and it will use them.

**Deploy recipe for when publish-and-deploy.php is unavailable:**
1. Delete stale HTML files: `ssh root@192.168.1.4 "rm -f /tmp/aicli-*.sh /tmp/aicli-src.tar.gz /tmp/aicli-stop-warning.page"`
2. SCP all installer scripts to their expected paths (see PLG `<FILE Name="/tmp/aicli-...">` entries for the mapping)
3. SCP the PLG: `scp unraid-aicliagents.plg root@192.168.1.4:/tmp/plugins/`
4. Run: `ssh root@192.168.1.4 "plugin install /tmp/plugins/unraid-aicliagents.plg"`

**Also:** `tests/` is not in `src.tar.gz`. For L3 smoke to pass, SCP the tests dir:
```
scp -r tests/ root@192.168.1.4:/usr/local/emhttp/plugins/unraid-aicliagents/tests/
```
Note: run `mkdir -p .../tests/` on the server FIRST, then SCP, to avoid nested `tests/tests/` structure.

---

## 2026-05-28 — `publish-to-github.sh` is a broken stub (missing commit + no URL transforms)

**Problem:** The `.sh` script in `.gemini/skills/unraid-storefront/scripts/` is missing:
1. `git add .` + `git commit -m "Official Release v..."` between `git checkout master -- .` and `git push` on the deploy-github branch
2. URL transformations (GitLab → GitHub raw URLs)
3. PLG entity resolution (DOCTYPE strip, `&pluginURL;` → literal GitHub URL, etc.)

The script reports "SUCCESS" because `git push` silently force-pushes the OLD commit (no-op) and exits 0.

**Workaround:** Run the Python transform script (`~/.claude/jobs/c3511db4/transform-plg.py`) to produce the public PLG, then manually do the git operations on deploy-github:
```bash
git checkout deploy-github
git rm -rf .
git checkout master -- .
# remove exclusions (.gemini/, ui-build/, CLAUDE.md, etc.) + install transformed PLG
python3 /path/to/transform-plg.py unraid-aicliagents.plg CHANGES.public.xml /tmp/public.plg
cp /tmp/public.plg unraid-aicliagents.plg
git add .
git commit -m "Official Release v<VERSION>"
git push public deploy-github:main --force
git checkout master
```
The transform script is saved at `/Users/johnwhite/.claude/jobs/c3511db4/transform-plg.py` but job dirs are ephemeral — copy it somewhere permanent if needed again.

---

## 2026-05-28 — macOS sed quoting mangling in publish-to-github.sh VERSION extraction

The `.sh` publish script uses `sed -n 's/.*"\([^"]*\)".*/\1/p'` on a line like `<!ENTITY version "2026.05.24.03">`. On macOS BSD sed, this produces the correct value. However, the script then does:
```bash
VERSION=$(grep 'ENTITY version' unraid-aicliagents.plg | sed ... | tr -d ' ')
```
When the command substitution is nested, bash on macOS produces `'2026.05.24.03\n' | tr -d '` in the variable, mangling the commit message. This is a bash/sed quoting compatibility issue specific to macOS. The grep+sed pipeline needs to be replaced with a more robust extraction.

---

## 2026-05-28 — CA index does not need updating on each release

The `unraid-community-applications-index/unraid-aicliagents.xml` just contains static metadata including the PLG URL (which points to `main`). It does NOT contain a version number. CA reads the PLG at `pluginURL` to find the current version. As long as the PLG on GitHub `main` has the new version, CA will see it on the next refresh cycle. The `update-index.ps1` script is only needed if the metadata (description, icon, category, etc.) changes — not for routine version bumps.

---

## 2026-05-27 — Antigravity CLI (agy) glog falls back to stderr when log dir absent

When `agy`'s log directory (`$HOME_DIR/.gemini/antigravity-cli/log/`) doesn't exist, Go's `glog` library writes to stderr — the same TTY as the TUI. This corrupts the display with messages like `I0527 22:02:xx.xxxxxx experiment_manager.go:39] ...`. Pre-create the dir in `aicli-shell.sh` per-loop-iteration (same block as `.cache`/`.config`/`.local`). Fixed in WP #1227, v2026.05.24.03.

---

## 2026-05-27 — OverlayFS upper/ deletion: writes silently fail, reads still work

When the `upper/` directory is deleted while an overlayfs mount is still live (kernel holds an orphaned inode), the overlay appears healthy — reads from lower layers still work — but ALL writes fail with ENOENT. This is invisible in `mount`, `df`, `findmnt` output. The only symptom is writes failing.

**Diagnosis:** `touch <merged-path>/.test` returns ENOENT while `ls` on the same path succeeds.

**Recovery:** 
1. Kill sessions using the overlay
2. `umount -l <merged-path>`
3. `mkdir -p <upper-path>/upper`
4. PHP bridge `init <entity> true` to remount cleanly

**Root cause (WP #1224):** `selective_upper_cleanup()` in `common.sh` used `find -type d -empty -delete` without `-mindepth 1`, so it deleted `upper/` itself. Fixed with `find "$upper" -mindepth 1 -type d -empty -delete`.

---

## 2026-05-27 — Factory publish script entity/attribute split-brain (WP #1226)

`publish-factory.php` updates `<PLUGIN version="...">` but NOT `<!ENTITY version "...">` in the PLG DOCTYPE. `consolidate-changelog.ps1` reads the ENTITY to determine what version has shipped; the mismatch means the PLG's src.tar.gz tarball URL in FILE entries still pointed at the old version tarball. Users who upgraded got old code silently. Manual workaround: always bump BOTH the ENTITY declaration (line ~5) AND the PLUGIN attribute (line ~12) before regenerating the tarball. **Fix needed in `publish-factory.php`** (WP #1226).
