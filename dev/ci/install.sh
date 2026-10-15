#!/usr/bin/env bash
# Install the CI toolchain in dev/ci/vendor: the module's Magento dependencies from the public Mage-OS mirror,
# the Xumulus packages it builds on, and PHPUnit. Those Xumulus packages are private repositories, so they are
# read from checkouts in dev/ci/deps/ (git-ignored), which CI clones with read-only deploy keys:
#   dev/ci/deps/main      a checkout of xumulus/xumulus-advanced-bundles (xumulus/xumulus-advancebundles)
#
# Composer picks the newest Magento that the running PHP supports (PHP 8.1: 2.4.7, PHP 8.4: 2.4.9), so there is
# no committed lock file; when you switch PHP versions locally, run with XAB_CI_UPDATE=1.
#
# Usage: dev/ci/install.sh
set -euo pipefail

CI_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for dep in main; do
    if [ ! -f "$CI_DIR/deps/$dep/composer.json" ]; then
        echo "Missing dev/ci/deps/$dep: clone it there first (see the comment at the top of this script)." >&2
        exit 1
    fi
done

# dev/ci/composer.json lists the module's dependencies again; fail fast when the two lists drift apart.
php -r '
    $module = json_decode(file_get_contents($argv[1]), true)["require"] ?? [];
    $ci = json_decode(file_get_contents($argv[2]), true)["require"] ?? [];
    ksort($module);
    ksort($ci);
    if ($module !== $ci) {
        fwrite(STDERR, "dev/ci/composer.json \"require\" differs from the module composer.json \"require\":\n"
            . json_encode(["module" => $module, "dev/ci" => $ci], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
' "$CI_DIR/../../composer.json" "$CI_DIR/composer.json"

if [ "${XAB_CI_UPDATE:-0}" = "1" ]; then
    rm -f "$CI_DIR/composer.lock"
fi

composer install \
    --working-dir="$CI_DIR" \
    --no-interaction \
    --no-progress \
    --prefer-dist

php -v | head -1
for package in magento/framework phpunit/phpunit; do
    printf '%-24s %s\n' "$package" \
        "$(composer show --working-dir="$CI_DIR" "$package" 2>/dev/null | sed -n 's/^versions *: *//p')"
done
