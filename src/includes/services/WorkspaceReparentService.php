<?php
/**
 * <module_context>
 *     <name>WorkspaceReparentService</name>
 *     <description>A person promotes a sub-agent (helper) workspace to a top-level workspace, or moves a top-level workspace under a parent. The tie is the record fields `createdBy`, `spawned` and `spawn` (`parent`, `parent_name`), plus the saved `commandAllow` rules. Spec: docs/specs/WORKSPACE_REPARENT.md (issue #951).</description>
 *     <dependencies>ConfigService (updateParentTie, the only writer of the tie), SpawnService (isHelper, newSpawnMeta, findRecord, notify, parentNotifier), CommandApprovalService (endPendingForTarget), ArgsService, AgentLaunchProfile, AgentRelayService, EventLedger</dependencies>
 *     <constraints>Reached only by the AJAX actions workspace_promote and workspace_move_under (Manager UI, POST with CSRF). No MCP tool and no CLI verb calls this class. Every rule is checked inside the workspaces lock, on the record as it is stored now, so a second tab that acts at once is refused with the reason. A refusal changes nothing. Never touches the running session, the worktree folder or the branch.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

final class WorkspaceReparentService
{
    /** @var callable|null fn(): int. Replaces time(). */
    public static $now = null;

    private static function now(): int
    {
        return self::$now !== null ? (int)(self::$now)() : time();
    }

    /** True when the record has a parent: it is a sub-agent, or another workspace created it. */
    public static function hasParent(array $record): bool
    {
        if (SpawnService::isHelper($record)) return true;
        $by = (string)($record['createdBy'] ?? '');
        return $by !== '' && $by !== 'human';
    }

    /** The id of the parent, or '' when there is none. */
    public static function parentIdOf(array $record): string
    {
        $p = (string)($record['spawn']['parent'] ?? '');
        if ($p !== '') return $p;
        $by = (string)($record['createdBy'] ?? '');
        return ($by !== '' && $by !== 'human') ? $by : '';
    }

    private static function isScriptRun(array $record): bool
    {
        $by = (string)($record['createdBy'] ?? '');
        return (string)($record['spawn']['run']['runId'] ?? '') !== '' || is_array($record['spawn']['run'] ?? null) || strpos($by, 'run:') === 0;
    }

    /** The sign-in workspace of the run user (spawn.runUserSignIn). A person controls it; it is not a normal sub-agent. */
    private static function isSignIn(array $record): bool
    {
        return !empty($record['spawn']['runUserSignIn']);
    }

    /**
     * The names of the workspaces that have this one as their parent.
     *
     * @param list<array<string,mixed>> $all
     * @return list<string>
     */
    public static function ownHelperNames(string $id, array $all): array
    {
        $names = [];
        foreach ($all as $w) {
            if (!is_array($w) || (string)($w['id'] ?? '') === $id) continue;
            if (self::parentIdOf($w) === $id) $names[] = (string)($w['name'] ?? $w['id'] ?? '');
        }
        return $names;
    }

    /** R11: the reason a promote is refused, or null. @param array<string,mixed> $record */
    public static function promoteRefusal(array $record): ?string
    {
        $name = (string)($record['name'] ?? $record['id'] ?? 'This workspace');
        if (!self::hasParent($record)) return "'$name' is a top-level workspace already. There is nothing to promote.";
        if (self::isScriptRun($record)) return "'$name' was started by a script run, so a person cannot make it a top-level workspace. Close it when the run ends.";
        if (self::isSignIn($record)) return "'$name' is a sign-in workspace of the run user, so it cannot be made a top-level workspace. Close it when the sign-in is done.";
        return null;
    }

    /**
     * R10: the reason a move is refused, or null.
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed>|null $target
     * @param list<array<string,mixed>> $all
     */
    public static function moveRefusal(array $record, ?array $target, array $all): ?string
    {
        $id = (string)($record['id'] ?? '');
        $name = (string)($record['name'] ?? $id);
        if (self::isScriptRun($record)) return "'$name' was started by a script run, so it cannot be moved under a parent.";
        if (self::isSignIn($record)) return "'$name' is a sign-in workspace of the run user, so it cannot be moved under a parent.";
        if (self::hasParent($record)) return "'$name' is a sub-agent already. Make it a top-level workspace first.";
        $own = self::ownHelperNames($id, $all);
        if ($own !== []) {
            return "'$name' has sub-agents of its own (" . implode(', ', array_slice($own, 0, 3)) . (count($own) > 3 ? ' and more' : '') . '). Close them or make them top-level first.';
        }
        if ($target === null) return 'The workspace to move under does not exist.';
        $tid = (string)($target['id'] ?? '');
        $tname = (string)($target['name'] ?? $tid);
        if ($tid === $id) return "A workspace cannot be its own parent.";
        if (self::hasParent($target)) return "'$tname' is a sub-agent, and a sub-agent cannot have sub-agents. Choose a top-level workspace.";
        return null;
    }

    // ======================================================================
    // Promote (R4 to R7, R11)
    // ======================================================================

    /**
     * Make a sub-agent a top-level workspace. The session keeps running. Returns ['status'=>'ok', ...] or ['error'=>...].
     *
     * @return array<string,mixed>
     */
    public static function promote(string $id): array
    {
        $id = trim($id);
        $refusal = null;
        $before = null;
        $all = [];
        $ok = ConfigService::updateParentTie($id, static function (array $record, array $stored) use ($id, &$refusal, &$before, &$all): ?array {
            $refusal = self::promoteRefusal($record);
            if ($refusal === null && ConfigService::isWorkspaceRemoved($id)) $refusal = "'" . (string)($record['name'] ?? $id) . "' is being closed, so its parent cannot change now.";
            if ($refusal !== null) return null;
            $before = $record;
            $all = $stored;
            $next = $record;
            // R6: the worktree data lives in `spawn`. Removing `spawn` removes the `tool_made` mark, so no clean-up path touches the folder.
            unset($next['spawned'], $next['spawn'], $next['commandAllow']);
            $next['createdBy'] = 'human';
            return $next;
        });
        if (!$ok || $before === null) {
            return ['error' => $refusal ?? self::failureText($id)];
        }
        $name = (string)($before['name'] ?? $id);
        $oldParentId = self::parentIdOf($before);
        $oldParentName = self::nameOf($oldParentId, $all, (string)($before['spawn']['parent_name'] ?? ''));
        $wt = is_array($before['spawn']['worktree'] ?? null) ? $before['spawn']['worktree'] : null;

        // R5: nothing from the old parent may be typed any more.
        $ended = CommandApprovalService::endPendingForTarget($id, '');
        // R7: the next start of this workspace asks for permission again. The running session is not changed.
        $flagsState = self::removeUnattendedFlags($id, (string)($before['agentId'] ?? ''));
        $flagsRemoved = $flagsState === 'removed';

        $worktreeNote = $wt !== null
            ? ' Its git worktree ' . (string)($wt['path'] ?? '') . ' and branch ' . (string)($wt['branch'] ?? '') . ' are kept. The plugin does not remove them any more.'
            : '';
        self::event('promote', $id, $name, $oldParentId, '', $before);
        self::note($oldParentId, $id, "A person made '$name' a top-level workspace. You are no longer its parent. These end for you: the parent tag on your text, direct commands with saved rules, closing it without approval, its time limit and its report to you. From now on you are like any other agent toward a top-level workspace.");
        if ($oldParentId !== '') {
            self::note($id, $oldParentId, "A person made you a top-level workspace. '$oldParentName' is no longer your parent. You have no time limit or auto-close any more, and your report goes to no parent.$worktreeNote");
        }
        SpawnService::notify("Made top-level: $name", "A person made it a top-level workspace" . ($oldParentName !== '' ? " (it was a sub-agent of $oldParentName)" : '')
            . ($flagsRemoved ? ' · the unattended flags are removed from its saved arguments' : '') . ($flagsState === 'failed' ? ' · the unattended flags could NOT be removed from its saved arguments: check Session settings' : '') . ($ended > 0 ? " · $ended command request(s) ended" : '') . ($wt !== null ? ' · the worktree is kept' : ''), $id);
        return ['status' => 'ok', 'action' => 'promote', 'id' => $id, 'name' => $name, 'oldParentId' => $oldParentId, 'oldParentName' => $oldParentName,
            'endedRequests' => $ended, 'unattendedFlagsRemoved' => $flagsRemoved, 'unattendedFlagsState' => $flagsState, 'worktreeKept' => $wt !== null];
    }

    // ======================================================================
    // Move under a parent (R8 to R10)
    // ======================================================================

    /** @return array<string,mixed> */
    public static function moveUnder(string $id, string $parentId): array
    {
        $id = trim($id);
        $parentId = trim($parentId);
        $refusal = null;
        $before = null;
        $parent = null;
        $ok = ConfigService::updateParentTie($id, static function (array $record, array $stored) use ($id, $parentId, &$refusal, &$before, &$parent): ?array {
            $target = null;
            foreach ($stored as $w) if (is_array($w) && $parentId !== '' && (string)($w['id'] ?? '') === $parentId) { $target = $w; break; }
            $refusal = self::moveRefusal($record, $target, $stored);
            if ($refusal === null && ConfigService::isWorkspaceRemoved($id)) $refusal = "'" . (string)($record['name'] ?? $id) . "' is being closed, so its parent cannot change now.";
            if ($refusal === null && ConfigService::isWorkspaceRemoved($parentId)) $refusal = "'" . (string)($target['name'] ?? $parentId) . "' is being closed, so nothing can move under it now.";
            if ($refusal !== null) return null;
            $before = $record;
            $parent = $target;
            $parentName = (string)($target['name'] ?? $parentId);
            $next = $record;
            $meta = SpawnService::newSpawnMeta($parentId, $parentName, '', false, false, null, '', '', 0);
            $meta['state'] = 'running';
            $meta['moved_at'] = self::now();
            $next['createdBy'] = $parentId;
            $next['spawned'] = true;
            $next['spawn'] = $meta;
            unset($next['commandAllow']);
            return $next;
        });
        if (!$ok || $before === null || $parent === null) {
            return ['error' => $refusal ?? self::failureText($id)];
        }
        $name = (string)($before['name'] ?? $id);
        $parentName = (string)($parent['name'] ?? $parentId);
        // R5: a request from anyone but the new parent cannot be typed.
        $ended = CommandApprovalService::endPendingForTarget($id, $parentId);
        self::event('move', $id, $name, '', $parentId, $before);
        self::note($parentId, $id, "A person moved '$name' under you. You are its parent now. You can send it text with the parent tag, send it commands (a person still allows each one), answer its permission prompts and close it without approval. It has no task title, no time limit and no auto-close.");
        self::note($id, $parentId, "A person moved you under '$parentName'. '$parentName' is your parent now. It can send you text with the parent tag. Your work and your session do not change.");
        SpawnService::notify("Moved under $parentName: $name", 'A person moved it under a parent' . ($ended > 0 ? " · $ended command request(s) ended" : ''), $id);
        return ['status' => 'ok', 'action' => 'move', 'id' => $id, 'name' => $name, 'parentId' => $parentId, 'parentName' => $parentName, 'endedRequests' => $ended];
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private static function failureText(string $id): string
    {
        $msg = ConfigService::lastWorkspaceSaveMessage();
        if ($msg !== null && $msg !== '') return $msg;
        if (SpawnService::findRecord($id) === null) return "No workspace with id '$id' was found. It may be closed already.";
        return 'The change could not be saved. Nothing was changed. Try again in a moment.';
    }

    /** @param list<array<string,mixed>> $all */
    private static function nameOf(string $id, array $all, string $fallback): string
    {
        foreach ($all as $w) if (is_array($w) && (string)($w['id'] ?? '') === $id) return (string)($w['name'] ?? $id);
        return $fallback !== '' ? $fallback : $id;
    }

    /**
     * R7: take the unattended flags of the agent's launch profile out of the saved arguments of this workspace.
     * The arguments are read like a shell line: a word in quotes is never a flag. Only whole unquoted words that match
     * the profile flags are cut (also the `--name value` form of a `--name=value` flag). Every other byte stays.
     *
     * @return string 'removed', 'none' (nothing to remove) or 'failed' (the file could not be read or written)
     */
    private static function removeUnattendedFlags(string $id, string $agentId): string
    {
        try {
            if ($agentId === '' || !ArgsService::hasIdArgs($id)) return 'none';
            $profile = AgentLaunchProfile::profileFor($agentId);
            $flags = is_array($profile) ? ($profile['unattended']['flags'] ?? null) : null;
            if (!is_string($flags) || trim($flags) === '') return 'none';
            $args = ArgsService::getIdArgs($id);
            $new = self::cutFlagWords($args, $flags);
            if ($new === $args) return 'none';
            return ArgsService::saveIdArgs($id, $new) ? 'removed' : 'failed';
        } catch (\Throwable $e) {
            return 'failed';
        }
    }

    /**
     * Cut the words of $flags from $args. Pure. Quote aware: single quotes, double quotes and backslash.
     * @internal public for the unit test only
     */
    public static function cutFlagWords(string $args, string $flags): string
    {
        $drop = preg_split('/\s+/', trim($flags)) ?: [];
        if ($drop === [] || $drop[0] === '') return $args;
        $alts = [$drop];
        if (count($drop) === 1 && strpos($drop[0], '=') !== false) {
            [$name, $value] = explode('=', $drop[0], 2);
            $alts[] = [$name, $value];
        }
        // Tokens: [start, end, plain]. "plain" means no quote and no backslash inside the word.
        $tokens = [];
        $len = strlen($args);
        for ($i = 0; $i < $len; ) {
            if (ctype_space($args[$i])) { $i++; continue; }
            $start = $i;
            $plain = true;
            while ($i < $len && !ctype_space($args[$i])) {
                $c = $args[$i];
                if ($c === '\\') { $plain = false; $i += 2; continue; }
                if ($c === "'" || $c === '"') {
                    $plain = false;
                    $q = $c;
                    $i++;
                    while ($i < $len && $args[$i] !== $q) { if ($q === '"' && $args[$i] === '\\') $i++; $i++; }
                    $i++;
                    continue;
                }
                $i++;
            }
            $tokens[] = [$start, min($i, $len), $plain];
        }
        $ranges = [];
        for ($t = 0; $t < count($tokens); ) {
            $hit = 0;
            foreach ($alts as $alt) {
                $n = count($alt);
                if ($t + $n > count($tokens)) continue;
                $ok = true;
                for ($k = 0; $k < $n; $k++) {
                    [$s0, $e0, $plain] = $tokens[$t + $k];
                    if (!$plain || substr($args, $s0, $e0 - $s0) !== $alt[$k]) { $ok = false; break; }
                }
                if ($ok) { $hit = $n; break; }
            }
            if ($hit > 0) { $ranges[] = [$tokens[$t][0], $tokens[$t + $hit - 1][1]]; $t += $hit; continue; }
            $t++;
        }
        if ($ranges === []) return $args;
        $out = '';
        $pos = 0;
        foreach ($ranges as [$from, $to]) {
            $out .= substr($args, $pos, $from - $pos);
            $pos = $to;
            while ($pos < $len && ctype_space($args[$pos])) $pos++;
        }
        $out .= substr($args, $pos);
        return $pos >= $len ? rtrim($out, " \t") : $out;
    }
    /** R12: one Relay note. Best effort; the event and the tray row are the record. */
    private static function note(string $recipient, string $sender, string $text): void
    {
        if ($recipient === '' || $sender === '' || $recipient === $sender) return;
        try {
            if (SpawnService::$parentNotifier !== null) (SpawnService::$parentNotifier)($sender, $recipient, $text);
            else AgentRelayService::directMessage($sender, $recipient, $text);
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /** @param array<string,mixed> $before */
    private static function event(string $action, string $id, string $name, string $oldParentId, string $newParentId, array $before): void
    {
        if (!class_exists('\\AICliAgents\\Services\\EventLedger')) return;
        try {
            $summary = EventLedger::summaryFor('workspace.reparented', ['action' => $action, 'name' => $name]);
            EventLedger::append('workspace.reparented', ['workspaceId' => $id, 'agentId' => (string)($before['agentId'] ?? '')], $summary, [
                'action' => $action, 'name' => $name, 'oldParent' => $oldParentId, 'newParent' => $newParentId, 'by' => 'person',
            ]);
        } catch (\Throwable $e) {
            // Best effort.
        }
    }
}
