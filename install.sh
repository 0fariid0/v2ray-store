#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || true)
if [ -n "$SCRIPT_DIR" ] && [ -f "$SCRIPT_DIR/v2raystore.sh" ]; then
    exec bash "$SCRIPT_DIR/v2raystore.sh" install
fi

tmp_script=$(mktemp /tmp/v2raystore-installer.XXXXXX)
trap 'rm -f "$tmp_script"' EXIT
curl -fsSL --retry 3 --connect-timeout 15 \
    https://raw.githubusercontent.com/0fariid0/v2ray-store/main/v2raystore.sh \
    -o "$tmp_script"
bash "$tmp_script" install
