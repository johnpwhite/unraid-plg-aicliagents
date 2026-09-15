# Prompt: implement event-first reconciliation

Paste the section below into a fresh session started in
`/mnt/user/DevelopmentProjects/unraid-extensions/unraid-plg-aicliagents`.

---

Implement the event-first reconciliation work for this plugin, phase by phase. Forgejo epic #179 (children #180–#194) tracks it; reference the issue in each publish message and close each child when its phase ships.

Read these first, in this order:
1. `CLAUDE.md` (project) and `../CLAUDE.md` (workspace). Note the shfs/FUSE rules and the token-discipline section.
2. `docs/plans/2026-09-11-event-first-reconciliation.md` — the plan, the decisions already taken, the assumed defaults, the constraints, and the ordered steps. Treat "Decisions already taken" as settled.
3. `docs/reviews/EVENT_ARCHITECTURE_REVIEW.md` — the review the plan comes from. Sections 1, 2, 3, 5a, 6 and 9 are the ones you will cite.
4. The Draft specs the plan lists. Each spec must be moved from Draft to Approved by the operator before you write code for it; ask once per spec, then proceed.

Scope for this session: **Phase 0**, then **Phase 1a**, then **Phase 1b** in the plan's order. Do not start Phase 2. Do not add any push to agents. Do not put plugin events in the Relay.

How to work:
- Spec-first. Before each step, update the governing spec (status, requirements ticked, design detail) and only then edit code. Publish messages end with `Spec: docs/specs/<...>.md`.
- Start with `git status` and `git branch --show-current`. If files you need to touch are already modified by another session, stop and report which; do not edit them.
- Before changing any literal, command shape, channel name or log string: `grep -rn "<old>" tests/` and update every hit in the same change. The plan lists the known guard files.
- Iterate with `../ci/run guards` and `../ci/run unit --php-filter=<Class>` / vitest. Never run two `ci/run` for this plugin at once; check `../ci/status --plugin=aicliagents` first. Run `release-gate` only when the box is free and before a functional publish.
- `ui-build/src/components/AICliAgentsTerminal.tsx` is CRLF: read with `tr -d '\r'`, edit with the Edit tool.
- After `src/**` or `ui-build/**` changes, run the C4 drift check in the CI container and refresh the docs it names.
- Verify on 192.168.1.4 only. Never mutate Tower.
- Publish each phase as its own version through the factory-publisher flow. Phase 0 is `Fix:`; Phase 1a is `Feat:` (the events tools); Phase 1b is `Improve:`/`Fix:`.
- Where a step finds a defect the plan does not list, fix it in the same phase if it is in the files you are already changing, otherwise file it in Forgejo (repo `unraid/unraid-plg-aicliagents`) and note it in the phase's publish message.

Acceptance for the whole session, checked manually on two devices (desktop and phone) against `.4`:
1. Press Consolidate on the Manager with a terminal tab open on the other device: the terminal tab does not reload.
2. Close a workspace on one device: it leaves the drawer on the other device without a reload.
3. Create a workspace with `aicli_create_workspace` from an agent: it appears in both drawers; `aicli_get_events` on that agent, after `aicli_subscribe_events(["workspace.*"])`, returns `workspace.created` with the agent as actor, and `workspace.removed` with `human` and the device label as actor after you close it.
4. Deliver a Relay message that was held: the pill clears on both devices; dismiss a pill on one device: it clears on the other.
5. With no browser open, a fake stalled op (see `tests/smoke/activity.sh`) becomes `stalled` within a minute.
6. Deploy a new generation: both devices show the countdown and reconnect every terminal with one reload each.
7. Health shows `push: ok`; rename the nginx socket away for a minute and it shows `push: warn`.

Report at the end: what shipped per phase (version numbers), what was verified and how, what was left out and why, and the Forgejo issues filed.
