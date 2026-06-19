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

# Use the `zip` binary (writes spec-compliant forward-slash paths).
# NOTE: do NOT fall back to Windows PowerShell's Compress-Archive — it writes
# back-slash paths inside the ZIP, which WordPress cannot extract ("Plugin file
# does not exist"). On Windows, run build.ps1 / build.cmd instead.
if command -v zip >/dev/null 2>&1; then
	( cd "$BUILD_DIR" && zip -rq "../$ZIP" "$SLUG" )
	rm -rf "$BUILD_DIR"
	echo "✅ Created $ZIP"
else
	rm -rf "$BUILD_DIR"
	echo "❌ No 'zip' binary found." >&2
	echo "   On Windows, run:  powershell -ExecutionPolicy Bypass -File build.ps1" >&2
	echo "   (or just double-click build.cmd)" >&2
	exit 1
fi
