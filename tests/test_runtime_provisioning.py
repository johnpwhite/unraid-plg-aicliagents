"""Isolated fault tests; execute Bash from PATH (or set BASH).

No Unraid paths are written. The helper/gate snippets are read from source,
so tests exercise production control flow without storage/service side effects.
"""
import hashlib
import html
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = (ROOT / 'src/scripts/installer/runtime.sh').read_text()
ENGINE = (ROOT / 'src/scripts/installer/install-engine.sh').read_text()
BASH = os.environ.get('BASH', 'bash')


class RuntimeFaults(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.helpers = RUNTIME[RUNTIME.index('verify_sha256()'):RUNTIME.index('# --- 1. Node.js')]
        self.prefix = '''set -o pipefail
log_fail() { echo "FAIL: $*"; }
log_status() { echo "$*"; }
log_ok() { echo "$*"; }
log_warn() { echo "$*"; }
log_step() { echo "$*"; }
sleep() { :; }
RUNTIME_WORK="$PWD/work"
mkdir -p "$RUNTIME_WORK" "$PWD/cache"
'''

    def tearDown(self):
        self.tmp.cleanup()

    def run_shell(self, script):
        path = self.root / 'test.sh'
        path.write_text(self.prefix + self.helpers + script, newline='\n')
        return subprocess.run([BASH, path.name], cwd=self.root, text=True, capture_output=True)

    def test_zero_byte_successful_transport_rejected_and_retried(self):
        r = self.run_shell('''wget() { echo attempt >> attempts; : > "$4"; return 0; }
download_verified "$PWD/cache/tmux.gz" deadbeef tmux https://fixture/tmux
''')
        self.assertNotEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertEqual((self.root / 'attempts').read_text().count('attempt'), 3)
        self.assertIn('empty or missing download', r.stdout)
        self.assertFalse((self.root / 'cache/tmux.gz').exists())
        self.assertEqual(list((self.root / 'work').iterdir()), [])

    def test_failed_transport_rejects_even_matching_bytes(self):
        digest = hashlib.sha256(b'valid').hexdigest()
        r = self.run_shell(f'''wget() {{ printf valid > "$4"; return 8; }}
download_verified "$PWD/cache/tmux.gz" {digest} tmux https://fixture/tmux
''')
        self.assertNotEqual(r.returncode, 0)
        self.assertIn('rc=8', r.stdout)
        self.assertFalse((self.root / 'cache/tmux.gz').exists())

    def test_bad_cache_recovers_then_offline_reuses_verified_cache(self):
        digest = hashlib.sha256(b'valid').hexdigest()
        r = self.run_shell(f'''touch cache/tmux.gz
wget() {{ echo attempt >> attempts; printf valid > "$4"; }}
download_verified "$PWD/cache/tmux.gz" {digest} tmux https://fixture/tmux || exit 1
wget() {{ return 99; }}
download_verified "$PWD/cache/tmux.gz" {digest} tmux https://fixture/tmux
''')
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertEqual((self.root / 'cache/tmux.gz').read_bytes(), b'valid')
        self.assertEqual((self.root / 'attempts').read_text().count('attempt'), 1)

    def test_digest_mismatch_never_publishes(self):
        r = self.run_shell('''wget() { printf corrupt > "$4"; }
download_verified "$PWD/cache/tmux.gz" deadbeef tmux https://fixture/tmux
''')
        self.assertNotEqual(r.returncode, 0)
        self.assertIn('expected=deadbeef actual=', r.stdout)
        self.assertFalse((self.root / 'cache/tmux.gz').exists())

    def test_failed_executable_preserves_previous_binary(self):
        r = self.run_shell('''printf old > tmux
printf '#!/bin/bash\\nexit 7\\n' > candidate
publish_binary candidate "$PWD/tmux" -V
''')
        self.assertNotEqual(r.returncode, 0)
        self.assertEqual((self.root / 'tmux').read_text(), 'old')
        self.assertEqual(list(self.root.glob('tmux.next.*')), [])

    def test_valid_executable_published_atomically(self):
        r = self.run_shell('''printf old > tmux
printf '#!/bin/bash\\necho "tmux fixture"\\n' > candidate
publish_binary candidate "$PWD/tmux" -V || exit 1
./tmux -V
''')
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn('tmux fixture', r.stdout)

    def test_real_tmux_empty_download_path_fails_without_link_or_data_changes(self):
        tmux = RUNTIME[RUNTIME.index('# --- 2. tmux'):RUNTIME.index('# --- 3. fd')]
        r = self.run_shell('''RUNTIME_BASE="$PWD/runtime"; BIN_DEST="$PWD/bin"; CONFIG_DIR="$PWD/cache"
mkdir -p "$BIN_DEST" "$RUNTIME_BASE/bin" HOME AgentStorage
printf keep > HOME/sentinel; printf keep > AgentStorage/sentinel
printf existing > "$BIN_DEST/tmux"
wget() { : > "$4"; }
command() { return 1; }
''' + tmux)
        self.assertNotEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertFalse((self.root / 'runtime/bin/tmux').exists())
        self.assertEqual((self.root / 'bin/tmux').read_text(), 'existing')
        self.assertEqual((self.root / 'HOME/sentinel').read_text(), 'keep')
        self.assertEqual((self.root / 'AgentStorage/sentinel').read_text(), 'keep')

    def test_engine_runtime_failure_and_missing_script_block_activation(self):
        start = ENGINE.index('        # Required runtime gates')
        end = ENGINE.index('        log_ok "Backend generation', start)
        gate = ENGINE[start:end].replace('/tmp/aicli-runtime.sh', 'runtime.sh')
        for content in ('#!/bin/bash\nexit 23\n', ''):
            with self.subTest(content=content):
                (self.root / 'runtime.sh').write_text(content, newline='\n')
                r = self.run_shell('aicli_activate_generation() { touch activated; }\n' + gate + '\ntouch autolaunched\n')
                self.assertNotEqual(r.returncode, 0, r.stdout + r.stderr)
                self.assertFalse((self.root / 'activated').exists())
                self.assertFalse((self.root / 'autolaunched').exists())

    def test_engine_runtime_success_precedes_activation(self):
        start = ENGINE.index('        # Required runtime gates')
        end = ENGINE.index('        log_ok "Backend generation', start)
        gate = ENGINE[start:end].replace('/tmp/aicli-runtime.sh', 'runtime.sh')
        (self.root / 'runtime.sh').write_text('#!/bin/bash\necho runtime >> order\n', newline='\n')
        r = self.run_shell('aicli_activate_generation() { echo activation >> order; }\n' + gate)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertEqual((self.root / 'order').read_text().splitlines(), ['runtime', 'activation'])

    def test_parent_plg_propagates_runtime_failure_before_autolaunch(self):
        # Run the actual parent's engine pipeline and PIPESTATUS check with the
        # actual engine runtime gate in a child. All host paths are fixture paths.
        start = ENGINE.index('        # Required runtime gates')
        end = ENGINE.index('        log_ok "Backend generation', start)
        gate = ENGINE[start:end].replace('/tmp/aicli-runtime.sh', 'runtime.sh')
        (self.root / 'runtime.sh').write_text('#!/bin/bash\nexit 23\n', newline='\n')
        (self.root / 'engine.sh').write_text(self.prefix + 'aicli_activate_generation() { touch activated; }\n' + gate, newline='\n')
        plg = html.unescape((ROOT / 'unraid-aicliagents.plg').read_text())
        start = plg.index('# --- Modular Engine Bootstrap ---')
        end = plg.index('# #337: EVERY background', start)
        parent = plg[start:end].replace('/tmp/aicli-engine.sh', 'engine.sh')
        r = self.run_shell('get_ts() { echo fixture; }\nexport -f log_fail log_step get_ts\nLOG_FILE="$PWD/install.log"\n' + parent + '\ntouch autolaunched\n')
        self.assertNotEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertFalse((self.root / 'activated').exists())
        self.assertFalse((self.root / 'autolaunched').exists())
        self.assertIn('Aborting installation', r.stdout)

    def test_optional_failures_do_not_skip_required_git_lfs(self):
        start = RUNTIME.index('( install_tool "$FD_TAR"')
        end = RUNTIME.index('# --- 4. squashfs-tools', start)
        optional = RUNTIME[start:end]
        r = self.run_shell('''install_tool() {
    case "$3" in fd|rg) return 1;; git-lfs) touch git-lfs-reached; return 17;; esac
}
''' + optional + '\ntouch false-success\n')
        self.assertEqual(r.returncode, 1, r.stdout + r.stderr)
        self.assertTrue((self.root / 'git-lfs-reached').exists())
        self.assertFalse((self.root / 'false-success').exists())

    def test_existing_managed_node_is_not_adopted_as_system_node(self):
        start = RUNTIME.index('USE_SYSTEM_NODE=1')
        end = RUNTIME.index('log_step "Node.js runtime..."', start)
        selection = RUNTIME[start:end]
        r = self.run_shell('''RUNTIME_BASE="$PWD/runtime"; BIN_DEST="$PWD/bin"
mkdir -p "$RUNTIME_BASE/node/bin"
printf '#!/bin/bash\\necho v22.22.0\\n' > "$RUNTIME_BASE/node/bin/node"
chmod +x "$RUNTIME_BASE/node/bin/node"
command() { echo "$RUNTIME_BASE/node/bin/$2"; }
readlink() { echo "$2"; }
''' + selection + '\n[ "$USE_SYSTEM_NODE" -eq 0 ]\n')
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)

    def test_unsquashfs_version_contract_accepts_exit_one_with_banner(self):
        r = self.run_shell('''printf '#!/bin/bash\\necho "unsquashfs version 4.5"\\nexit 1\\n' > candidate
publish_binary candidate "$PWD/unsquashfs" -version
''')
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertTrue((self.root / 'unsquashfs').exists())

    def test_unsquashfs_exit_one_without_banner_is_rejected(self):
        r = self.run_shell('''printf previous > unsquashfs
printf '#!/bin/bash\\necho "error while loading shared libraries" >&2\\nexit 1\\n' > candidate
publish_binary candidate "$PWD/unsquashfs" -version
''')
        self.assertNotEqual(r.returncode, 0)
        self.assertEqual((self.root / 'unsquashfs').read_text(), 'previous')
        self.assertIn('rc=1', r.stdout)
        self.assertIn('error while loading shared libraries', r.stdout)

    def test_unsquashfs_fatal_status_with_banner_is_rejected(self):
        r = self.run_shell('''printf '#!/bin/bash\\necho "unsquashfs version 4.5"\\nexit 2\\n' > candidate
publish_binary candidate "$PWD/unsquashfs" -version
''')
        self.assertNotEqual(r.returncode, 0)
        self.assertFalse((self.root / 'unsquashfs').exists())


if __name__ == '__main__':
    unittest.main()
