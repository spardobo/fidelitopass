#!/usr/bin/env sh
set -eu

# reset cached Laravel configuration before analyzing the application
./vendor/bin/sail artisan config:clear --ansi

# check PHP formatting and static analysis
./vendor/bin/sail composer check:format
./vendor/bin/sail composer check:lint

# run focused PHP Unit-suite tests
./vendor/bin/sail composer test:unit

# check JavaScript formatting and linting independently
./vendor/bin/sail npm run check:format
./vendor/bin/sail npm run check:lint

# test the documentation validator before applying it to the repository
./vendor/bin/sail npm run test:documentation-validator
./vendor/bin/sail npm run check:documentation
