#!/usr/bin/env bash
#
# Push the working tree into an existing rig, without rebuilding it.
#
#   bash tests/rig/sync-plugin.sh /c/wp-rig/wordpress
#
# build-rig.sh deletes and recreates the WordPress install, which throws away
# every snippet, page and setting the rig is carrying. That is right for CI and
# wrong for a rig being kept between sessions to test fixes against. This copies
# only the plugin, so the site around it survives.
#
# It copies exactly the file list bin/build-zip.sh ships, and nothing else: a
# rig running files that are not in the release is a rig that can pass a check
# the release would fail.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP="${1:-${CP_RIG_WP:-}}"

if [ -z "$WP" ]; then
  echo "Usage: bash tests/rig/sync-plugin.sh /path/to/rig/wordpress" >&2
  exit 2
fi

DEST="$WP/wp-content/plugins/claudpress"

# Refuse to create the target. If it is not already a rig, the path is wrong,
# and copying a plugin into a directory nobody asked for is how a real site
# quietly acquires a development build.
if [ ! -f "$DEST/claudpress.php" ]; then
  echo "No Claudpress install at $DEST — build the rig first:" >&2
  echo "  bash tests/rig/build-rig.sh \"$(dirname "$WP")\"" >&2
  exit 2
fi

# Parse-check before overwriting anything. Copying a file with a syntax error
# into a live rig takes the whole admin down, including the screen you would
# use to see what happened.
fail=0
while IFS= read -r file; do
  php -l "$file" > /dev/null 2>&1 || { echo "Syntax error: $file" >&2; fail=1; }
done < <(find "$ROOT/includes" "$ROOT/claudpress.php" "$ROOT/uninstall.php" -name '*.php')
[ "$fail" = "0" ] || { echo "Nothing was copied." >&2; exit 1; }

# Old files are removed first. Without this a class deleted from the working
# tree keeps running in the rig, and the bug you are chasing is a file that no
# longer exists.
rm -rf "$DEST/includes" "$DEST/skills" "$DEST/assets"
cp "$ROOT/claudpress.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$DEST/"
cp -r "$ROOT/includes" "$ROOT/skills" "$ROOT/assets" "$DEST/"

VERSION="$(grep -oP "define\('CLAUDPRESS_VERSION',\s*'\K[^']+" "$ROOT/claudpress.php" || echo '?')"
echo "Synced Claudpress $VERSION into $DEST"
echo "Reload the admin — no reactivation needed."
