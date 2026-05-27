#!/bin/bash
# Cross-compile the Secret Service daemon to a static linux/amd64 binary and
# drop it where the plugin ships it (src/secret-service/). The Unraid box has
# no Go toolchain, so the committed binary is the deployed artefact.
set -e
cd "$(dirname "$0")"
export GOOS=linux GOARCH=amd64 CGO_ENABLED=0
go mod tidy
mkdir -p ../src/secret-service
go build -trimpath -ldflags='-s -w' -o ../src/secret-service/secret-service-daemon .
echo "built:"
ls -la ../src/secret-service/secret-service-daemon
