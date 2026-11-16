#!/usr/bin/env sh
# Creates the official Wails v3 vanilla-js project and applies the desktop/ overlay.
# Requires Go 1.26+ and: go install github.com/wailsapp/wails/v3/cmd/wails3@v3.0.0
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
target=${1:-"$here/wowcensus-companion"}

command -v wails3 >/dev/null 2>&1 || { echo "wails3 not found. Run: go install github.com/wailsapp/wails/v3/cmd/wails3@v3.0.0" >&2; exit 1; }
[ -e "$target" ] && { echo "$target already exists" >&2; exit 1; }

(cd "$(dirname "$target")" && wails3 init -n "$(basename "$target")" -t vanilla-js -mod wowcensuscompanion -d "$(basename "$target")")
rm -f "$target/greetservice.go"
cp -R "$here/desktop/." "$target/"
cd "$target"
go mod tidy
go test ./companion/...
printf '\nReady. Next:\n  cd "%s"\n  wails3 dev\n' "$target"
