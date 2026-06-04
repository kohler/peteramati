#!/bin/sh
# Build and test pa-jail in an ephemeral Linux container.
# The source tree is mounted read-only and built out-of-tree into a throwaway
# directory, so the host working tree is left untouched (no Linux .o files
# clobbering a native build). Prints compiler output and runs `make check`.
set -e
dir=$(cd "$(dirname "$0")" && pwd)
args=
if test "$PA_HAVE_CGROUP" = 0 -o "$PA_HAVE_CGROUP" = 1; then
    args="--env PA_HAVE_CGROUP=$PA_HAVE_CGROUP"
fi
echo docker run --rm -v "$dir:/src:ro" $args gcc:14 \
    sh -c 'mkdir -p /build && make -C /build -f /src/GNUmakefile pa-jail check'
exec docker run --rm -v "$dir:/src:ro" $args gcc:14 \
    sh -c 'mkdir -p /build && make -C /build -f /src/GNUmakefile pa-jail check'
