#!/usr/bin/env bash
# The Marketplace gate: Magento2 coding standard over the shipped files, errors at severity 10 must be 0.
#
# Usage:
#   dev/ci/run-phpcs.sh [extra phpcs args...]        # e.g. -s to show sniff codes
#   PHPCS_BIN=/var/www/html/vendor/bin/phpcs dev/ci/run-phpcs.sh   # use an existing install, no Composer step
set -euo pipefail

CI_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$CI_DIR/../.." && pwd)"

PHPCS="${PHPCS_BIN:-}"
if [ -z "$PHPCS" ]; then
    composer install \
        --working-dir="$CI_DIR/phpcs" \
        --no-interaction \
        --no-progress \
        --prefer-dist
    PHPCS="$CI_DIR/phpcs/vendor/bin/phpcs"
fi

cd "$ROOT"
# dev/ holds repository-only tooling (export-ignored, not shipped).
php "$PHPCS" \
    --standard=Magento2 \
    --extensions=php,phtml,graphqls \
    --error-severity=10 \
    --ignore-annotations \
    --ignore='*/dev/*,*/.phpunit.cache/*,*/node_modules/*' \
    --report-width=120 \
    -q \
    -n \
    "$@" \
    .
