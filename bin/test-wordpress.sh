#!/usr/bin/env bash
set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${plugin_root}/tests/acceptance/compose.yml"
project_name="spacefast-wordpress-acceptance"

cleanup() {
  docker compose --project-name "${project_name}" --file "${compose_file}" down --volumes --remove-orphans
}
trap cleanup EXIT

docker compose --project-name "${project_name}" --file "${compose_file}" up --detach --wait db wordpress
docker compose --project-name "${project_name}" --file "${compose_file}" run --rm wpcli \
  wp core install --url=http://wordpress --title=Spacefast --admin_user=admin \
  --admin_password=acceptance-password --admin_email=acceptance@example.test
docker compose --project-name "${project_name}" --file "${compose_file}" run --rm wpcli \
  wp plugin activate spacefast-wordpress
docker compose --project-name "${project_name}" --file "${compose_file}" run --rm wpcli \
  wp eval-file wp-content/plugins/spacefast-wordpress/tests/acceptance/wordpress.php
