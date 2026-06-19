#!/usr/bin/env bash
#
# Build a clean, distributable ZIP of the plugin.
#
# Includes only the files WordPress needs (main file, includes/, assets/,
# templates/, README, languages/) and excludes all dev/IDE/agent tooling.
# The version number is read from the plugin header automatically, so the
# output is e.g. woo-bd-partial-cod-1.5.0.zip with a top-level folder that
# WordPress can install directly.
#
# Usage:  bash build.sh
#
set -euo pipefail

SLUG="woo-bd-partial-cod"
MAIN="$SLUG.php"

cd "$(dirname "$0")"

if [ ! -f "$MAIN" ]; then
	echo "❌ $MAIN not found — run this from the plugin folder." >&2
	exit 1
fi

# Read "Version: X.Y.Z" from the plugin header.
VERSION="$(grep -m1 -oE 'Version:[[:space:]]*[0-9][0-9.]*' "$MAIN" | grep -oE '[0-9][0-9.]*')"
VERSION="${VERSION:-dev}"

BUILD_DIR="build"
STAGE="$BUILD_DIR/$SLUG"
ZIP="$SLUG-$VERSION.zip"

# The actual plugin payload — anything not listed here is left out of the ZIP.
INCLUDE=( "$MAIN" "README.md" "includes" "assets" "templates" )
[ -d languages ] && INCLUDE+=( "languages" )

echo "📦 Building $ZIP (version $VERSION)…"

rm -rf "$BUILD_DIR" "$ZIP"
mkdir -p "$STAGE"

for item in "${INCLUDE[@]}"; do
	if [ -e "$item" ]; then
		cp -r "$item" "$STAGE/"
	fi
done

# No `zip` binary on Windows Git Bash — use PowerShell's Compress-Archive.
ROOT_WIN="$(pwd -W 2>/dev/null || pwd)"
if command -v powershell >/dev/null 2>&1; then
	powershell -NoProfile -Command \
		"Compress-Archive -Path '$ROOT_WIN/$STAGE' -DestinationPath '$ROOT_WIN/$ZIP' -Force"
elif command -v zip >/dev/null 2>&1; then
	( cd "$BUILD_DIR" && zip -rq "../$ZIP" "$SLUG" )
else
	echo "❌ Neither PowerShell nor zip is available to create the archive." >&2
	rm -rf "$BUILD_DIR"
	exit 1
fi

rm -rf "$BUILD_DIR"
echo "✅ Created $ZIP"
