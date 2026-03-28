# AICliAgents Plugin Code Review Report

**Date**: March 2026  
**Reviewer**: Code Review Analysis  
**Version**: 1.0

---

## Executive Summary

The AICliAgents plugin is a complex multi-agent CLI terminal manager for Unraid. While functional, it has significant standards non-compliance, architectural complexity issues, and several anti-patterns that should be addressed. Below is a detailed analysis with recommended changes.

---

## 1. Standards Non-Compliance

### 1.1 File Naming Convention Violations

| Current | Expected | Impact |
|---------|----------|--------|
| `AICliAgentsManager.php` | `AICliAgentsSettings.php` | Inconsistent with other plugins (Copilot, Zram) |
| `AICliAgents.page` | `UnraidAICliAgents.page` | Deviation from `[PluginName].page` standard |
| `AICliAgentsManager.page` | `UnraidAICliAgentsSettings.page` | Same issue |

**Recommendation**: Rename files to match plugin naming conventions.

### 1.2 Dashboard Styling Violations

**Location**: `AICliAgentsManager.page` lines 14-180, 188-290

**Issues**:
- Uses `<div class="aicli-card">` instead of `<table class="dashboard"><tbody>` structure
- Custom CSS cards don't follow Unraid's `tile-header` structure
- Uses Grid/Flexbox inside cards (acceptable) but ignores the overall dashboard structure

**Expected Structure** (per DASHBOARD_STYLE_GUIDE.md):
```php
<table class="dashboard">
  <tbody title="<?= _("Plugin Title") ?>">
    <tr>
      <td>
        <span class='tile-header'>
          <span class='tile-header-left'>
            <i class="icon-my-plugin f32"></i>
            <div class="section">
              <h3 class="tile-header-main"><?= _("PLUGIN TITLE") ?></h3>
            </div>
          </span>
        </span>
      </td>
    </tr>
  </tbody>
</table>
```

### 1.3 Security (Compliant)

- ✓ Secrets stored in `secrets.cfg` with `chmod 0600`
- ✓ CSRF validation implemented in AJAX handlers

---

## 2. Unnecessary Complexity

### 2.1 Over-Engineered Scrollbar Suppression

**Location**: `AICliAgents.page` lines 34-219

The page contains 185 lines of "scorched earth" scrollbar suppression code:
- Nuclear CSS resets with `!important`
- Interval-based scrollbar killing (`setInterval(killScroll, 1000)`)
- Multiple resize event triggers
- Reach into iframe to inject styles

**Issue**: This is fragile and may break with Unraid updates. The terminal should handle its own scrollbars.

**Recommendation**: Remove all scrollbar suppression from the page file. Let ttyd/xterm handle terminal scrolling internally.

### 2.2 Dual Build System

- **ui-build/**: Full Vite + React + TypeScript + Tailwind build
- **src/assets/ui/**: Pre-built static assets

**Issue**: The build system is complex for what is essentially a terminal wrapper. Consider:
1. Using the terminal directly without React for simpler maintenance
2. Or documenting the build process clearly

### 2.3 Multiple State Management Layers

The plugin maintains state in **5 different locations**:

| Layer | Storage | Purpose |
|-------|---------|---------|
| Backend | `/var/run/aicliterm-*.sock` | Terminal socket |
| Backend | `/var/run/unraid-aicliagents-*.pid` | Process ID |
| Backend | `/var/run/unraid-aicliagents-*.chatid` | Chat session |
| Backend | `/var/run/unraid-aicliagents-*.agentid` | Agent ID |
| Frontend | localStorage | Session tabs, active ID |

**Recommendation**: Consolidate state management. Use a single state file with JSON instead of multiple files.

---

## 3. Client/Server Syncing Issues

### 3.1 Race Conditions in Terminal Startup

**Location**: `AICliAgentsManager.php` lines 296-406

```php
// Line 353-359
if (isAICliRunning($id, $chatSessionId, $agentId) && file_exists($sock)) {
    flock($fp, LOCK_UN);
    fclose($fp);
    return;  // Returns without verifying
}

stopAICliTerminal($id, false);  // Then stops it
```

**Issues**:
1. Checks if running, then stops - but doesn't re-verify after stop
2. No atomic lock acquisition
3. `flock()` is used but the lock file path is hardcoded

### 3.2 Polling-Based Sync (Anti-Pattern)

**Location**: `AICliAgentsTerminal.tsx` lines 141-169

```typescript
const timer = setInterval(poll, 4000);  // Polls every 4 seconds
```

**Problems**:
- 4-second delay between server state changes and UI updates
- Circuit breaker logic adds complexity to hide the underlying issue
- Multiple `useEffect` dependencies create complex re-render patterns

### 3.3 Stale Closure Issues

**Location**: `AICliAgentsTerminal.tsx` line 224

```typescript
}, [activeId, !!config, Object.keys(registry).length, sessions.find(s => s.id === activeId)?.chatSessionId, sessions.find(s => s.id === activeId)?.agentId]);
```

The dependency array uses function results which can cause unexpected behavior. The code attempts to work around this with `lastStartedKey` and `lastSuccessfulStartKey` refs, but this is a band-aid.

---

## 4. Anti-Patterns

### 4.1 Inline CSS in PHP Page

**Location**: `AICliAgentsManager.page` lines 14-180

All styling is embedded directly in the page file. Should be in a separate `.css` file.

### 4.2 Dangerous Use of `eval` in Shell Script

**Location**: `aicli-shell.sh` lines 105, 118, 121, 125

```bash
eval "$FINAL_CMD"
eval "$frozen_binary"
```

**Risk**: If `$FINAL_CMD` or `$frozen_binary` is compromised, arbitrary code execution is possible.

**Recommendation**: Use array-based command execution or validate the binary path before execution.

### 4.3 Duplicate Agent Registry

The agent registry is defined in **3 locations**:
1. `AICliAgentsManager.php` lines 212-285
2. `AICliAgentsTerminal.tsx` lines 19-26
3. `aicli-shell.sh` (hardcoded logic)

**Issue**: Updates require changes in multiple places.

### 4.4 Monolithic Functions

**Location**: `installAgent()` in `AICliAgentsManager.php` (lines 488-615)

This function is 127 lines long with multiple responsibilities:
- Version resolution
- Download
- NPM installation
- Caching
- Deployment

**Recommendation**: Break into smaller functions:
- `resolveAgentVersion()`
- `downloadAgent()`
- `installNpmAgent()`
- `cacheAgent()`
- `deployAgent()`

### 4.5 Magic Numbers Throughout

| Location | Magic Number | Should Be |
|----------|--------------|-----------|
| Line 524 | `v0.31.0` | Configurable default |
| Line 215 | `2000` | Named constant |
| Line 558 | `800` | Named constant |
| Line 82 | `10000` | Named constant |
| Line 166 | `4000` | Named constant |

---

## 5. Specific Code Issues

### 5.1 Path Traversal Risk

**Location**: `AICliAgentsManager.php` line 793-802

```php
} elseif ($_GET['action'] === 'list_dir') {
    $path = $_GET['path'] ?? '/mnt';
    if (!is_dir($path)) { echo json_encode(['error' => 'Not a directory']); exit; }
```

While there are some checks, the path validation could be more robust.

### 5.2 Inconsistent Error Handling

- Some functions return `['status' => 'error', 'error' => 'message']`
- Some throw exceptions
- Some just log and continue

### 5.3 Global Error Handler Override

**Location**: `AICliAgentsManager.php` lines 7-14

```php
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    aicli_debug("PHP ERROR [$errno]: $errstr in $errfile on line $errline");
    return false;
});
```

This overrides Unraid's error handler which may break other plugins.

---

## 6. Recommended Refactoring (Smaller Model Implementation)

For a smaller model to implement, here are the high-impact changes:

### Priority 1: Standards Compliance (Quick Wins)

```php
// Rename AICliAgentsManager.php -> AICliAgentsSettings.php
// Rename function: saveAICliConfig() -> saveAICliAgentsSettings()
// Update all require_once references
```

### Priority 2: Reduce Complexity

1. **Remove scrollbar suppression code** - Delete lines 34-219 in AICliAgents.page
2. **Consolidate state files** - Use single JSON file instead of multiple flag files

### Priority 3: Improve Security

1. **Replace eval with array execution**:
```bash
# Before
eval "$binary --flag"

# After  
cmd=($binary --flag)
"${cmd[@]}"
```

### Priority 4: Fix Syncing

1. **Reduce polling interval** from 4s to 2s
2. **Add WebSocket support** for real-time updates (future enhancement)

---

## 7. Suggested Code Changes

### 7.1 Rename Backend File (Priority 1)

**File**: `src/includes/AICliAgentsManager.php`
```php
// Rename to: src/includes/AICliAgentsSettings.php
// Update function names:
// - saveAICliConfig() -> saveAICliAgentsSettings()
// - getAICliConfig() -> getAICliAgentsConfig()
// - getAICliAgentsRegistry() -> getAICliAgentsRegistry()
// - etc.
```

### 7.2 Replace eval with Safe Execution (Priority 3)

**File**: `src/scripts/aicli-shell.sh`

```bash
# Before (lines 105, 118, 121, 125)
eval "$FINAL_CMD"
eval "$frozen_binary"

# After - using arrays
cmd_args=()
if [ -n "$frozen_chat_id" ] && [ "$frozen_chat_id" != "none" ]; then
    # Replace {chatId} placeholder
    cmd_string="${frozen_resume_cmd//\{chatId\}/$frozen_chat_id}"
    cmd_args=($cmd_string)
else
    cmd_args=($frozen_binary)
fi

# Execute safely
"${cmd_args[@]}"
```

### 7.3 Consolidate State Files (Priority 2)

**File**: `src/includes/AICliAgentsSettings.php`

```php
function getAICliSessionState($id = 'default') {
    $stateFile = "/var/run/aicli-state-$id.json";
    if (file_exists($stateFile)) {
        return json_decode(file_get_contents($stateFile), true);
    }
    return [
        'socket' => getAICliSock($id),
        'pid' => null,
        'chatId' => '',
        'agentId' => 'gemini-cli'
    ];
}

function saveAICliSessionState($id, $state) {
    $stateFile = "/var/run/aicli-state-$id.json";
    file_put_contents($stateFile, json_encode($state));
}
```

### 7.4 Add Constants for Magic Numbers (Priority 2)

**File**: `src/includes/AICliAgentsSettings.php`

```php
// At top of file
define('AICLI_DEFAULT_GEMINI_VERSION', 'v0.31.0');
define('AICLI_POLL_INTERVAL_MS', 4000);
define('AICLI_STARTUP_TIMEOUT_MS', 2000);
define('AICLI_REGISTRY_POLL_INTERVAL_MS', 10000);
define('AICLI_CONTEXT_BUFFER', 2000);
```

---

## Summary

| Category | Issues Found | Severity |
|----------|-------------|----------|
| Standards Non-Compliance | 6 | Medium |
| Unnecessary Complexity | 8 | High |
| Syncing Issues | 4 | High |
| Anti-Patterns | 9 | Medium |
| Security | 2 | Low |

**Total: 29 identified issues**

The most impactful changes for a smaller model implementation would be:
1. Rename files for standards compliance
2. Remove the scrollbar suppression code
3. Replace eval with safe command execution
4. Add constants for magic numbers
