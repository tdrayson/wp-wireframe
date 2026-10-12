#!/usr/bin/env bash
# Builds the release zip with release.yml's own PHP and packaging steps, installs
# copies as fake plugins (zip and Composer, versions 1.0.7 and 1.1.0), and checks
# that one PHP process resolves the framework to the newest copy.
#
# Needs php, composer, ruby (to read the workflow), rsync, zip and unzip.
# The JS build steps are skipped: src/assets is committed.

set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
here="$root/tests/multi-copy"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

set_version() { # <bootstrap.php> <version>
    sed -i.bak -E "s/^Versions::register\('[^']+'/Versions::register('$2'/" "$1"
    rm "$1.bak"
}

echo "Building the release zip in $work/repo"
rsync -a --exclude=.git --exclude=vendor --exclude=node_modules --exclude=build --exclude=wp-wireframe.zip "$root"/ "$work/repo/"
ruby -ryaml -e '
    wanted = ["Check bootstrap version matches tag", "Install PHP dependencies", "Stage release files", "Create zip"]
    steps = YAML.load_file(ARGV[0])["jobs"]["release"]["steps"].select { |s| wanted.include?(s["name"]) }
    abort "release.yml steps renamed; update tests/multi-copy/run.sh" if steps.size != wanted.size
    steps.each { |s| puts s["run"] }
' "$root/.github/workflows/release.yml" > "$work/release-steps.sh"
version="$(sed -nE "s/^Versions::register\('([^']+)'.*/\1/p" "$root/bootstrap.php")"
if ! (cd "$work/repo" && GITHUB_REF_NAME="v$version" bash -e "$work/release-steps.sh") > "$work/build.log" 2>&1; then
    cat "$work/build.log"
    exit 1
fi
zip="$work/repo/wp-wireframe.zip"

if unzip -Z1 "$zip" | grep -q '^wp-wireframe/vendor/composer/'; then
    echo "FAIL  zip ships vendor/composer/"
    exit 1
fi
echo "PASS  zip has no Composer autoloader; vendor/autoload.php is: $(unzip -p "$zip" wp-wireframe/vendor/autoload.php | tail -1)"

for copy in zip-a:1.0.7 zip-b:1.1.0 zip-c:1.1.0; do
    name="${copy%%:*}"
    mkdir -p "$work/plugins/$name/vendor"
    unzip -q "$zip" -d "$work/plugins/$name/vendor"
    set_version "$work/plugins/$name/vendor/wp-wireframe/bootstrap.php" "${copy##*:}"
done

for v in 1.0.7 1.1.0; do
    pkg="$work/packages/$v"
    mkdir -p "$pkg"
    rsync -a --exclude=vendor "$work/plugins/zip-a/vendor/wp-wireframe/" "$pkg/"
    set_version "$pkg/bootstrap.php" "$v"
    php -r '$f = $argv[1]; $j = json_decode(file_get_contents($f), true); $j["version"] = $argv[2];
        file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));' "$pkg/composer.json" "$v"

    plugin="$work/plugins/composer-$v"
    mkdir -p "$plugin"
    php -r 'echo json_encode([
        "name" => "acme/plugin",
        "repositories" => [["type" => "path", "url" => $argv[1], "options" => ["symlink" => false]]],
        "require" => ["tdrayson/wp-wireframe" => $argv[2]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);' "$pkg" "$v" > "$plugin/composer.json"
    composer install --working-dir "$plugin" --no-interaction --quiet --optimize-autoloader
done

zip_a="$work/plugins/zip-a/vendor/wp-wireframe/vendor/autoload.php"
zip_b="$work/plugins/zip-b/vendor/wp-wireframe/vendor/autoload.php"
zip_c="$work/plugins/zip-c/vendor/wp-wireframe/vendor/autoload.php"
composer_a="$work/plugins/composer-1.0.7/vendor/autoload.php"
composer_b="$work/plugins/composer-1.1.0/vendor/autoload.php"
zip_b_dir="$work/plugins/zip-b/vendor/wp-wireframe"
zip_c_dir="$work/plugins/zip-c/vendor/wp-wireframe"
zip_a_dir="$work/plugins/zip-a/vendor/wp-wireframe"
composer_b_dir="$work/plugins/composer-1.1.0/vendor/tdrayson/wp-wireframe"

status=0
run() { php "$here/scenario.php" "$@" || status=1; }

run "zip 1.0.7 then zip 1.1.0"                normal "$zip_b_dir" "$zip_a" "$zip_b"
run "zip 1.1.0 then zip 1.0.7"                normal "$zip_b_dir" "$zip_b" "$zip_a"
run "zip 1.1.0 twice (same version)"          normal "$zip_b_dir" "$zip_b" "$zip_c"
run "zip 1.1.0 twice, other order"            normal "$zip_c_dir" "$zip_c" "$zip_b"
run "class used before plugins_loaded"        early  "$zip_b_dir" "$zip_a" "$zip_b"
run "composer 1.1.0 then zip 1.0.7"           normal "$composer_b_dir" "$composer_b" "$zip_a"
run "zip 1.0.7 then composer 1.1.0"           normal "$composer_b_dir" "$zip_a" "$composer_b"
run "composer 1.0.7 then zip 1.1.0"           normal "$zip_b_dir" "$composer_a" "$zip_b"
run "composer 1.0.7 then composer 1.1.0"      normal "$composer_b_dir" "$composer_a" "$composer_b"
run "composer 1.1.0 then composer 1.0.7"      normal "$composer_b_dir" "$composer_b" "$composer_a"
run "all copies required during plugins_loaded" during "$zip_b_dir" "$zip_a" "$zip_b"
run "1.1.0 required after plugins_loaded"     after  "$zip_a_dir" "$zip_a" "$zip_b"

exit $status
