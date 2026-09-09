#!/usr/bin/env bash
#
# Build the installable WordPress plugin zip.
#
#   bash bin/build-zip.sh             → build/claudpress.zip
#   bash bin/build-zip.sh ~/Downloads → also copies it there
#
# WordPress expects a single top-level directory inside the archive matching the
# plugin folder name, so the plugin is staged under its own folder first. Only
# runtime files ship — tests, CI config, docs and local indexes stay out, and so
# do internal .md files (README.md documents the repo, not the plugin; readme.txt
# is the one WordPress reads).
#
# This script used to build two archives and police the boundary between them.
# There is one product now, so there is one archive and nothing to police.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"

STAGE="$BUILD/claudpress"
ZIP="$BUILD/claudpress.zip"

# Every skill in skills/ ships. The count is asserted rather than derived,
# because "copy the folder, then check the folder was copied" is a test that
# cannot fail — and a check that cannot fail is worse than no check, since it
# reads like cover. Adding a skill is a deliberate act that updates this number.
EXPECTED_SKILLS=18

VERSION="$(grep -oP "define\('CLAUDPRESS_VERSION',\s*'\K[^']+" "$ROOT/claudpress.php" || true)"
[ -n "$VERSION" ] || { echo "Could not read CLAUDPRESS_VERSION from claudpress.php" >&2; exit 1; }

echo "Building Claudpress $VERSION"

# Only what this script owns. "rm -rf $BUILD" would also delete skills.json
# and skills-bundle.json, which bin/build-release-assets.php writes into the
# same directory — and it did, silently, between signing them and uploading
# them to a release.
rm -rf "$STAGE"
rm -f  "$ZIP"
mkdir -p "$STAGE"

# ---- stage ------------------------------------------------------------------

cp "$ROOT/claudpress.php"  "$STAGE/"
cp "$ROOT/uninstall.php"   "$STAGE/"
cp "$ROOT/readme.txt"      "$STAGE/"
cp -r "$ROOT/includes"     "$STAGE/"
cp -r "$ROOT/skills"       "$STAGE/"
# The brand images. Small and few, but the admin menu icon is a URL into this
# directory: if it does not ship, every install gets a broken image in the
# sidebar, which looks like a broken plugin rather than a missing decoration.
cp -r "$ROOT/assets"       "$STAGE/"

# Belt and braces: strip anything that should never ship even if it was copied.
find "$BUILD" \( -name '.DS_Store' -o -name '*.log' -o -name '.env' \) -delete
rm -rf "$STAGE/.codegraph" "$STAGE/node_modules"

actual_skills="$(find "$STAGE/skills" -name '*.md' | wc -l | tr -d ' ')"
if [ "$actual_skills" != "$EXPECTED_SKILLS" ]; then
  echo "::error::staged $actual_skills skills, expected $EXPECTED_SKILLS." >&2
  echo "If you added or removed a skill on purpose, update EXPECTED_SKILLS in this script." >&2
  exit 1
fi
echo "Skills: $actual_skills staged."

# Named one by one, not counted. A count would pass if a file were renamed, and
# a renamed brand file is exactly the failure this guards: the plugin still
# installs, still works, and shows a broken image where the logo should be.
for img in mark-mono-40.png icon-128.png icon-256.png logo.png; do
  if [ ! -s "$STAGE/assets/img/$img" ]; then
    echo "::error::assets/img/$img missing from the staged zip." >&2
    echo "Claudpress_Brand builds admin URLs into that directory; a missing file is a broken image on every install." >&2
    exit 1
  fi
done
echo "Brand images: 4 staged."

# ---- archiving --------------------------------------------------------------
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
    "$PY" - "$BUILD" "$dir" "$out" <<'PYZIP'
import os, sys, zipfile
build, dir_name, out = sys.argv[1], sys.argv[2], sys.argv[3]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, _dirs, files in os.walk(os.path.join(build, dir_name)):
        for name in sorted(files):
            full = os.path.join(root, name)
            z.write(full, os.path.relpath(full, build).replace(os.sep, '/'))
PYZIP
  else
    echo "Need 'zip', PowerShell, or Python to build the archive." >&2
    exit 1
  fi
}

zip_dir claudpress "$ZIP"

if command -v unzip > /dev/null 2>&1; then
  entries="$(unzip -Z1 "$ZIP" | wc -l | tr -d ' ')"
else
  entries="$(find "$STAGE" -type f | wc -l | tr -d ' ')"
fi
echo "Built $ZIP — $entries entries, $(du -h "$ZIP" | cut -f1)"

# Optional: drop a copy wherever the caller asked for it.
if [ "${1:-}" != "" ]; then
  cp "$ZIP" "$1/claudpress.zip"
  echo "Copied the zip to $1"
fi
