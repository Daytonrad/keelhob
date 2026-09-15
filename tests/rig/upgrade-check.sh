#!/usr/bin/env bash
#
# Upgrade an OLD released version to the working tree, on the rig, and prove
# nothing was lost.
#
#   bash tests/rig/upgrade-check.sh v1.1.0 /c/wp-rig/wordpress
#
# WHY THIS EXISTS. Every other suite tests one version of the plugin in
# isolation. None of them can see the one thing that only happens to real
# people: an existing site, with existing data written by an OLDER schema,
# being handed new files by an automatic update.
#
# That is the failure mode with no undo. A migration that drops a column, a
# read path that assumes a shape only the new version writes, or an upgrade
# that quietly switches capabilities on — none of it shows up on a fresh
# install, which is the only thing a test rig normally is.
#
# WHAT IT DOES, and why in this order:
#
#   1. Wipes every claudpress_* table and option, so the rig is a site that has
#      NEVER seen the new version. Downgrading files over a new database would
#      prove nothing: maybe_upgrade() reads claudpress_db_version and returns
#      early, so the migration under test would never run.
#   2. Installs the OLD version from its git tag and activates it.
#   3. Seeds data through the OLD version's OWN save path — never by writing
#      rows by hand. Hand-written fixtures encode what you BELIEVE the old
#      shape was; the old code encodes what it actually was.
#   4. Fingerprints everything.
#   5. Copies the new files over WITHOUT deactivating, because that is exactly
#      what WordPress's updater does: replace the directory, then let the next
#      page load run the upgrade.
#   6. Loads a page, then compares.
#
# It restores the rig to the working tree afterwards, pass or fail.
#
# Other plugins on the rig are untouched: every wipe below is scoped to the
# claudpress_ prefix, and the run asserts that a neighbouring plugin's tables
# and posts are still there at the end.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FROM="${1:-}"
WP="${2:-${CP_RIG:-/c/wp-rig/wordpress}}"

if [ -z "$FROM" ]; then
  echo "Usage: bash tests/rig/upgrade-check.sh <old-tag> [/path/to/rig/wordpress]" >&2
  exit 2
fi
if [ ! -f "$WP/wp-config.php" ]; then
  echo "Not a WordPress install: $WP" >&2
  exit 2
fi
if ! git -C "$ROOT" rev-parse "$FROM" >/dev/null 2>&1; then
  echo "No such tag or ref: $FROM" >&2
  exit 2
fi

WPCLI="${WP_CLI:-}"
if [ -z "$WPCLI" ]; then
  if [ -f /c/wp-cli/wp-cli.phar ]; then WPCLI="php /c/wp-cli/wp-cli.phar"; else WPCLI="wp"; fi
fi

PLUGIN_DIR="$WP/wp-content/plugins/claudpress"
OLD_TREE="$(mktemp -d)"
STEP_DIR="$ROOT/tests/rig/upgrade-steps"

cleanup() { rm -rf "$OLD_TREE"; }
trap cleanup EXIT

# Run a PHP file through WP-CLI, stripping chatter that is not about the plugin.
#
# The is_singular()/is_front_page() notices are WordPress complaining that a
# conditional query tag was called before the query ran -- which is exactly what
# happens when snippets execute inside a WP-CLI boot. Real, and not about the
# upgrade; they would bury every line this script prints.
php_run() {
  # shellcheck disable=SC2086
  $WPCLI eval-file "$1" --path="$WP" 2>&1     | grep -viE 'deprecated|called <strong>incorrectly|Conditional query tags|^\s*$'
  return "${PIPESTATUS[0]}"
}

echo "=============================================================="
echo "  Upgrade check: $FROM  ->  working tree"
echo "=============================================================="

# ---------------------------------------------------------------- 1. wipe
echo
echo "[1/6] Wiping claudpress data so this is a site that never saw the new version"
php_run "$STEP_DIR/1-wipe.php" || exit 1

# ---------------------------------------------------------------- 2. old files
echo
echo "[2/6] Installing $FROM"
git -C "$ROOT" archive "$FROM" | tar -x -C "$OLD_TREE"
rm -rf "$PLUGIN_DIR"
mkdir -p "$PLUGIN_DIR"
# Ship-shaped: the same top-level files a zip carries, nothing from tests/.
for item in claudpress.php uninstall.php readme.txt includes assets skills languages; do
  [ -e "$OLD_TREE/$item" ] && cp -r "$OLD_TREE/$item" "$PLUGIN_DIR/"
done
OLD_VER="$(grep -m1 -oE 'Version:[[:space:]]*[0-9][0-9.]*' "$PLUGIN_DIR/claudpress.php" | grep -oE '[0-9][0-9.]*')"
echo "      installed plugin header version: $OLD_VER"

# shellcheck disable=SC2086
$WPCLI plugin activate claudpress --path="$WP" 2>&1 | grep -viE 'deprecated|^\s*$'

# ---------------------------------------------------------------- 3. seed
echo
echo "[3/6] Seeding data through $FROM's OWN save path"
php_run "$STEP_DIR/2-seed.php" || exit 1

# ---------------------------------------------------------------- 4. before
echo
echo "[4/6] Fingerprinting before the upgrade"
php_run "$STEP_DIR/3-before.php" || exit 1

# ---------------------------------------------------------------- 5. new files
echo
echo "[5/6] Dropping the new files in place, the way WordPress's updater does"
# No deactivate/reactivate. A real update replaces the directory under a live,
# active plugin and lets the next page load notice.
#
# THE REAL ZIP, not a file copy. sync-plugin.sh deliberately refuses to create
# a plugin directory that does not exist -- a guard worth keeping, since the
# alternative is a real site quietly acquiring a development build -- and an
# upgrade test wants the shipped artifact anyway. This is the same file people
# download.
ALLOW_DIRTY=1 bash "$ROOT/bin/build-zip.sh" >/dev/null 2>&1 || {
  echo "      FAILED: could not build the zip" >&2
  exit 1
}
rm -rf "$PLUGIN_DIR"
unzip -q -o "$ROOT/build/claudpress.zip" -d "$WP/wp-content/plugins/"
if [ ! -f "$PLUGIN_DIR/claudpress.php" ]; then
  echo "      FAILED: the new files are not in place at $PLUGIN_DIR" >&2
  exit 1
fi
NEW_VER="$(grep -m1 -oE "define\('CLAUDPRESS_VERSION',[[:space:]]*'[^']+" "$PLUGIN_DIR/claudpress.php" | grep -oE "[0-9][0-9.]*")"
echo "      unpacked the release zip: $NEW_VER"

# The upgrade runs on a normal page load, not on a WP-CLI call that happens to
# boot WordPress. Hit the front end first so the path under test is the real one.
# shellcheck disable=SC2086
$WPCLI eval 'wp_remote_get(home_url("/"), ["timeout" => 30]);' --path="$WP" >/dev/null 2>&1

# ---------------------------------------------------------------- 6. after
echo
echo "[6/6] Comparing"
php_run "$STEP_DIR/4-after.php"
status=$?

echo
echo "--------------------------------------------------------------"
if [ "$status" -ne 0 ]; then
  echo "UPGRADE CHECK FAILED — the rig is back on the working tree, but read the"
  echo "failures above before shipping."
  exit 1
fi
echo "Upgrade check passed. Rig is back on the working tree."
