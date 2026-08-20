#!/usr/bin/env bash
#
# Build both installable WordPress plugin zips.
#
#   bash bin/build-zip.sh             → build/claudpress.zip + build/claudpress-pro.zip
#   bash bin/build-zip.sh ~/Downloads → also copies both there
#
# WordPress expects a single top-level directory inside the archive matching the
# plugin folder name, so each product is staged under its own folder first. Only
# runtime files ship — tests, CI config, docs and local indexes stay out, and so
# do internal .md files (README.md documents the repo, not the plugin; readme.txt
# is the one WordPress reads).
#
# The script kept its original name so CI and the README keep working; it now
# produces two archives rather than one, because the free/Pro split is only worth
# anything if both halves are cut from the same run and checked against each
# other in one place. See assert_free_zip_is_clean().

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"

FREE_STAGE="$BUILD/claudpress"
FREE_ZIP="$BUILD/claudpress.zip"
PRO_STAGE="$BUILD/claudpress-pro"
PRO_ZIP="$BUILD/claudpress-pro.zip"

VERSION="$(grep -oP "define\('CLAUDPRESS_VERSION',\s*'\K[^']+" "$ROOT/claudpress.php" || true)"
[ -n "$VERSION" ] || { echo "Could not read CLAUDPRESS_VERSION from claudpress.php" >&2; exit 1; }

PRO_VERSION="$(grep -oP "define\('CLAUDPRESS_PRO_VERSION',\s*'\K[^']+" "$ROOT/claudpress-pro/claudpress-pro.php" || true)"
[ -n "$PRO_VERSION" ] || { echo "Could not read CLAUDPRESS_PRO_VERSION from claudpress-pro/claudpress-pro.php" >&2; exit 1; }

echo "Building Claudpress $VERSION + Claudpress Pro $PRO_VERSION"

rm -rf "$BUILD"
mkdir -p "$FREE_STAGE" "$PRO_STAGE"

# ---- stage the free core ---------------------------------------------------

cp "$ROOT/claudpress.php"  "$FREE_STAGE/"
cp "$ROOT/uninstall.php"   "$FREE_STAGE/"
cp "$ROOT/readme.txt"      "$FREE_STAGE/"
cp -r "$ROOT/includes"     "$FREE_STAGE/"

# Skills are split by tier, and the catalogue is the single source of truth for
# which is which. Reading it here rather than keeping a second list in this
# script is deliberate: two lists drift, and the way this one would drift is by
# quietly shipping a paid skill in the free zip.
mkdir -p "$FREE_STAGE/skills" "$PRO_STAGE/skills"

free_skills="$(ROOT="$ROOT" php -r 'define("ABSPATH",1); require getenv("ROOT") . "/includes/class-skill-catalog.php"; echo implode(chr(10), Claudpress_Skill_Catalog::names("free"));')"
pro_skills="$(ROOT="$ROOT" php -r 'define("ABSPATH",1); require getenv("ROOT") . "/includes/class-skill-catalog.php"; echo implode(chr(10), Claudpress_Skill_Catalog::names("pro"));')"

while IFS= read -r name; do
  [ -n "$name" ] || continue
  [ -f "$ROOT/skills/$name.md" ] || { echo "::error::catalogue lists free skill '$name' but skills/$name.md does not exist" >&2; exit 1; }
  cp "$ROOT/skills/$name.md" "$FREE_STAGE/skills/"
done <<< "$(printf "%s" "$free_skills" | tr -d "")"

while IFS= read -r name; do
  [ -n "$name" ] || continue
  [ -f "$ROOT/skills/$name.md" ] || { echo "::error::catalogue lists Pro skill '$name' but skills/$name.md does not exist" >&2; exit 1; }
  cp "$ROOT/skills/$name.md" "$PRO_STAGE/skills/"
done <<< "$(printf "%s" "$pro_skills" | tr -d "")"

# Every skill on disk must be accounted for. A new skill that nobody tiered
# would otherwise vanish from both zips without a word.
for f in "$ROOT/skills/"*.md; do
  base="$(basename "$f" .md)"
  if [ ! -f "$FREE_STAGE/skills/$base.md" ] && [ ! -f "$PRO_STAGE/skills/$base.md" ]; then
    echo "::error::skills/$base.md is in neither zip - add it to includes/class-skill-catalog.php" >&2
    exit 1
  fi
done

# ---- stage the Pro add-on --------------------------------------------------

cp "$ROOT/claudpress-pro/claudpress-pro.php" "$PRO_STAGE/"
cp -r "$ROOT/claudpress-pro/includes"        "$PRO_STAGE/"
[ -f "$ROOT/claudpress-pro/readme.txt" ] && cp "$ROOT/claudpress-pro/readme.txt" "$PRO_STAGE/"

# Belt and braces: strip anything that should never ship even if it was copied.
find "$BUILD" \( -name '.DS_Store' -o -name '*.log' -o -name '.env' \) -delete
rm -rf "$FREE_STAGE/.codegraph" "$FREE_STAGE/node_modules" "$PRO_STAGE/.codegraph" "$PRO_STAGE/node_modules"

# ---- the guard -------------------------------------------------------------
#
# If Pro code ever ships inside the free zip, the entire reason for having two
# zips is void: the anti-piracy argument in the plan (D5) is not "the licence
# check is strong", it is "a free user has no Pro code on disk to crack". One
# stray `cp` silently hands every free user the thing they were meant to pay
# for, and nobody would notice for months. So this is a hard failure that stops
# the build, never a warning somebody scrolls past.
#
# Note the regex. A plain substring search for "claudpress-pro" also matches the
# free admin screen's `claudpress-provider-row` CSS classes, so the check
# requires the next character not to be a letter — which still catches
# "claudpress-pro/", "claudpress-pro.php", "claudpress-pro-licence" and the
# "claudpress-pro" text domain, while ignoring "claudpress-provider". A
# hypothetical free-core string like "claudpress-promote" would slip past; if
# one is ever added, tighten this rather than loosening it.
assert_free_zip_is_clean() {
  local failed=0

  if grep -rlEI 'claudpress-pro([^a-z]|$)' "$FREE_STAGE" > /dev/null 2>&1; then
    echo "::error::the free build contains the string 'claudpress-pro':" >&2
    grep -rlEI 'claudpress-pro([^a-z]|$)' "$FREE_STAGE" >&2
    failed=1
  fi

  if grep -rlI 'Claudpress_Pro_' "$FREE_STAGE" > /dev/null 2>&1; then
    echo "::error::the free build contains the Pro class prefix 'Claudpress_Pro_':" >&2
    grep -rlI 'Claudpress_Pro_' "$FREE_STAGE" >&2
    failed=1
  fi

  # Every file the add-on owns, by name, wherever it ended up in the free tree.
  local leaked
  leaked="$(cd "$ROOT/claudpress-pro" && find . -type f -printf '%f\n' | sort -u)"
  while IFS= read -r name; do
    [ -n "$name" ] || continue
    if find "$FREE_STAGE" -type f -name "$name" | grep -q .; then
      echo "::error::the free build contains a file from claudpress-pro/: $name" >&2
      failed=1
    fi
  done <<< "$leaked"

  if [ "$failed" -ne 0 ]; then
    echo "Refusing to ship a free zip containing Pro code." >&2
    exit 1
  fi
  echo "Guard: free build contains no Pro code."
}

assert_free_zip_is_clean

# ---- archiving -------------------------------------------------------------
# `zip` on CI and Linux/macOS; Python or PowerShell elsewhere, since Git Bash on
# Windows ships neither zip nor unzip.
PY=""
for candidate in python3 python; do
  if command -v "$candidate" > /dev/null 2>&1 && "$candidate" -c "import zipfile" > /dev/null 2>&1; then
    PY="$(command -v "$candidate")"
    break
  fi
done

# zip_dir <stage-dir-name> <output-zip>
zip_dir() {
  local dir="$1" out="$2"
  if command -v zip > /dev/null 2>&1; then
    ( cd "$BUILD" && zip -qr "$(basename "$out")" "$dir" )
  elif command -v powershell > /dev/null 2>&1 || command -v pwsh > /dev/null 2>&1; then
    local PS
    PS="$(command -v pwsh || command -v powershell)"
    "$PS" -NoProfile -Command "Compress-Archive -Path '$(cygpath -w "$BUILD/$dir" 2>/dev/null || echo "$BUILD/$dir")' -DestinationPath '$(cygpath -w "$out" 2>/dev/null || echo "$out")' -Force"
  elif [ -n "$PY" ]; then
    "$PY" - "$BUILD" "$dir" "$out" <<'PY'
import os, sys, zipfile
build, dir_name, out = sys.argv[1], sys.argv[2], sys.argv[3]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, _dirs, files in os.walk(os.path.join(build, dir_name)):
        for name in sorted(files):
            full = os.path.join(root, name)
            z.write(full, os.path.relpath(full, build).replace(os.sep, '/'))
PY
  else
    echo "Need 'zip', PowerShell, or Python to build the archive." >&2
    exit 1
  fi
}

report() {
  local stage="$1" out="$2" entries size
  if command -v unzip > /dev/null 2>&1; then
    entries="$(unzip -Z1 "$out" | wc -l | tr -d ' ')"
  else
    entries="$(find "$stage" -type f | wc -l | tr -d ' ')"
  fi
  size="$(du -h "$out" | cut -f1)"
  echo "Built $out — $entries entries, $size"
}

zip_dir claudpress     "$FREE_ZIP"
zip_dir claudpress-pro "$PRO_ZIP"

# The staged tree was checked above; check the archive too, because the archive
# is what actually ships and the two are only the same thing if zip_dir behaved.
if command -v unzip > /dev/null 2>&1; then
  if unzip -Z1 "$FREE_ZIP" | grep -Eq 'claudpress-pro([^a-z]|$)'; then
    echo "::error::build/claudpress.zip lists a claudpress-pro path." >&2
    exit 1
  fi
fi

report "$FREE_STAGE" "$FREE_ZIP"
report "$PRO_STAGE"  "$PRO_ZIP"

# Optional: drop copies wherever the caller asked for them.
if [ "${1:-}" != "" ]; then
  cp "$FREE_ZIP" "$1/claudpress.zip"
  cp "$PRO_ZIP"  "$1/claudpress-pro.zip"
  echo "Copied both zips to $1"
fi
