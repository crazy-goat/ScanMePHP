# Dev/test image for ScanMePHP on a supported PHP version.
# Matches the CI environment: PHP + ffi/gd extensions + composer + build tools
# for the C++ FFI library in clib/.
#
# Build:  docker build -t scanmephp-test --build-arg PHP_VERSION=8.4 docker
# Run:    docker run --rm -v "$PWD":/app scanmephp-test
#         docker run --rm -v "$PWD":/app scanmephp-test composer test
#         docker run --rm -it -v "$PWD":/app scanmephp-test bash

ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

# Build tools for the C++ FFI library (clib/) and FFI extension build deps,
# same as CI (which uses shivammathur/setup-php with ffi,gd extensions).
RUN apt-get update \
    && apt-get install -y --no-install-recommends cmake g++ make git libpng-dev libffi-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions used by the project and its tests.
# FFI needs libffi-dev and is built from source via docker-php-ext-install.
RUN docker-php-ext-install gd ffi

# Composer (cached layer; pinned to v2 for reproducibility).
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app

# Default action: build the FFI library and run the test suite,
# mirroring the CI "test" job.
CMD ["sh", "-c", "test -d clib/build || (cd clib && cmake -S . -B build -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTS=OFF && cmake --build build -j$(nproc)) && composer install --prefer-dist --no-progress && composer run-script test"]
