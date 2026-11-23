#!/bin/sh
# Lumino HRM cron runner for Hostinger shared hosting. POSIX sh.
#
# One cron entry, every minute:
#   * * * * * sh /home/u882878513/domains/hrm.luminocrm.online/public_html/deploy/hostinger/cron.sh
#
# Each run (idempotent, never overlaps itself):
#   1. makes sure ../shared/storage/... exists (outside the document root);
#   2. waits (exit 0) until ../shared/.env exists;
#   3. generates APP_KEY if it is empty;
#   4. after a deploy (release.txt differs from shared/release.done, or .env is
#      newer than release.done): config:clear (so migrate sees the current
#      shared/.env, not a stale cached config), migrate, config:cache,
#      route:cache, view:cache;
#   5. artisan schedule:run.
# Everything is appended to shared/storage/logs/cron.log. Nothing is printed
# (cron would mail it) and env values are never echoed.
#
# PHP_BIN overrides the PHP binary (used by the tests).

PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
NO_COLOR=1   # plain text in the log (Symfony Console honours NO_COLOR)
export NO_COLOR
LOG_MAX_BYTES="${LOG_MAX_BYTES:-1000000}"
LOCK_STALE_MINUTES="${LOCK_STALE_MINUTES:-30}"

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd) || exit 1
APP_ROOT=$(cd "$SCRIPT_DIR/../.." && pwd) || exit 1
SHARED="$(dirname "$APP_ROOT")/shared"
STORAGE="$SHARED/storage"
ENV_FILE="$SHARED/.env"
RELEASE_FILE="$SCRIPT_DIR/release.txt"
DONE_FILE="$SHARED/release.done"
LOCK_DIR="$SHARED/cron.lock"
LOG_DIR="$STORAGE/logs"
LOG_FILE="$LOG_DIR/cron.log"
OUT_FILE="$SHARED/cron.out.$$"

# 1. Shared layout (idempotent).
for d in app/private app/public framework/cache/data framework/sessions framework/views logs; do
    mkdir -p "$STORAGE/$d" || exit 1
done

log() {
    printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >> "$LOG_FILE"
}

# Indent captured command output into the log.
log_output() {
    [ -s "$OUT_FILE" ] && sed 's/^/    | /' "$OUT_FILE" >> "$LOG_FILE"
}

# Size-based rotation: keep one previous file.
if [ -f "$LOG_FILE" ]; then
    size=$(wc -c < "$LOG_FILE" | tr -d ' ')
    if [ "${size:-0}" -gt "$LOG_MAX_BYTES" ]; then
        mv -f "$LOG_FILE" "$LOG_FILE.1"
    fi
fi

# From here on, anything written to stdout/stderr (even by a failing redirect)
# goes to the log, so cron has nothing to mail.
exec >> "$LOG_FILE" 2>&1

# Lock: mkdir is atomic. A lock is stale if its PID is gone or it is very old.
acquire_lock() {
    if mkdir "$LOCK_DIR" 2>/dev/null; then
        echo $$ > "$LOCK_DIR/pid"
        return 0
    fi
    old_pid=$(cat "$LOCK_DIR/pid" 2>/dev/null)
    stale=0
    if [ -n "$old_pid" ]; then
        kill -0 "$old_pid" 2>/dev/null || stale=1
    else
        # No PID yet: the other run may be just starting; only old locks are stale.
        stale=0
    fi
    if [ -n "$(find "$LOCK_DIR" -maxdepth 0 -mmin "+$LOCK_STALE_MINUTES" 2>/dev/null)" ]; then
        stale=1
    fi
    if [ "$stale" = 1 ]; then
        log "removing stale lock (pid ${old_pid:-unknown})"
        rm -rf "$LOCK_DIR"
        if mkdir "$LOCK_DIR" 2>/dev/null; then
            echo $$ > "$LOCK_DIR/pid"
            return 0
        fi
    fi
    return 1
}

if ! acquire_lock; then
    exit 0   # another run is active; it will do the work
fi
trap 'rm -rf "$LOCK_DIR" "$OUT_FILE"' EXIT
trap 'exit 1' HUP INT TERM

# Run one artisan command; output is captured in $OUT_FILE.
artisan() {
    (cd "$APP_ROOT" && "$PHP_BIN" "$APP_ROOT/artisan" "$@") > "$OUT_FILE" 2>&1
}

# 2. Wait for the environment file (created separately, never by a deploy).
if [ ! -f "$ENV_FILE" ]; then
    log "waiting for shared/.env (not created yet); nothing to do"
    exit 0
fi

# 3. APP_KEY. artisan key:generate writes to the environment file the app
#    loads, which is shared/.env (see bootstrap/app.php).
key=$(grep '^APP_KEY=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d "\"' \r")
if [ -z "$key" ]; then
    grep -q '^APP_KEY=' "$ENV_FILE" || printf '\nAPP_KEY=\n' >> "$ENV_FILE"
    log "APP_KEY is empty: running key:generate"
    if artisan key:generate --force; then
        log "APP_KEY generated"
        rm -f "$DONE_FILE"   # cached config would hold the old (empty) key
    else
        log "key:generate FAILED"
        log_output
        exit 0
    fi
fi

# 4. Post-deploy step.
post_deploy_ok=1
release=''
done_id=''
[ -f "$RELEASE_FILE" ] && release=$(tr -d ' \r\n' < "$RELEASE_FILE")
[ -f "$DONE_FILE" ] && done_id=$(tr -d ' \r\n' < "$DONE_FILE")
need=0
if [ -n "$release" ]; then
    [ "$release" != "$done_id" ] && need=1
    # shared/.env edited after the last successful run: config is cached, rebuild it.
    [ -f "$DONE_FILE" ] && [ "$ENV_FILE" -nt "$DONE_FILE" ] && need=1
else
    log "no deploy/hostinger/release.txt; skipping post-deploy step"
fi

if [ "$need" = 1 ]; then
    log "post-deploy for release $release"
    for cmd in "config:clear" "migrate --force" "config:cache" "route:cache" "view:cache"; do
        # shellcheck disable=SC2086
        if artisan $cmd; then
            log "ok: artisan $cmd"
        else
            log "FAILED: artisan $cmd (release $release stays pending, will retry)"
            log_output
            post_deploy_ok=0
            break
        fi
    done
    if [ "$post_deploy_ok" = 1 ]; then
        echo "$release" > "$DONE_FILE"
        log "release $release done"
    fi
fi

# 5. Scheduler (skipped while a post-deploy step is failing: schema may be stale).
if [ "$post_deploy_ok" = 1 ]; then
    if artisan schedule:run; then
        # Only log when something actually ran.
        grep -qi 'no scheduled commands' "$OUT_FILE" || { log "schedule:run"; log_output; }
    else
        log "FAILED: artisan schedule:run"
        log_output
    fi
else
    log "schedule:run skipped (post-deploy failing)"
fi

exit 0
