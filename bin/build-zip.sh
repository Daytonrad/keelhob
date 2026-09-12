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
#
# THE ZIP IS A FUNCTION OF A COMMIT, NOT OF SOMEBODY'S DESK.
#
# This used to `cp -r` out of the working tree, which meant whatever happened to
# be sitting in the checkout is what people installed: an uncommitted debug
# edit, a `class-admin.php.orig` left by a bad merge, an editor swapfile, a
# stale checkout's CRLF line endings. None of it would have been noticed.
#
# That matters more here than in most projects because these releases are
# SIGNED, and the entire promise of a signature is that the artefact is exactly
# what the source says it is. A build that quietly includes whatever was lying
# around breaks that promise before the signature is applied to it.
#
# `git archive` needs no new dependency and takes only tracked content at the
# given ref.
#
# PATHS SHIPPED — keep in step with the list in the header comment above.
PATHS=(claudpress.php uninstall.php readme.txt includes skills assets)
# assets is the brand images. Small and few, but the admin menu icon is a URL
# into that directory: if it does not ship, every install gets a broken image in
# the sidebar, which reads as a broken plugin rather than a missing decoration.

REF="${CLAUDPRESS_BUILD_REF:-HEAD}"

if git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1; then
    # Refuse a dirty tree by default. Warning instead would be friendlier, and
    # would also mean the one time it mattered it scrolled past unread. Someone
    # testing a local change can opt out; a release cannot do it by accident.
    if [ -n "$(git -C "$ROOT" status --porcelain)" ] && [ "${ALLOW_DIRTY:-0}" != "1" ]; then
        echo "Refusing to build: the working tree has uncommitted or untracked files." >&2
        echo "The zip is signed, so it must be exactly what the commit says it is." >&2
        echo >&2
        git -C "$ROOT" status --short >&2
        echo >&2
        echo "Commit or stash them, or run again with ALLOW_DIRTY=1 for a local test build." >&2
        exit 1
    fi
    [ "${ALLOW_DIRTY:-0}" = "1" ] && echo "  WARNING: ALLOW_DIRTY=1 — building from $REF, uncommitted changes are NOT included."

    echo "  staging from git ($REF)"
    git -C "$ROOT" archive --format=tar "$REF" "${PATHS[@]}" | tar -x -C "$STAGE"
else
    # Someone building from an extracted tarball has no .git. Falling back is
    # right — refusing would make the source archive unbuildable — but it is
    # loud, because this is the one path where the guarantee above does not hold.
    echo "  WARNING: not a git checkout. Falling back to copying the working tree."
    echo "  WARNING: the zip will contain whatever is in this directory."
    for path in "${PATHS[@]}"; do
        cp -r "$ROOT/$path" "$STAGE/"
    done
fi

# Belt and braces: strip anything that should never ship even if it was copied.
find "$BUILD" \( -name '.DS_Store' -o -name '*.log' -o -name '.env' \) -delete
rm -rf "$STAGE/.codegraph" "$STAGE/node_modules"

# Compared against the source folder, not against a number kept in this file.
# That number used to be the thing that made you notice you had added a skill,
# which was its real job - but the noticing has a better home now:
# bin/lint-skills.php has to pass, and bin/build-skill-router.php --check has to
# be current, before a skill can ship at all. What is left for this check to do
# is catch a copy that silently dropped a file, so it compares the two folders.
source_skills="$(find "$ROOT/skills" -name '*.md' | wc -l | tr -d ' ')"
actual_skills="$(find "$STAGE/skills" -name '*.md' | wc -l | tr -d ' ')"
if [ "$actual_skills" != "$source_skills" ]; then
  echo "::error::staged $actual_skills skills but skills/ holds $source_skills. The copy dropped something." >&2
  exit 1
fi
if [ "$actual_skills" -lt 1 ]; then
  echo "::error::no skills staged." >&2
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
