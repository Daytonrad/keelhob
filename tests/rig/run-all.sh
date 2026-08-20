#!/usr/bin/env bash
#
# Run every live check against the rig.
#
#   bash tests/rig/run-all.sh /path/to/rig/wordpress
#
# Or set CP_RIG once and drop the argument.
#
# The unit suite (php tests/run.php) runs against hand-written stubs and is
# fast. This runs against a real WordPress with a real database, and catches the
# class of bug stubs cannot see: a guard that was dropped when a file was
# carved up, an option that autoloads when you thought it didn't, a screen that
# throws only once WordPress is actually loaded.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP="${1:-${CP_RIG:-}}"

if [ -z "$WP" ]; then
  echo "Usage: bash tests/rig/run-all.sh /path/to/rig/wordpress" >&2
  echo "   or: export CP_RIG=/path/to/rig/wordpress" >&2
  exit 2
fi

if [ ! -f "$WP/wp-config.php" ]; then
  echo "Not a WordPress install: $WP" >&2
  echo "See tests/rig/README.md for how to build one." >&2
  exit 2
fi

WPCLI="${WP_CLI:-wp}"
if ! command -v "$WPCLI" >/dev/null 2>&1; then
  # WP-CLI is often only present as a phar on Windows.
  if [ -f /c/wp-cli/wp-cli.phar ]; then
    WPCLI="php /c/wp-cli/wp-cli.phar"
  else
    echo "WP-CLI not found. Set WP_CLI to the command that runs it." >&2
    exit 2
  fi
fi

# checks.php expects the add-on OFF (it asserts Pro tools are absent).
# The others expect it ON. Ordering the runs is cheaper and clearer than making
# each script tolerate either state.
run() {
  local label="$1" script="$2"
  echo
  echo "=============================================================="
  echo "  $label"
  echo "=============================================================="
  # shellcheck disable=SC2086
  $WPCLI eval-file "$ROOT/tests/rig/$script" --path="$WP" 2>&1 | grep -vi 'deprecated'
  return "${PIPESTATUS[0]}"
}

fails=0

# shellcheck disable=SC2086
$WPCLI plugin deactivate claudpress-pro --path="$WP" >/dev/null 2>&1
run "Free install"        checks.php         || fails=$((fails + 1))

# shellcheck disable=SC2086
$WPCLI plugin activate claudpress-pro --path="$WP" >/dev/null 2>&1
run "With the Pro add-on" checks-pro.php     || fails=$((fails + 1))
run "Snippets Studio"     checks-studio.php  || fails=$((fails + 1))
run "AI Compose flow"     checks-compose.php || fails=$((fails + 1))

echo
echo "--------------------------------------------------------------"
if [ "$fails" -gt 0 ]; then
  echo "$fails of 4 check files FAILED"
  exit 1
fi
echo "All 4 check files passed."
