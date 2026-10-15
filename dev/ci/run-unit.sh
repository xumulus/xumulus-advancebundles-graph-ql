#!/usr/bin/env bash
# Run the PHPUnit unit suite (phpunit.xml.dist) with the toolchain from dev/ci/install.sh.
#
# Usage:
#   dev/ci/run-unit.sh [phpunit args...]
# To use an existing Magento install instead, with no Composer step:
#   XAB_AUTOLOAD=/var/www/html/vendor/autoload.php PHPUNIT_BIN=/var/www/html/vendor/bin/phpunit dev/ci/run-unit.sh
set -euo pipefail

CI_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$CI_DIR/../.." && pwd)"

PHPUNIT="${PHPUNIT_BIN:-}"
if [ -z "$PHPUNIT" ]; then
    "$CI_DIR/install.sh"
    PHPUNIT="$CI_DIR/vendor/bin/phpunit"
    export XAB_AUTOLOAD="$CI_DIR/vendor/autoload.php"
fi

cd "$ROOT"
php "$PHPUNIT" --configuration "$ROOT/phpunit.xml.dist" "$@"
