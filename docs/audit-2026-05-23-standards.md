# Project Audit — 2026-05-23

Audits `unraid-plg-aicliagents` against the two standards in
`C:\Users\john\Dev Projects\claude-skills_management\docs\project-audit-prompt.md`.

Standards reference docs read:
- `unraid-extensions/.claude/commands/unraid-testing.md`
- `~/.claude/agents/factory-ui-reviewer.md`
- `~/.claude/agents/ui-visual-reviewer.md`
- `~/.claude/skills/ui-visual-reviewer/SKILL.md`

This plugin runs **Path B** (complex TypeScript Playwright harness) per Standard 1 —
the playbook still ships a `tests/ui_playbook.json` (Path A capability) but the
canonical L4 harness is `ui-build/tests/e2e/specs/l4-playbook.spec.ts` driven by
`tests/run-l4.sh`.

---

## Standard 1 — UI testing migration

### 1.1 `tests/visual_review.sh` — **BLOCKING** (workspace-level)

Plugin-side shim is correct after this session's edits — comment cites the new skill, dispatches to `.claude/skills/unraid-testing/shared/visual_review.sh`. **But that target directory does not exist locally** (`ls .claude/skills/unraid-testing/shared/` returns "No such file or directory"). The actual shared script is at `.gemini/skills/unraid-testing/shared/visual_review.sh` AND it still calls Gemini CLI directly:

```
.gemini/skills/unraid-testing/shared/visual_review.sh:44   MODEL="${GEMINI_MODEL:-gemini-3-flash-preview}"
.gemini/skills/unraid-testing/shared/visual_review.sh:45   FALLBACK_MODEL="${GEMINI_FALLBACK:-gemini-2.5-flash}"
.gemini/skills/unraid-testing/shared/visual_review.sh:56   if ! command -v gemini >/dev/null 2>&1; then
.gemini/skills/unraid-testing/shared/visual_review.sh:102  raw=$(timeout "$TIMEOUT_S" gemini -m "$MODEL" ...)
```

**Plugin-level: not blocking** (shim already correct). **Workspace-level: blocking** for any plugin that invokes `tests/visual_review.sh` — the shared script needs to either be migrated to native-vision (no SDK calls) OR a `.claude/skills/unraid-testing/shared/` mirror must be created.

The other-agent migration the user mentioned earlier appears to be in flight for this exact item.

### 1.2 `tests/run-l4.sh` — **COMPLIANT**

- Flag is `--skip-visual` (not `--skip-gemini`). ✓
- Anthropic SDK gate removed in v.08 audit fixes. ✓
- Comment cites the `ui-visual-reviewer` skill. ✓

### 1.3 `.gemini/skills/unraid-testing/` references in plugin code

Present in:

| File | Line | Why |
|---|---|---|
| `tests/regress.sh` | 157-158 | Intentional fallback added v.09 — phpstan.phar lookup tries `.claude` then `.gemini` so pre-publish regress survives the in-flight migration. |
| `docs/specs/STATIC_ANALYSIS_GATING.md` | 9, 33 | Historical documentation reference to the bootstrap template path. |

**Cosmetic.** The fallback is intentional back-compat. Docs are historical.

### 1.4 `tests/visual_prompt.txt` — **COMPLIANT**

- No "Gemini" in body. ✓
- Header references `.claude/skills/unraid-testing/shared/visual_prompt.txt` (per the audit you commissioned earlier this session). ✓

### 1.5 `tests/ui_playbook.json` — **COMPLIANT**

Created in v.08 with 10 steps covering Settings tab, Manager page (PHP-fatal check), Store-tab cards, Envs/Channel/Runtime/Terminal chips, Tasks tab, Storage-tab rogue-id check, and final render. Drives the Docker `ui-playwright-runner` runner per Path A.

### 1.6 Visual review pre-publish vs post-publish — **COMPLIANT (with caveat)**

`publish-and-deploy.php` runs L4 AFTER deploy (Step 5, line 326+), as Standard 1 requires:

```
publish-and-deploy.php:54-57   exit codes
    33   pre-publish L1/L2 regress (tests/regress.sh --skip-ssh) reported failure
    34   post-deploy L3 smoke suite (tests/smoke.sh) reported a non-zero assertion
    35   post-deploy L4 visual harness (tests/run-l4.sh) reported a DOM failure
```

L4 step at line 326+ runs strictly after Step 4 deploy. ✓

**Caveat (workspace-level, not a blocker for this plugin):**

```
publish-and-deploy.php:337   fwrite(STDOUT, "l4: running tests/run-l4.sh --skip-gemini\n");
publish-and-deploy.php:344   $l4Cmd = ['bash', 'tests/run-l4.sh', '--skip-gemini'];
```

The publish wrapper still passes `--skip-gemini`, but plugin `tests/run-l4.sh` now only recognises `--skip-visual`. The legacy flag is silently ignored → if the wrapper ever drops `--skip-l4` and the unrecognised `--skip-gemini` reaches the script, visual review would run during publish instead of being skipped. Currently inert because every publish in this session has been called with `--skip-l4`. **Workspace fix needed in `publish-and-deploy.php`** — change `'--skip-gemini'` → `'--skip-visual'`.

---

## Standard 2 — Heavy compute → Unraid Docker

### 2.1 Local Dockerfiles — **NONE FOUND** ✓

No `Dockerfile` anywhere in the plugin (`Glob **/Dockerfile` returned no results).

### 2.2 Local npm / composer / phpunit — **ADVISORY**

| Where | Tool | Approx duration | Path B status |
|---|---|---|---|
| `tests/regress.sh` L1.5 | PHPStan via local `php.exe + phpstan.phar` | ~5–10s | OK locally (Path B harness, fast) |
| `tests/regress.sh` L1.6 | ESLint via local node_modules | ~5–10s | OK locally |
| `tests/regress.sh` L2 | PHPUnit local | ~2s | OK locally |
| `tests/run-l4.sh` | `npx playwright test` local | ~30–120s | **Local by Path B design** — Standard 1 explicitly says "Path B runs locally via `bash tests/run-l4.sh`" |

**Advisory only.** The user already paused the broader Docker-on-Unraid migration this session ("I have another agent working on removing gemini and making the skill, let's stop the work here for now, and just get the plugin working ready for storefront publish"). The work is parked.

### 2.3 `run-l4.sh` Playwright LOCALLY — **COMPLIANT WITH PATH B**

Path B explicitly allows local Playwright execution (`Standard 1 — Path B: TypeScript Playwright harness in ui-build/tests/e2e/, runs locally via bash tests/run-l4.sh --skip-visual`). Visual review handled post-publish by `factory-ui-reviewer`. ✓

### 2.4 `docker build` commands — **NONE FOUND** ✓

Grep `docker build|docker run` matched only:
- `secret-service-src/localtest.sh` — local dev test of the in-plugin Secret Service daemon (not the ui-playwright-runner image; not a build step).
- `src/scripts/installer/runtime.sh` — plugin install runtime, not a Docker build.

No `docker build ui-playwright-runner` anywhere — that image is built ONCE on Unraid per the skill. ✓

### 2.5 `~/.claude/skills/ui-visual-reviewer/SKILL.md` references in docs — **MIXED**

Files referencing `ui-visual-reviewer` or `factory-ui-reviewer`:

| File | Reference quality |
|---|---|
| `tests/run-l4.sh` | ✓ Updated v.08 |
| `tests/visual_review.sh` | ✓ Updated v.08 |
| `tests/ui_playbook.json` | ✓ Created v.08 referencing the new skill |
| `tests/ui_e2e_playbook.md` | ✓ Updated v.08 ("Claude visual sidecar — native vision via the agent's Read tool") |
| `tests/regress.sh` | ✓ Updated v.08 (L4 help text now points at `ui-visual-reviewer` agent + `tests/ui_playbook.json`) |
| `ui-build/tests/e2e/specs/l4-playbook.spec.ts` | Unchecked — Playwright spec; references probably legacy. |
| `unraid-aicliagents.plg` | Unchecked — likely legacy "factory-ui-reviewer" reference in changelog/notes. |
| `docs/specs/ATOMIC_WRITES.md` | Historical doc. |
| `docs/plans/2026-04-28-channel-dropdown-filtering.md` | Historical plan. |

Plugin-level docs that gate behaviour are all updated. Historical docs / plans / changelog text retain legacy "factory-ui-reviewer" naming — **cosmetic only**.

---

## Prioritised fix list

### Must fix (blocks standard compliance)

1. **Workspace `.gemini/skills/unraid-testing/shared/visual_review.sh` still calls Gemini CLI** — Standard 1 §1.1. Either migrate the shared script to native vision (no `gemini`/SDK calls) OR create a `.claude/skills/unraid-testing/shared/` mirror that does. Plugin-level shim is already correctly pointing at the new path; it just resolves to a missing file. **Owned by the in-flight migration agent the user mentioned, not this session.**

### Should fix (advisory, next session)

2. **`publish-and-deploy.php` passes `--skip-gemini` to `run-l4.sh`** — Standard 1 §1.6 caveat. Script silently ignores the unrecognised flag. Workspace-level fix in `.gemini/skills/unraid-factory/scripts/publish-and-deploy.php` lines 337 + 344: rename `--skip-gemini` → `--skip-visual`. Currently inert because every publish uses `--skip-l4`, but a latent foot-gun.

3. **Stale references in legacy docs** — `unraid-aicliagents.plg`, `docs/specs/ATOMIC_WRITES.md`, `docs/plans/2026-04-28-channel-dropdown-filtering.md`, `ui-build/tests/e2e/specs/l4-playbook.spec.ts` — likely contain "factory-ui-reviewer" or "Gemini" wording in non-load-bearing prose. Cosmetic-only; can be deferred until each file is touched for other reasons.

### Already compliant (confirmed)

4. `tests/run-l4.sh` flag naming (`--skip-visual`), anthropic SDK gate removed.
5. `tests/ui_playbook.json` exists with Docker-runner-compatible Path A schema.
6. `tests/visual_prompt.txt` no Gemini references in body.
7. `tests/regress.sh` PHPSTAN_PHAR lookup has the .gemini→.claude fallback.
8. Visual review timing (post-publish via `publish-and-deploy.php` Step 5 / exit code 35).
9. No local `Dockerfile`. No `docker build` of the runner image in any script.
10. `tests/visual_review.sh` plugin-level shim correctly dispatches to the new shared path.
11. L4 harness runs locally per Path B design — compliant with Standard 1's two-track model for this plugin.

---

## Net summary

- **1 must-fix** (workspace shared `visual_review.sh` still uses Gemini — out of this plugin's scope, in-flight elsewhere).
- **2 should-fix** (workspace `publish-and-deploy.php` flag mismatch, stale doc wording).
- **11 already compliant** items spanning both standards.

Plugin-level compliance with the new standards is **complete** as far as in-repo files go. The only Standard 1 gap that affects this plugin at runtime is the missing `.claude/skills/unraid-testing/shared/` mirror — which is workspace-level, not plugin-level.
