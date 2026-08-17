#!/usr/bin/env bash
# Convenience wrapper to build/run the ScanMePHP test container.
#
# Usage:
#   docker/test.sh              # build (if needed) and run the test suite
#   docker/test.sh build        # build the image only
#   docker/test.sh shell        # drop into an interactive shell in the container
#   docker/test.sh <cmd...>     # run an arbitrary command, e.g. ./test.sh composer install
#
# Override the PHP version with SCANMEPHP_PHP, e.g. SCANMEPHP_PHP=8.3 ./docker/test.sh

set -euo pipefail

PHP_VERSION="${SCANMEPHP_PHP:-8.4}"
IMAGE="scanmephp-test:${PHP_VERSION}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

build() {
  docker build -t "$IMAGE" --build-arg "PHP_VERSION=${PHP_VERSION}" "$ROOT_DIR/docker"
}

run() {
  docker run --rm \
    -v "$ROOT_DIR":/app \
    -e SCANMEPHP_PHP="$PHP_VERSION" \
    "$IMAGE" "$@"
}

case "${1:-run}" in
  build) build ;;
  shell) run bash ;;
  run)   build; run ;;
  *)     build; run "$@" ;;
esac
