#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "$0")/../../.."
readonly app_service=fidelitopass-laravel-app
readonly runtime="/tmp/fidelitopass-browser-$$"
readonly mail_container="fidelitopass-browser-mail-$$"
readonly port=8017
readonly base_url="http://${app_service}:${port}"
readonly fixture=scripts/quality/browser/promotion-fixture.php
server_started=false
launcher_pid=
mail_started=false
testing_reserved=false

# the lock serializes this wrapper; other PHP suites must remain stopped.
exec 9>/tmp/fidelitopass-browser-testing.lock
flock -n 9 || { echo 'Another isolated browser run owns testing.' >&2; exit 1; }

before=$(./vendor/bin/sail php "$fixture" development-fingerprint)
mail_image=$(./vendor/bin/sail images -q fidelitopass-mailpit-dev)
test -n "$mail_image" || { echo 'The existing Mailpit image is unavailable.' >&2; exit 1; }

./vendor/bin/sail exec -T -u sail "$app_service" mkdir "$runtime"
./vendor/bin/sail exec -T -u sail "$app_service" mkdir -p \
    "$runtime/framework/views" "$runtime/framework/sessions" "$runtime/logs" "$runtime/cache"

# environment overrides belong only to these isolated PHP processes.
app() {
    ./vendor/bin/sail exec -T -u sail "$app_service" env \
        APP_ENV=testing APP_DEBUG=false APP_URL="$base_url" DB_DATABASE=testing DB_URL= \
        SESSION_DRIVER=database SESSION_COOKIE=fidelitopass_browser_testing SESSION_SECURE_COOKIE=false \
        CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=smtp MAIL_SCHEME=smtp \
        MAIL_URL="smtp://${mail_container}:1025" \
        MAIL_HOST="$mail_container" MAIL_PORT=1025 MAIL_USERNAME= MAIL_PASSWORD= \
        LARAVEL_STORAGE_PATH="$runtime" VIEW_COMPILED_PATH="$runtime/framework/views" \
        APP_CONFIG_CACHE="$runtime/cache/config.php" APP_ROUTES_CACHE="$runtime/cache/routes.php" \
        APP_SERVICES_CACHE="$runtime/cache/services.php" APP_PACKAGES_CACHE="$runtime/cache/packages.php" \
        APP_EVENTS_CACHE="$runtime/cache/events.php" php "$@"
}

cleanup() {
    local outcome=$?
    trap - EXIT INT TERM
    if "$server_started"; then
        if app "$fixture" stop; then
            wait "$launcher_pid" || true
        else
            echo 'Listener shutdown failed; preserve testing and runtime for inspection.' >&2
            exit 1
        fi
    fi
    if "$mail_started"; then docker stop "$mail_container" >/dev/null || outcome=1; fi
    if "$testing_reserved"; then app "$fixture" reset || outcome=1; fi
    local after
    after=$(./vendor/bin/sail php "$fixture" development-fingerprint) || outcome=1
    if test "$before" != "$after"; then
        echo 'Development fingerprint changed; preserve runtime diagnostics and investigate.' >&2
        outcome=1
    else
        echo "Development unchanged: $after"
        ./vendor/bin/sail exec -T -u sail "$app_service" rm -rf -- "$runtime" || outcome=1
        if test "$outcome" -eq 0; then
            rm -f -- "/tmp/fidelitopass-browser-$$.log"
        fi
        echo 'Owned runtime resources cleaned; development fingerprint verified.'
    fi
    exit "$outcome"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# probe the port before rebuilding testing or starting anything.
app -r '$s = @stream_socket_server("tcp://0.0.0.0:8017", $code, $error); if (!$s) { fwrite(STDERR, "Browser port unavailable\n"); exit(1); } fclose($s);'
app "$fixture" guard
testing_reserved=true
app "$fixture" reset
export PLAYWRIGHT_PROMOTION_FIXTURE
PLAYWRIGHT_PROMOTION_FIXTURE=$(app "$fixture" seed)

docker run --detach --rm --pull=never --name "$mail_container" \
    --network fidelitopass-network "$mail_image" >/dev/null
mail_started=true
app "$fixture" serve >"/tmp/fidelitopass-browser-$$.log" 2>&1 &
launcher_pid=$!
server_started=true
app "$fixture" ready

export PLAYWRIGHT_BASE_URL="$base_url"
export PLAYWRIGHT_MAILPIT_URL="http://${mail_container}:8025"
scripts/quality/browser/run-playwright.sh test:e2e \
    tests/Browser/business-promotion-draft.spec.js --project=chromium --workers=1 "$@"
app "$fixture" verify
