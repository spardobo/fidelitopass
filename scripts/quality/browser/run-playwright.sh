#!/usr/bin/env sh
set -eu

readonly PLAYWRIGHT_IMAGE='mcr.microsoft.com/playwright:v1.64.0-noble@sha256:06a9939e57531807f8d5fd76ce44b53165ffb7d7501d87ab10e285c20b1e971f'

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
