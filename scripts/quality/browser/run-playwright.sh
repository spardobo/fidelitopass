#!/usr/bin/env sh
set -eu

readonly PLAYWRIGHT_IMAGE='mcr.microsoft.com/playwright:v1.64.1-noble@sha256:47b510dd9230bf0b9bfc9b20102241e577ea7a99334ab4c3ca19f19770e9b8ea'

repository_root=$(cd -- "$(dirname -- "$0")/../../.." && pwd)
script=${1:-test:e2e}

if [ "$#" -gt 0 ]; then
    shift
fi

# the image version matches @playwright/test and owns every browser dependency.
docker run --rm --pull=never --init --ipc=host \
    --user "$(id -u):$(id -g)" \
    --env HOME=/tmp \
    --env CI="${CI:-}" \
    --env PLAYWRIGHT_BASE_URL \
    --env PLAYWRIGHT_MAILPIT_URL \
    --env PLAYWRIGHT_PROMOTION_FIXTURE \
    --env NPM_CONFIG_UPDATE_NOTIFIER=false \
    --network fidelitopass-network \
    --volume "$repository_root:/work" \
    --workdir /work \
    "$PLAYWRIGHT_IMAGE" \
    npm run "$script" -- "$@"
