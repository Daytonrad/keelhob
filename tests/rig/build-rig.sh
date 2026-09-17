#!/usr/bin/env bash
#
# Build a throwaway WordPress-on-SQLite rig and install this plugin into it.
#
#   bash tests/rig/build-rig.sh /path/to/rig
#   bash tests/rig/run-all.sh   /path/to/rig/wordpress
#
# This automates tests/rig/README.md step for step, so the rig a maintainer
# builds by hand and the rig CI builds are the same rig. Needs PHP with
# pdo_sqlite, sodium and openssl; WP-CLI; curl and unzip.
#
# Never point it at a directory you care about: it deletes and recreates one.

set -euo pipefail

# Everything downloaded here is PINNED. With "latest", a WordPress release could
# turn CI red on a morning nobody touched the plugin, and a red build nobody
# caused is a red build everybody learns to ignore. Bumping these two lines is
# a deliberate, reviewable act, and the diff then says which upgrade broke it.
WP_VERSION="${WP_VERSION:-7.1}"
SQLITE_VERSION="${SQLITE_VERSION:-3.0.1}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RIG="${1:-${KH_RIG_ROOT:-}}"

if [ -z "$RIG" ]; then
  echo "Usage: bash tests/rig/build-rig.sh /path/to/rig" >&2
  exit 2
fi

WPCLI="${WP_CLI:-wp}"
if ! command -v "$WPCLI" > /dev/null 2>&1; then
  # WP-CLI is often only present as a phar on Windows.
  if [ -f /c/wp-cli/wp-cli.phar ]; then
    WPCLI="php /c/wp-cli/wp-cli.phar"
  else
    echo "WP-CLI not found. Set WP_CLI to the command that runs it." >&2
    exit 2
  fi
fi

for ext in pdo_sqlite sodium openssl; do
  if ! php -m | grep -qix "$ext"; then
    echo "PHP is missing the $ext extension - see tests/rig/README.md." >&2
    exit 2
  fi
done

# Downloads live outside the rig itself so a CI cache can hold them across runs.
CACHE="${KH_RIG_CACHE:-$RIG/.downloads}"
WP="$RIG/wordpress"
IMPL="$WP/wp-content/plugins/sqlite-database-integration"

mkdir -p "$CACHE"
mkdir -p "$RIG"
rm -rf "$WP"

fetch() {
  local url="$1" dest="$2"
  if [ -s "$dest" ]; then
    echo "cached:  $(basename "$dest")"
    return 0
  fi
  # --fail so an HTML error page is never silently unzipped; --retry because a
  # single dropped connection is not a reason to fail a build.
  curl -sSL --fail --retry 3 --retry-delay 2 --connect-timeout 20 -o "$dest.part" "$url"
  mv "$dest.part" "$dest"
  echo "fetched: $(basename "$dest")"
}

fetch "https://wordpress.org/wordpress-${WP_VERSION}.zip" \
      "$CACHE/wordpress-${WP_VERSION}.zip"
fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_VERSION}.zip" \
      "$CACHE/sqlite-database-integration.${SQLITE_VERSION}.zip"

unzip -q "$CACHE/wordpress-${WP_VERSION}.zip" -d "$RIG"
unzip -q "$CACHE/sqlite-database-integration.${SQLITE_VERSION}.zip" -d "$WP/wp-content/plugins/"

# ---- the SQLite drop-in -----------------------------------------------------
# db.copy carries two placeholders. The folder one self-heals these days (it
# falls back to realpath(__DIR__ . '/plugins/sqlite-database-integration') when
# the path does not exist) but the plugin one does not, so both are replaced.
cp "$IMPL/db.copy" "$WP/wp-content/db.php"
IMPL_ABS="$IMPL"
if command -v cygpath > /dev/null 2>&1; then
  IMPL_ABS="$(cygpath -m "$IMPL")"
fi
php -r '
$file = $argv[1];
$body = file_get_contents($file);
$body = str_replace("{SQLITE_IMPLEMENTATION_FOLDER_PATH}", $argv[2], $body);
$body = str_replace("{SQLITE_PLUGIN}", "sqlite-database-integration/load.php", $body);
if (strpos($body, "{SQLITE_") !== false) {
    fwrite(STDERR, "db.php still has an unreplaced placeholder\n");
    exit(1);
}
file_put_contents($file, $body);
' "$WP/wp-content/db.php" "$IMPL_ABS"

# ---- wp-config --------------------------------------------------------------
# Real salts, not placeholders: Keelhob_Secrets derives its encryption key
# from AUTH_KEY, SECURE_AUTH_KEY and AUTH_SALT, so a config with the shipped
# "put your unique phrase here" values would appear to work and hide a bug.
# Generated locally rather than fetched from api.wordpress.org, so building a
# rig does not depend on that endpoint being up.
{
  cat <<'PHP'
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'rig' );
define( 'DB_PASSWORD', 'rig' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

PHP
  php -r '
$names = ["AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY",
          "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT"];
$alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|";
foreach ($names as $name) {
    $value = "";
    for ($i = 0; $i < 64; $i++) {
        $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    printf("define( %s, %s );\n", var_export($name, true), var_export($value, true));
}'
  cat <<'PHP'

$table_prefix = 'wp_';

define( 'WP_HOME',    'http://localhost:8899' );
define( 'WP_SITEURL', 'http://localhost:8899' );
define( 'FS_METHOD',  'direct' );
define( 'WP_DEBUG',         true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG',     false );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP
} > "$WP/wp-config.php"

php -l "$WP/wp-config.php" > /dev/null

# ---- the plugin -------------------------------------------------------------
# Copied, not linked, and copied to exactly the file list bin/build-zip.sh ships.
#
# A symlink (or a Windows junction) is the right thing on a development machine,
# because edits are picked up with no rebuild. It is the wrong thing in CI. PHP
# resolves symlinks in __FILE__, so plugin_dir_path(__FILE__) - and therefore
# KEELHOB_DIR - points at the checkout, OUTSIDE wp-content/plugins. Only
# plugin_basename() and plugins_url() are symlink-aware, through the
# $wp_plugin_paths map WordPress builds for exactly this case. A copy puts every
# path where a real install puts it, and removes the question.
#
# Set KH_RIG_LINK=1 for the development edit loop, where that trade is worth it.
DEST="$WP/wp-content/plugins/keelhob"
if [ "${KH_RIG_LINK:-0}" = "1" ]; then
  ln -s "$ROOT" "$DEST"

  # `ln -s` reporting success is not evidence that a symlink exists. Git Bash on
  # Windows, without MSYS=winsymlinks:nativestrict and with Developer Mode off,
  # RECURSIVELY COPIES the target instead and exits 0. Nothing warns you.
  #
  # That failure is the worst shape a failure can take here. The rig builds, the
  # plugin loads, every check passes — and then edits to the checkout never
  # appear, because the rig is holding a frozen copy made at build time. You
  # change code, reload, see the old behaviour, and start debugging the plugin.
  #
  # So the claim is checked rather than trusted, and the bogus copy is deleted:
  # leaving it would hand back exactly the stale rig this is meant to prevent.
  if [ ! -L "$DEST" ]; then
    rm -rf "$DEST"
    echo "KH_RIG_LINK=1 was asked for, but this shell cannot make symlinks." >&2
    echo "ln -s exited 0 and produced a plain directory, which would have gone" >&2
    echo "stale the moment you edited anything. Nothing was left behind." >&2
    echo >&2
    echo "On Git Bash for Windows, either:" >&2
    echo "  export MSYS=winsymlinks:nativestrict   # and enable Developer Mode" >&2
    echo "or drop KH_RIG_LINK and use the copy, which is the default:" >&2
    echo "  bash tests/rig/build-rig.sh \"$RIG\"" >&2
    echo "  bash tests/rig/sync-plugin.sh \"$WP\"   # after each change" >&2
    exit 2
  fi
  echo "Plugin:  symlinked from $ROOT"
else
  mkdir -p "$DEST"
  cp "$ROOT/keelhob.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$DEST/"
  # assets/ ships in the zip, so it must be here too. Leave it out and the rig
  # shows a broken image where the sidebar icon goes — which looks like a bug in
  # the plugin rather than a gap in this script.
  cp -r "$ROOT/includes" "$ROOT/skills" "$ROOT/assets" "$DEST/"
  echo "Plugin:  copied from $ROOT"
fi

# The AI mock replaces the network hop and nothing else, so the whole agent loop
# runs for real with no API key and no spend. See tests/rig/README.md.
mkdir -p "$WP/wp-content/mu-plugins"
cp "$ROOT/tests/rig/ai-mock.php" "$WP/wp-content/mu-plugins/"

# ---- install ----------------------------------------------------------------
# shellcheck disable=SC2086
$WPCLI --path="$WP" core install \
  --url=http://localhost:8899 \
  --title="Keelhob Rig" \
  --admin_user=admin \
  --admin_password=rigpass \
  --admin_email=rig@example.test \
  --skip-email > /dev/null

# shellcheck disable=SC2086
$WPCLI --path="$WP" plugin activate keelhob > /dev/null

# A rig that built but did not load the plugin would let every check "pass" by
# never running, so prove it is there before handing the path back.
# shellcheck disable=SC2086
$WPCLI --path="$WP" eval '
if ( ! defined( "KEELHOB_VERSION" ) ) {
    fwrite( STDERR, "Keelhob did not load\n" );
    exit( 1 );
}
printf( "Loaded:  WordPress %s, Keelhob %s\n", get_bloginfo( "version" ), KEELHOB_VERSION );
// realpath() on both sides before comparing. Comparing the raw strings gave a
// false OUTSIDE on Windows whenever the rig sat under a path with an 8.3 short
// name: PHP resolved KEELHOB_DIR to "C:/Users/FLINTSBWOY/..." while
// WP_PLUGIN_DIR still read "C:/Users/FLINTS~1/...". Same directory, no shared
// prefix. A check that cries wolf on a correct rig gets ignored on a broken one.
$kh_dir = wp_normalize_path( (string) ( realpath( KEELHOB_DIR ) ?: KEELHOB_DIR ) );
$wp_dir = wp_normalize_path( (string) ( realpath( WP_PLUGIN_DIR ) ?: WP_PLUGIN_DIR ) );
printf( "Paths:   KEELHOB_DIR %s wp-content/plugins\n",
    strpos( $kh_dir, $wp_dir ) === 0 ? "is inside" : "is OUTSIDE" );
'

echo
echo "Rig ready: $WP"
echo "Run it:    bash tests/rig/run-all.sh \"$WP\""
