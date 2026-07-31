#!/usr/bin/env bash
# Builds the distributable plugin zip.
#
# The archive must unpack to a single `vittas-payments/` directory — that name
# becomes the plugin folder on the merchant's server, and it must match the
# slug plugin-update-checker was given, or WordPress installs the update
# alongside the old copy instead of replacing it.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="vittas-payments"
SRC="$ROOT/$SLUG"
OUT="$ROOT/build"

VERSION=$(grep -m1 '^ \* Version:' "$SRC/$SLUG.php" | awk '{print $3}')
if [ -z "$VERSION" ]; then
	echo "Could not read Version from $SLUG.php header" >&2
	exit 1
fi

# The plugin header is the single source of truth for the version, and
# plugin-update-checker compares it against the release tag. If readme.txt
# disagrees, the WordPress plugin screen shows one number while the updater
# acts on another.
STABLE=$(grep -m1 '^Stable tag:' "$SRC/readme.txt" | awk '{print $3}')
if [ "$STABLE" != "$VERSION" ]; then
	echo "Version mismatch: $SLUG.php says $VERSION, readme.txt says $STABLE" >&2
	exit 1
fi

rm -rf "$OUT"
mkdir -p "$OUT/$SLUG"

# Copy only what the plugin needs at runtime. Anything used solely for
# development stays out of the archive merchants download.
( cd "$SRC" && tar -cf - \
	--exclude='.git*' \
	--exclude='node_modules' \
	--exclude='*.zip' \
	--exclude='lib/plugin-update-checker/.git*' \
	--exclude='lib/plugin-update-checker/examples' \
	--exclude='lib/plugin-update-checker/README.md' \
	--exclude='lib/plugin-update-checker/composer.json' \
	. ) | ( cd "$OUT/$SLUG" && tar -xf - )

ZIP="$OUT/$SLUG-$VERSION.zip"

# Linux/macOS and CI have `zip`; a stock Git-Bash on Windows does not.
#
# PowerShell's Compress-Archive is NOT an acceptable fallback: on Windows
# PowerShell it writes backslash path separators, which the ZIP spec forbids.
# PHP's unzip then treats "vittas-payments\includes\foo.php" as one flat
# filename instead of a directory tree, and the plugin installs broken on any
# Linux host. Python's zipfile always writes forward slashes.
if command -v zip >/dev/null 2>&1; then
	( cd "$OUT" && zip -qr "$SLUG-$VERSION.zip" "$SLUG" )
elif command -v python3 >/dev/null 2>&1; then
	( cd "$OUT" && python3 -c "
import os, sys, zipfile
slug, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(slug):
        for f in files:
            p = os.path.join(root, f)
            z.write(p, os.path.relpath(p).replace(os.sep, '/'))
" "$SLUG" "$SLUG-$VERSION.zip" )
else
	echo "Need 'zip' or python3 to build a spec-compliant archive" >&2
	exit 1
fi

echo "built:   $ZIP"
echo "version: $VERSION"
if command -v unzip >/dev/null 2>&1; then
	echo "entries: $(unzip -l "$ZIP" | tail -1 | awk '{print $2}')"
	# Guard the one property that breaks silently: everything must live under
	# vittas-payments/ or WordPress installs the update as a second plugin.
	if unzip -l "$ZIP" | awk 'NR>3 && $4 != "" && $4 !~ /^vittas-payments\// {found=1} END {exit !found}'; then
		echo "ERROR: archive has entries outside vittas-payments/" >&2
		exit 1
	fi
	echo "layout:  OK (single vittas-payments/ root)"
fi
