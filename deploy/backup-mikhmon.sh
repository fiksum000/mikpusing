#!/usr/bin/env bash
set -Eeuo pipefail
# Compatibility entry point: all backups must include the RADIUS database.
exec bash "$(dirname -- "${BASH_SOURCE[0]}")/backup-full-radius.sh" "$@"
