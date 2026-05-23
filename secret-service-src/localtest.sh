#!/bin/bash
# Local verification of the Secret Service in a Debian container: runs the real
# bring-up path (secret-service-up.sh -> session bus -> daemon) and the daemon's
# own --selftest round-trip. Run build.sh first to produce the daemon binary.
set -e
cd "$(dirname "$0")"
STAGE="C:/tmp/sstest"
mkdir -p "$STAGE"

cp ../src/secret-service/secret-service-daemon "$STAGE/ss-daemon"
cp ../src/secret-service/secret-service-up.sh "$STAGE/ss-up.sh"

echo "docker build..."
docker build -q -t aicli-sstest "$STAGE"

echo "docker run..."
docker run --rm aicli-sstest
