# Implement: terminal file links open a standalone editor tab

Read this entire file, then read `AGENTS.md` and `docs/specs/TMUX_PATH_LINKS.md`
before changing code. This is an implementation task for AICliAgents, with a
small, explicit interoperability change required in the sibling
`unraid-plg-file_editor_extentions` plugin.

## Outcome

When a user clicks a detected file path in an AICliAgents terminal (workspace
terminal or overlay terminal), open a new Browse tab. In that tab:

1. If **File Editor Extensions** is installed *and supports that file*, launch
   its standalone, full-page editor. Closing the editor closes that tab.
2. Otherwise, retain the current native Unraid `fileEdit()` / viewer behaviour.

The terminal click must remain safe for files outside File Editor Extensions'
language catalog. Do not make the terminal feature depend on that plugin being
installed.

## Current implementation to preserve

The existing feature is already wired under #40:

- path detection and deep-link construction:
  `ui-build/src/lib/pathLinks.ts`
- terminal click handler and iframe bridge lifecycle:
  `ui-build/src/components/AICliAgentsTerminal.tsx`
- native Browse loader contract:
  `src/AICliOpenFile.page`
- existing specification and tests:
  `docs/specs/TMUX_PATH_LINKS.md`,
  `ui-build/src/lib/__tests__/pathLinks.test.ts`, and associated PHP/E2E tests.

Do not regress relative-path resolution, server-side `check_path` validation,
terminal link disposal/reinstall behaviour, or the native fallback.

## Contract to implement

Continue opening a same-origin `/Shares/Browse?dir=...` tab from the terminal,
but use a fragment with both values:

```text
#aicli_edit=<encoded absolute path>&aicli_standalone=1
```

The fragment avoids path logging and keeps the normal Browse `dir` query
unchanged. Build it with `URLSearchParams`; do not concatenate unescaped paths.

`AICliOpenFile.page` owns the handoff. It should:

1. Decode and validate the fragment's `aicli_edit` value only as an input to
   the already-validated browsing flow. Do not introduce a write endpoint or
   bypass `check_path`.
2. If `aicli_standalone=1`, wait briefly for
   `window.FileEditorExtensions?.openStandaloneFile` (poll at 100–250 ms for
   at most 2 seconds).
3. Call `openStandaloneFile(path)` when it becomes available.
   - `true` means File Editor Extensions accepted the file: clear the AICli
     fragment and stop. It owns the UI and its close control calls
     `window.close()`.
   - `false` means the file is not in its supported catalog: continue to the
     existing native `fileEdit('name_N')` route.
4. If the API never appears, continue to the existing native route. This is
   the normal outcome when File Editor Extensions is not installed.
5. Never invoke both editors for the same link. Once one route accepts the
   file, cancel all loaders/timers and clear the fragment.

Do **not** attempt to detect another plugin from the terminal's parent page.
The target Browse tab is the correct place to negotiate, because it has the
actual plugin scripts loaded and can preserve the native fallback.

## Required File Editor Extensions interoperability patch

Coordinate the following small public API addition in the sibling repository:

`/mnt/user/DevelopmentProjects/unraid-extensions/unraid-plg-file_editor_extentions`

```ts
window.FileEditorExtensions.openStandaloneFile(path: string): boolean
```

Requirements:

- Return `false` synchronously when `path` is not supported by that plugin's
  `supportsPath()` / CodeMirror language catalog.
- Return `true` and open `FileEditorModal` directly in full-page standalone
  mode when supported.
- In this API path, the full-page close control and Escape (after the usual
  unsaved-change confirmation) call `window.close()`; do **not** navigate to
  the Browse list as a fallback.
- Preserve the existing ordinary `openFile(path)` overlay behaviour and the
  File Editor Extensions own `#feex_*` standalone URL behaviour.
- Extend its `Window.FileEditorExtensions` TypeScript declaration and add
  focused tests for accepted and rejected extensions.

Do not make AICliAgents import the other plugin's code. The global method is
the compatibility boundary; it must be feature-detected.

## UX requirements

- The terminal link itself has no extra menu or confirmation for an existing
  file; it opens one new tab as it does today.
- A missing file, directory, disallowed path, or failed `check_path` retains
  the existing toast and opens no tab.
- The native fallback is intentional for any extension unsupported by File
  Editor Extensions—even if its terminal link was recognised.
- The standalone editor should use the full page immediately; do not show the
  normal modal first and require the user to click a header button.
- Chrome may choose a new tab instead of a separate window. That is acceptable
  and enables Ctrl+Tab; a web page cannot force a separate browser instance.

## Tests and verification

Add or update tests before declaring the work complete:

1. **Vitest (`pathLinks`)**
   - URL has the encoded `aicli_edit` and `aicli_standalone=1` fragment.
   - Existing path candidate detection and relative/absolute resolution remain
     unchanged.
2. **Frontend loader tests** (extract a small pure helper if necessary)
   - enhanced API accepts → native `fileEdit` is not called;
   - API rejects → native `fileEdit` is called;
   - API absent/times out → native `fileEdit` is called;
   - no duplicate activation after timer completion.
3. **File Editor Extensions tests**
   - supported JS/PHP/Markdown path → standalone mode;
   - unsupported extension → returns `false` without rendering;
   - standalone close calls `window.close()` only after unsaved-change guard.
4. **Manual browser verification**
   - `echo docs/specs/foo.md` in a terminal opens a full-page standalone
     editor tab when both plugins are installed;
   - closing it closes that tab and returns focus to the terminal tab;
   - `echo unsupported.xyz` follows the native Unraid fallback if it is a
     recognised AICli path-link extension but not enhanced-editor-supported;
   - uninstall/disable File Editor Extensions and repeat: native editor still
     opens;
   - verify main terminal and overlay terminal.

Run the repository's required lint, unit, build, and release-gate stages.
Update `docs/specs/TMUX_PATH_LINKS.md` to make this the canonical runtime
contract and add a short changelog entry that describes the user-visible
behaviour rather than the implementation detail.

