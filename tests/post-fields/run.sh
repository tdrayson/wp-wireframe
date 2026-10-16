#!/usr/bin/env bash
# Integration tests for post fields. Runs each tests/post-fields/<name>.test.php
# with `wp eval-file` against a throwaway WordPress site in build/post-fields-wp.
#
#   run.sh [name...]   run the named tests (all of them if none given)
#   run.sh --serve     serve the test site on http://localhost:8890 (admin / admin)
#   run.sh --reset     drop the database and reinstall the site
#
# Needs php, composer, wp-cli and a MySQL server on 127.0.0.1 (user root, no password).

set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
here="$root/tests/post-fields"
site="$root/build/post-fields-wp"
db="wf_post_fields"

wpc() { wp --path="$site" "$@"; }

if [[ "${1:-}" == "--reset" ]]; then
    mysql -h127.0.0.1 -uroot -e "DROP DATABASE IF EXISTS \`$db\`"
    rm -rf "$site"
    shift
fi

if [[ ! -d "$root/vendor" ]]; then
    composer install --working-dir "$root" --no-interaction --quiet
fi

if [[ ! -f "$site/wp-config.php" ]]; then
    mkdir -p "$site"
    wp core download --path="$site" --quiet
    mysql -h127.0.0.1 -uroot -e "CREATE DATABASE IF NOT EXISTS \`$db\`"
    wpc config create --dbname="$db" --dbuser=root --dbpass= --dbhost=127.0.0.1 --skip-check --quiet
    wpc config set WP_DEBUG true --raw --quiet
fi

if ! wpc core is-installed 2>/dev/null; then
    wpc core install --url=http://localhost:8890 --title="Post Fields" \
        --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
fi

plugins="$site/wp-content/plugins"
ln -sfn "$root" "$plugins/wp-wireframe"
ln -sfn "$here/fixture" "$plugins/wf-fixture"
wpc plugin activate wf-fixture --quiet

if [[ "${1:-}" == "--serve" ]]; then
    exec wp --path="$site" server --port=8890
fi

if [[ $# -gt 0 ]]; then
    names=("$@")
else
    names=()
    for file in "$here"/*.test.php; do
        name="$(basename "$file" .test.php)"
        names+=("$name")
    done
fi

status=0
for name in "${names[@]}"; do
    echo "== $name"
    wpc eval-file "$here/$name.test.php" || status=1
done

exit $status
