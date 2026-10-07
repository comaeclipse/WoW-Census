#!/usr/bin/env bash
# Content-aware S3 deployment. Pass --dry-run to inspect or --prune to remove old keys.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
python "$ROOT/tools/deploy-s3.py" "$@"
