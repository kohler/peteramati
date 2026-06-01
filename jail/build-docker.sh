#!/bin/sh
# Build and test pa-jail in an ephemeral Linux container.
# The source tree is mounted read-only and copied into a throwaway build
# directory, so the host working tree is left untouched (no Linux .o files
# clobbering a native build). Prints compiler output and runs `make check`.
set -e
dir=$(cd "$(dirname "$0")" && pwd)
exec docker run --rm -v "$dir:/src:ro" gcc:14 \
    sh -c 'cp -a /src/. /build && cd /build && make clean pa-jail check'
