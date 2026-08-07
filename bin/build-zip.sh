#!/usr/bin/env bash
set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "${stage}"' EXIT

mkdir -p "${stage}/spacefast-wordpress" "${plugin_root}/dist"
cp "${plugin_root}/spacefast-wordpress.php" "${plugin_root}/uninstall.php" \
  "${plugin_root}/readme.txt" "${plugin_root}/README.md" "${plugin_root}/LICENSE" \
  "${stage}/spacefast-wordpress/"
cp -R "${plugin_root}/includes" "${stage}/spacefast-wordpress/includes"

find "${stage}/spacefast-wordpress" -type f -exec chmod 0644 {} +
find "${stage}/spacefast-wordpress" -exec touch -t 202601010000 {} +
(
  cd "${stage}"
  archive="${stage}/spacefast-wordpress.zip"
  # Store entries without deflate so different zip/zlib builds produce the
  # same release artifact from the same normalized files.
  find spacefast-wordpress -type f -print | LC_ALL=C sort | zip -0 -X -q \
    "${archive}" -@
  cp "${archive}" "${plugin_root}/dist/spacefast-wordpress.zip"
)

unzip -tq "${plugin_root}/dist/spacefast-wordpress.zip"
printf 'Built %s\n' "${plugin_root}/dist/spacefast-wordpress.zip"
