#!/usr/bin/env bash
set -euo pipefail

# PowerShell 7 also runs on Linux/macOS and keeps ZIP rules in one place.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if ! command -v pwsh >/dev/null 2>&1; then
  echo 'PowerShell 7 (pwsh) is required for the validated release builder.' >&2
  exit 1
fi
exec pwsh -NoProfile -File "${ROOT_DIR}/tools/build-standard-zip.ps1" "$@"
