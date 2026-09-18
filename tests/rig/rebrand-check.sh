#!/usr/bin/env bash
#
# Hand a real site with real data from the OLD product name to the new one, and
# prove nothing was lost.
#
#   bash tests/rig/rebrand-check.sh /c/wp-rig/wordpress
#
# WHY THIS EXISTS AND THE UNIT TESTS ARE NOT ENOUGH.
#
# tests/unit/test-legacy-adopt.php drives the adoption against a $wpdb double.
# A double answers whatever it was told to answer. It cannot tell you whether
# RENAME TABLE actually works on this database, whether the settings option
# survives being read by new code, or whether WordPress lets you activate a
# second plugin that declares the same admin menu. Those only fail on a real
# install, and they fail by losing somebody's snippets.
#
# So this drives the real sequence a site owner will follow, on the rig, over a
# database that already holds skills, memory and logs written by the old code.
#
# WHAT IT DOES, in the order that matters:
#
#   1. Fingerprints the old install: every table's row count, the settings
#      option, the hashed token, a named skill and a named memory key.
#   2. Deactivates the old plugin. This is required, not tidiness: the adoption
#      step refuses to run while it is active, because renaming tables under a
#      running plugin throws a database error on every request.
#   3. Unpacks the REAL release zip as the new plugin, then activates it.
#   4. Loads an admin page so maybe_upgrade() runs for real, rather than
#      calling the migration directly. Calling it directly would skip the one
#      thing most likely to be wrong: the ORDER of adoption against
#      install_table().
#   5. Fingerprints again and compares.
#   6. Asserts the old tables are GONE, not merely copied. A copy that leaves
#      both behind is how you get two sources of truth and a support ticket in
#      three months.
#
# The tally is kept in a PHP static, not $GLOBALS: `wp eval-file` evaluates the
# file body inside a method, so top-level variables are locals and a $GLOBALS
# counter silently stays at zero while FAIL lines scroll past and the script
# exits 0.

# HOW TO BUILD A RIG TO RUN THIS AGAINST.
#
# It needs a site running the OLD plugin, which this branch no longer contains.
# Build one from the last commit before the rename, in a throwaway worktree, so
# the rig you keep for day-to-day work is never touched:
#
#   git worktree add /c/kh-oldwt <commit-before-the-rename>
#   cd /c/kh-oldwt
#   CP_RIG_CACHE=/c/wp-rig/.downloads bash tests/rig/build-rig.sh /c/wp-rig2
#
# Then seed it with a site's worth of data, giving every table a DIFFERENT row
# count, so a table that ends up holding another table's rows is visible rather
# than merely plausible. Use a real 36-character UUID for the site id:
# Keelhob_Updater::site_id() replaces anything shorter, which reads as data loss
# and is not.
#
# BUILD THE RIG FRESH FOR EVERY RUN. This check moves tables around and drops
# them. A rig that has been through it once, or been hand-edited to "put things
# back", stops being evidence -- it cannot tell you whether a table survived
# because the code is right or because a previous run left it there. That is
# how a bug that abandoned the owner's snippets went unnoticed for three runs.

set -uo pipefail

WP_DIR="${1:-/c/wp-rig/wordpress}"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ZIP="$PLUGIN_DIR/build/keelhob.zip"
OLD_SLUG='claudpress'
NEW_SLUG='keelhob'

# Same discovery the other rig scripts use.
if [ -f /c/wp-cli/wp-cli.phar ]; then WPCLI="php /c/wp-cli/wp-cli.phar"; else WPCLI="wp"; fi
wp() { $WPCLI --path="$WP_DIR" "$@" 2>&1; }

step() { printf '\n\033[1m%s\033[0m\n' "$1"; }

[ -f "$ZIP" ] || { echo "No zip at $ZIP — run bash bin/build-zip.sh first."; exit 1; }
[ -d "$WP_DIR/wp-content/plugins/$OLD_SLUG" ] || {
  echo "The rig has no $OLD_SLUG plugin installed, so there is nothing to adopt."
  echo "This check only means something against a site that ran the old name."
  exit 1
}

step "1. Fingerprint the old install"
# WP-CLI mixes deprecations and SQLite notices into stdout, so take only
# the JSON line. Without this the compare step reads a wall of HTML,
# fails to decode it, and reports "no fingerprint" instead of the truth.
BEFORE="$(wp eval-file "$PLUGIN_DIR/tests/rig/rebrand-steps/before.php" | grep -o '{.*}' | tail -1)"
echo "$BEFORE"
[ -n "$BEFORE" ] || { echo "FAIL: could not fingerprint the old install."; exit 1; }

step "2. Deactivate the old plugin (the adoption step refuses to run otherwise)"
wp plugin deactivate "$OLD_SLUG" | tail -1

step "3. Unpack the real release zip"
rm -rf "$WP_DIR/wp-content/plugins/$NEW_SLUG"
unzip -q -o "$ZIP" -d "$WP_DIR/wp-content/plugins/"
[ -f "$WP_DIR/wp-content/plugins/$NEW_SLUG/$NEW_SLUG.php" ] || { echo "FAIL: zip did not unpack to $NEW_SLUG/"; exit 1; }
wp plugin activate "$NEW_SLUG" | tail -1

step "4. Load an admin page so maybe_upgrade() runs the way it does in real life"
wp eval 'do_action("init"); do_action("admin_init");' | tail -1

step "5. Compare"
echo "$BEFORE" > "$WP_DIR/.rebrand-before.json"
wp eval-file "$PLUGIN_DIR/tests/rig/rebrand-steps/after.php"
RESULT=$?

rm -f "$WP_DIR/.rebrand-before.json"
exit $RESULT
