#!/usr/bin/env bash
#
# Build a WordPress-installable ZIP with exactly one root folder:
#   plugin-conflict-debugger/
#
# This script is intentionally deterministic:
# - fixed slug, never derived from the current directory
# - clean staging area on every run
# - recursive copies that preserve folder structure
# - zip is created from the parent directory so the root folder is included

set -euo pipefail

SLUG="plugin-conflict-debugger"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
DIST_DIR="${ROOT_DIR}/build"
STAGING_ROOT="${BUILD_DIR}/${SLUG}"
ZIP_PATH="${DIST_DIR}/${SLUG}.zip"

# Files and folders expected at the project root.
# Keep this explicit so packaging stays predictable.
INCLUDE_ITEMS=(
  "plugin-conflict-debugger.php"
  "includes"
  "assets"
  "languages"
  "uninstall.php"
  "readme.txt"
)

EXCLUDES=(
  ".git"
  "node_modules"
  "dist"
  "build"
  ".DS_Store"
  "*.log"
  "*.map"
)

echo "Preparing clean build directories..."
rm -rf "${STAGING_ROOT}" "${ZIP_PATH}"
mkdir -p "${BUILD_DIR}" "${DIST_DIR}" "${STAGING_ROOT}"

copy_item() {
  local source="$1"
  local destination="$2"

  if [[ ! -e "${source}" ]]; then
    echo "Missing required item: ${source}" >&2
    exit 1
  fi

  if [[ -d "${source}" ]]; then
    mkdir -p "${destination}"

    if command -v rsync >/dev/null 2>&1; then
      local rsync_args=(-a --delete)
      for pattern in "${EXCLUDES[@]}"; do
        rsync_args+=(--exclude="${pattern}")
      done
      rsync "${rsync_args[@]}" "${source}/" "${destination}/"
    else
      cp -R "${source}/." "${destination}/"
      find "${destination}" \
        \( -name ".DS_Store" -o -name "*.log" -o -name "*.map" \) -type f -delete
      rm -rf \
        "${destination}/.git" \
        "${destination}/node_modules" \
        "${destination}/dist" \
        "${destination}/build"
    fi
  else
    mkdir -p "$(dirname "${destination}")"
    cp "${source}" "${destination}"
  fi
}

echo "Copying plugin files into staging area..."
for item in "${INCLUDE_ITEMS[@]}"; do
  src="${ROOT_DIR}/${item}"
  dst="${STAGING_ROOT}/${item}"
  copy_item "${src}" "${dst}"
done

echo "Creating ZIP from the parent directory so the root folder is preserved..."
(
  cd "${BUILD_DIR}"
  zip -rq "${ZIP_PATH}" "${SLUG}"
)

echo "Build complete:"
echo "  ${ZIP_PATH}"

echo "Archive root preview:"
unzip -l "${ZIP_PATH}" | sed -n '1,20p'
