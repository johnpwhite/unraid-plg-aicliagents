<?php
/**
 * Real-time activity bridge — invoked by the supervisor (queue_helpers
 * job_ledger_write) immediately after a USER-initiated storage job's ledger
 * transition (running / deferred / done / failed).
 *
 * Why: storage-job state lives in the bash supervisor's ledger. The activity
 * tray only learned of transitions when IT polled list_activities (every 10 s),
 * so a job that ran (or completed) between polls left the pill frozen at the
 * initial "queued" event. Running syncJobActivities here pushes each transition
 * into the matching `storage_job_<id>` activity entry, which publishes to the
 * Nchan `aicli_activity` channel — so the tray's EventSource updates in REAL
 * TIME (queued → running → "waiting for sessions…" → done) instead of on the
 * next poll.
 *
 * Lives in a script file (not `php -r`) per the publish anti-pattern rule
 * (backslash namespaces in a double-quoted -r body are eaten by bash). Cheap:
 * one glob of the jobs dir + a handful of entry updates. Fault-isolated — never
 * throws into the supervisor (it is backgrounded with stderr/stdout discarded).
 */

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';

\AICliAgents\Services\SupervisorService::syncJobActivities();
