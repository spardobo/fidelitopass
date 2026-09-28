#!/usr/bin/env sh
set -eu

readonly PLAYWRIGHT_IMAGE='mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27'

repository_root=$(cd -- "$(dirname -- "$0")/../../.." && pwd)
script=${1:-test:e2e}

if [ "$#" -gt 0 ]; then
    shift
fi

# the image version matches @playwright/test and owns every browser dependency.
docker run --rm --init --ipc=host \
    --user "$(id -u):$(id -g)" \
    --env HOME=/tmp \
    --env CI="${CI:-}" \
    --env NPM_CONFIG_UPDATE_NOTIFIER=false \
    --network fidelitopass-network \
    --volume "$repository_root:/work" \
    --workdir /work \
    "$PLAYWRIGHT_IMAGE" \
    npm run "$script" -- "$@"
