#!/bin/bash
# Gemini CLI Restricted Shell Wrapper (Simple Version)
LOG="/tmp/gemini-shell.log"
echo "$(date) - Shell started" >> "$LOG"

export HOME=/mnt
cd /mnt || { echo "Failed to cd to /mnt" >> "$LOG"; exit 1; }

# Ensure Node and Gemini are in PATH
export PATH=$PATH:/usr/local/bin:/boot/config/plugins/unraid-geminicli/bin

echo "Executing bash..." >> "$LOG"
exec /bin/bash --restricted
