#!/usr/bin/env bash
set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

find "${plugin_root}" -name '*.php' -not -path '*/tests/*' -print0 |
  xargs -0 -n1 php -l
php "${plugin_root}/tests/behavior.php"
"${plugin_root}/bin/build-zip.sh"
first_archive="$(mktemp)"
trap 'rm -f "${first_archive}"' EXIT
cp "${plugin_root}/dist/spacefast-wordpress.zip" "${first_archive}"
"${plugin_root}/bin/build-zip.sh"
cmp "${first_archive}" "${plugin_root}/dist/spacefast-wordpress.zip"
