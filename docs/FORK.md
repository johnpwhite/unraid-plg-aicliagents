# Fork policy — SeraphimSerapis / johnpwhite

## Source of truth

| Remote | Role |
|--------|------|
| `upstream` → `johnpwhite/unraid-plg-aicliagents` | Upstream / Community Applications storefront |
| `origin` → `SeraphimSerapis/unraid-plg-aicliagents` | Working fork for feature branches and PRs |

As of the fork baseline (`7c999e8` / `v2026.07.30.02`), **origin/main and upstream/main are identical**. The public GitHub tree is a **release mirror**: no `tests/`, `docs/specs/`, or `.github/` CI. Those live on the author's private Forgejo/factory workspace (see `HANDOVER.md`). Do not assume this checkout has the full regression harness.

## pluginURL / CA isolation

- **PRs aimed at upstream** must keep PLG `pluginURL`, raw GitHub download URLs, and `GIT_URL` pointing at `johnpwhite/unraid-plg-aicliagents`. Changing them would break CA installs for everyone if merged.
- **Private/local installs from this fork** (olympus, etc.) must temporarily retarget those URLs to `SeraphimSerapis/unraid-plg-aicliagents` (or a release tag) *outside* the upstream PR, or install via a local `.plg` / `plugin install` from a copied file — never publish a fork PLG that still claims the johnpwhite pluginURL while serving different payload bytes.

## Branch workflow

1. Cut feature work from `main` (synced to `upstream/main`).
2. Open the PR against `johnpwhite/unraid-plg-aicliagents` from this fork.
3. After merge, sync `origin/main` from upstream; rebuild `src.tar.gz` only when shipping a storefront release.

## Recovering tests/specs

If you need the internal suite, obtain it from the upstream author / Forgejo (`docs/specs/`, `tests/unit/*.sh`). Until then, verify changes with PHP lint, shell `bash -n`, and live olympus smokes.
