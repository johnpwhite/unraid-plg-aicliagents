#!/usr/bin/env php
<?php
/**
 * Config-Hub git clean/smudge filter — keeps resolved secrets OUT of the repo while the
 * working tree stays usable.
 *
 *   clean  (on `git add` / stage):   secret VALUE  ->  {KEY} placeholder   (committed blob is sanitized)
 *   smudge (on checkout / restore):  {KEY}         ->  secret VALUE         (working file is resolved)
 *
 * Wired as a REQUIRED filter (git config filter.aicli-secrets.required=true) so ANY failure
 * here ABORTS the git operation — git must never fall back to the raw, secret-bearing blob.
 * Reads the file content from stdin, writes the transformed content to stdout.
 *
 * Usage (from .git/config, set by GitHomeService): php git-secret-filter.php clean|smudge
 */
declare(strict_types=1);

$mode = $argv[1] ?? '';
if ($mode !== 'clean' && $mode !== 'smudge') {
    fwrite(STDERR, "git-secret-filter: mode must be 'clean' or 'smudge'\n");
    exit(2);
}

$content = stream_get_contents(STDIN);
if ($content === false) {
    fwrite(STDERR, "git-secret-filter: failed to read stdin\n");
    exit(3);
}

// Load the secrets vault. Any failure exits NON-ZERO so the required filter aborts the
// commit/checkout rather than risk emitting an unsanitized (secret-bearing) blob.
// Path is relative to THIS script (src/scripts/user) so it resolves both deployed and in
// the CI runner — NOT a hardcoded /usr/local/emhttp path.
$manager = dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';
if (!is_file($manager)) {
    fwrite(STDERR, "git-secret-filter: plugin manager not found\n");
    exit(4);
}
require_once $manager;
try {
    $secrets = \AICliAgents\Services\SecretService::getAgentSecrets();
} catch (\Throwable $e) {
    fwrite(STDERR, "git-secret-filter: secrets vault unavailable\n");
    exit(5);
}
if (!is_array($secrets)) {
    fwrite(STDERR, "git-secret-filter: secrets vault unreadable\n");
    exit(6);
}

// Transform lives in GitHomeService (unit-tested); this script is just the git-side shim.
fwrite(STDOUT, \AICliAgents\Services\Hub\GitHomeService::applySecretFilter($mode, $content, $secrets));
exit(0);
