#!/usr/bin/env sh
set -eu

# run the fast local baseline once before publishing commits
./scripts/quality/gates/pre-commit.sh

# run the canonical unfiltered PHP suite
./vendor/bin/sail composer test

# run the complete Node tooling test suite
./vendor/bin/sail npm test

# reject vulnerable dependencies and secrets in reachable Git history
./scripts/quality/security/audit-dependencies.sh
./scripts/quality/security/scan-git-secrets.sh

# verify production asset compilation
./vendor/bin/sail npm run build
