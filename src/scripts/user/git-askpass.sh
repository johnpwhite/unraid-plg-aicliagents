#!/bin/bash
# git-askpass.sh — GIT_ASKPASS helper for the hub git layer (OP #1365 / H-04).
#
# git invokes this once per credential prompt with the prompt text as $1
# ("Username for 'https://…': " / "Password for 'https://…': "). The PAT is
# injected by GitHomeService via proc_open's env parameter ONLY — it is never
# on a command line, never in a file, and this script never writes it anywhere
# but stdout (which git consumes directly).
#
# Username: PAT-based HTTPS auth accepts an arbitrary non-empty username on
# GitHub and GitLab when the password is the token; "token" is the default,
# overridable via AICLI_GIT_USERNAME for providers that require a fixed one.
#
# SECURITY: no logging, no echo to stderr, no temp files. Do not "improve"
# this with debugging output.

case "${1:-}" in
    [Uu]sername*) printf '%s\n' "${AICLI_GIT_USERNAME:-token}" ;;
    *)            printf '%s\n' "${AICLI_GIT_PAT:-}" ;;
esac
