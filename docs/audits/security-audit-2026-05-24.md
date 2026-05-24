# Security Audit — 2026-05-24

## Summary

**Findings: 3 findings (0 critical, 1 high, 2 medium)**

The plugin demonstrates mature security practices overall: CSRF tokens are validated, shell escaping is consistently applied via `escapeshellarg`, path traversal is guarded, and secrets are protected with 0600 permissions. However, three issues were identified that require remediation.

---

## Findings

### [HIGH] Unverified TLS in wget/curl downloads (installer/runtime.sh)
**Where:** `src/scripts/installer/runtime.sh:34, 77, 107, 160`  
**Class:** TLS verification / integrity validation  
**Attack scenario:**  
An attacker on the local network (or with control of DNS/DHCP) intercepts downloads of Node.js, tmux, fd, ripgrep, and squashfs-tools during plugin installation. The attacker substitutes malicious binaries, and they execute as root during the install phase. Subsequent agent processes inherit the compromised runtime.

**Impact:**  
Remote code execution as root during plugin installation. Attacker can backdoor the entire plugin, agents, and potentially the Unraid host.

**Suggested fix:**  
1. Compute and hardcode SHA256 hashes for each downloaded binary (Node.js v22.22.0, tmux v3.6a, fd v10.3.0, ripgrep 14.1.0, squashfs-tools 4.5).
2. After download, verify: `sha256sum "$CONFIG_DIR/$BINARY" | awk '{print $1}'` matches the expected hash.
3. If hash mismatches, log error, refuse to proceed, and exit 1.
4. Wrap each download in `set -e` or explicit exit checks.
5. Example for Node.js (add to runtime.sh after wget):
```bash
NODE_HASH_EXPECTED="<sha256 of node-v22.22.0-linux-x64.tar.gz>"
NODE_HASH_ACTUAL=$(sha256sum "$CONFIG_DIR/$NODE_TAR" | awk '{print $1}')
if [ "$NODE_HASH_ACTUAL" != "$NODE_HASH_EXPECTED" ]; then
    log_fail "Node.js checksum mismatch. Download may be corrupted or tampered."
    rm -f "$CONFIG_DIR/$NODE_TAR"
    exit 1
fi
```

**Confidence:** High  
**Why:** The script downloads binaries from public CDNs without integrity verification. While the URLs are HTTPS (mitigating active MITM in most cases), a sophisticated attacker with network position or a DNS/DHCP redirect can serve malicious binaries. No integrity check is present on any download.

---

### [MEDIUM] Manifest URL not verified for HTTPS in WP #963 probeManifestVersion
**Where:** `src/includes/services/sources/CurlInstallSource.php:189`  
**Class:** TLS verification  
**Attack scenario:**  
An attacker controls the Unraid network or DNS. When Antigravity CLI (or another agent with a `manifest_url` field) is installed or updated, `probeManifestVersion()` fetches `source.manifest_url` (e.g., the vendor's update manifest). If the URL is HTTP (not HTTPS), or if curl is invoked with weak TLS settings, the attacker intercepts the request and serves a fake manifest claiming a newer version is available. The user clicks Upgrade, triggering a download from the attacker's URL.

**Impact:**  
Remote code execution if the attacker can serve a malicious agent binary and trick the user into upgrading.

**Suggested fix:**  
1. Validate that `manifest_url` and all install-source URLs begin with `https://` before use.
2. Add to CurlInstallSource::fetch and probeManifestVersion:
```php
if (!preg_match('#^https://#i', $scriptUrl) || !preg_match('#^https://#i', $manifestUrl)) {
    LogService::log("CurlInstallSource: insecure URL protocol (HTTP not allowed): $scriptUrl", LogService::LOG_ERROR);
    return false;
}
```
3. Do the same for GithubReleaseSource::apiGet (line 220) and all download URLs in TarballSource.
4. All vendor fetch URLs should require HTTPS at validation time, not at curl time.

**Confidence:** Medium  
**Why:** The threat requires network position (local network attacker or compromised DNS). The existing curl `-fsSL` flag does enforce HTTPS by default, but the code does not *validate* that the configured URL is HTTPS before passing it to curl. If a user or vendor misconfigures a non-HTTPS URL, the plugin silently allows it. A defence-in-depth check would catch this.

---

### [MEDIUM] Missing TLS verification override check in curl commands
**Where:** `src/includes/services/sources/GithubReleaseSource.php:237, CurlInstallSource.php:64, TarballSource.php:41`  
**Class:** TLS verification  
**Attack scenario:**  
The curl commands used for downloading agents and vendor scripts do not explicitly set `CURLOPT_SSL_VERIFYPEER=true` or `--cacert`, relying on curl's default behaviour. On a system with a missing or stale CA certificate bundle, curl might silently fall back or skip verification. An attacker with network position could intercept the download.

**Impact:**  
Remote code execution if an attacker can serve a malicious agent binary due to weak TLS verification.

**Suggested fix:**  
1. Explicitly add `--cacert /etc/ssl/certs/ca-certificates.crt` (or the Unraid system CA path) to every curl invocation.
2. Or use `-C -` (continue if file exists) and add an integrity check post-download (see HIGH finding above).
3. Example (GithubReleaseSource.php line 237):
```php
private function download(string $url, string $dest): bool {
    $cmd = 'curl -fL --cacert /etc/ssl/certs/ca-certificates.crt -m 300 -o ' . escapeshellarg($dest) . ' ' . escapeshellarg($url) . ' 2>&1';
    $out = @shell_exec($cmd);
    // ... rest of function
}
```

**Confidence:** Medium  
**Why:** curl's default behaviour is generally secure, but explicitly pinning the CA bundle defends against system misconfiguration. The absence of an explicit `--cacert` flag means the code relies on environment defaults, which can vary.

---

## Mitigations Already Present (No Findings)

The following security controls are well-implemented and require no changes:

1. **CSRF Protection** (AICliAjax.php:53-68): CSRF tokens are validated on every AJAX action via Unraid's standard mechanism.
2. **Path Traversal Guards** (ValidationService.php): User-supplied paths are validated against `ALLOWED_PATH_BASES` whitelist and resolved via `realpath()`.
3. **Secrets at Rest** (SecretService.php:138-160): Secret files are created with 0600 permissions; the vault directory is 0700. Enforced via `chmod()` after atomic writes.
4. **Shell Escaping** (handlers/*.php, InstallerService.php): All shell commands use `escapeshellarg()` for user-controlled arguments.
5. **SSH Key Handling** (SshKeyService.php): Public keys are stored safely; private keys are never persisted. The sidecar tracks fingerprints, not secrets.
6. **Secret Logging** (LifecycleLogService.php, LogService.php): No evidence of logging secret values or sensitive env vars.
7. **Environment Variables** (aicli-shell.sh:300-316): Env vars are injected via PHP and escaped with `escapeshellarg()`, preventing code injection.
8. **Symlink Safety** (SshKeyService.php:79): Temporary files for `ssh-keygen` use `uniqid()` with randomness, not predictable names.
9. **DBus Session Bus** (secret-service-up.sh:54-61): Serialization via `flock` prevents concurrent bring-up races; the session bus is private to the user (unix socket at a fixed path under `/tmp/unraid-aicliagents/`).
10. **Vendor Script Timeout** (CurlInstallSource.php:83): Vendor install scripts are wrapped with `timeout 300`, preventing hung handoff attacks.
11. **NPM Postinstall Execution** (NpmSource.php): npm is invoked with `--no-audit --no-fund`, and postinstall scripts are not explicitly disabled but are run as part of the standard npm install flow. This is the intended behaviour for agent installation.

---

## Out of Scope (Not Reported)

- Race conditions (separate audit per project instructions).
- Code quality, performance, or style issues.
- Non-security-related bugs already tracked in CLAUDE.md or project WPs.
- Known mitigations for specific issues (e.g., Bug #1054, WP #966, WP #1078).
