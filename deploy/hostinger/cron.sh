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
#      newer than release.done) AND the uploaded tree is complete (see below):
#      config:clear (so migrate sees the current shared/.env, not a stale cached
#      config), migrate, hrm:sync-access (roles, permissions, first Admin from
#      HRM_ADMIN_*; reads the freshly cleared config, so it runs BEFORE config:cache and
#      sees a newly seeded env), config:cache, route:cache, view:cache. The .env-newer-
#      than-release.done path runs this same full sequence;
#   5. artisan schedule:run.
# Everything is appended to shared/storage/logs/cron.log. Nothing is printed
# (cron would mail it) and env values are never echoed.
#
# Upload completeness (new releases only): scripts/deploy.ps1 writes
# deploy/hostinger/release.manifest = "release <id>" followed by the relative path
# of EVERY file it staged. A new release (release.txt differs from release.done) is
# processed only when the manifest names the same id, every listed path exists, and
# that was true on CONFIRM_RUNS (default 2) consecutive cron runs for the same id
# (state in shared/release.pending; no file mtimes are used). Otherwise the run logs
# why ("upload still in progress: ...") and tries again next minute. Once a release
# is done (release.txt == release.done) the manifest is never consulted, so the
# scheduler and the .env-triggered config rebuild are not affected by it.
#
# Env seed: there is no file-write API for shared/, so deploy.ps1 -SeedEnvFile can
# stage deploy/hostinger/env.seed. When it exists, this script installs it as
# shared/.env (mode 600, atomically; an empty APP_KEY in the seed keeps the existing
# key), deletes the seed and logs one line without values, but only when the seed
# ends with "# seed-end <release id>" (same id as release.txt) and has a DB_PASSWORD;
# otherwise it logs "seed incomplete or invalid, waiting" and changes nothing. The
# seed is not listed in release.manifest. A newer .env triggers the config rebuild in the same run.
#
# Status (for reading through the hosting file API, where shared/ is out of reach):
# every run that holds the lock ends by writing deploy/hostinger/status.txt (key=value,
# no secrets, no command output) and status.log (the last 40 lines this script wrote
# itself; raw artisan output, which is indented, is never copied).
#
# Environment overrides (used by the tests): PHP_BIN, LOG_MAX_BYTES,
# LOCK_STALE_MINUTES, CONFIRM_RUNS.
#
# The whole flow is inside main() and invoked on the last line, so the shell has
# parsed the entire file before running it: a deploy that replaces this file
# while it runs cannot make sh read garbage at stale offsets.

PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
LOG_MAX_BYTES="${LOG_MAX_BYTES:-1000000}"
LOCK_STALE_MINUTES="${LOCK_STALE_MINUTES:-30}"
CONFIRM_RUNS="${CONFIRM_RUNS:-2}"
NO_COLOR=1   # plain text in the log (Symfony Console honours NO_COLOR)
export NO_COLOR

log() {
    printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >> "$LOG_FILE"
}

# Indent captured command output into the log.
log_output() {
    [ -s "$OUT_FILE" ] && sed 's/^/    | /' "$OUT_FILE" >> "$LOG_FILE"
}

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

# Run one artisan command; output is captured in $OUT_FILE.
artisan() {
    (cd "$APP_ROOT" && "$PHP_BIN" "$APP_ROOT/artisan" "$@") > "$OUT_FILE" 2>&1
}

# Is the uploaded tree ready for new release $1? Sets $not_ready_reason when not.
# Ready = manifest matches the release, every listed path exists, and that held
# for CONFIRM_RUNS consecutive runs. Uses only shell built-ins per path.
release_ready() {
    want="$1"
    not_ready_reason=''
    CR=$(printf '\r')
    if [ ! -f "$MANIFEST_FILE" ]; then
        rm -f "$PENDING_FILE"
        not_ready_reason="deploy/hostinger/release.manifest is missing"
        return 1
    fi
    first=$(head -n 1 "$MANIFEST_FILE" | tr -d '\r')
    if [ "$first" != "release $want" ]; then
        rm -f "$PENDING_FILE"
        not_ready_reason="release.manifest names '${first#release }', release.txt names '$want'"
        return 1
    fi
    total=0
    missing=0
    missing_list=''
    lineno=0
    while IFS= read -r path; do
        lineno=$((lineno + 1))
        [ "$lineno" -eq 1 ] && continue
        path=${path%"$CR"}
        [ -z "$path" ] && continue
        total=$((total + 1))
        if [ ! -e "$APP_ROOT/$path" ]; then
            missing=$((missing + 1))
            [ "$missing" -le 3 ] && missing_list="$missing_list${missing_list:+, }$path"
        fi
    done < "$MANIFEST_FILE"
    if [ "$missing" -gt 0 ]; then
        rm -f "$PENDING_FILE"
        not_ready_reason="$missing of $total files missing, first: $missing_list"
        return 1
    fi
    seen=0
    if [ -f "$PENDING_FILE" ]; then
        read -r pend_id pend_count < "$PENDING_FILE"
        [ "$pend_id" = "$want" ] && seen=${pend_count:-0}
    fi
    seen=$((seen + 1))
    echo "$want $seen" > "$PENDING_FILE"
    if [ "$seen" -lt "$CONFIRM_RUNS" ]; then
        not_ready_reason="all $total files present, confirming ($seen of $CONFIRM_RUNS runs)"
        return 1
    fi
    return 0
}
# Install deploy/hostinger/env.seed as shared/.env. Never logs values.
# The seed is NOT part of release.manifest, so completeness is explicit: deploy.ps1
# appends "# seed-end <release id>" as the last line of the staged copy. A seed is
# installed only if (a) that marker is its last non-empty line with the id in
# release.txt and (b) it has a non-empty DB_PASSWORD. Otherwise it stays in place and
# the previous shared/.env is never touched. All secret-bearing temp files are
# created under umask 077.
install_seed() {
    seed_tmp="$SHARED/.env.seed-tmp.$$"
    seed_rel=''
    [ -f "$RELEASE_FILE" ] && seed_rel=$(tr -d ' \r\n' < "$RELEASE_FILE")

    # Private copy first: what is validated is exactly what gets installed.
    if ! (umask 077; tr -d '\r' < "$SEED_FILE" > "$seed_tmp"); then
        rm -f "$seed_tmp"
        SEED_NOTE="seed unreadable, waiting"
        log "seed incomplete or invalid, waiting (could not read it)"
        return 1
    fi
    seed_last=$(grep -v '^[[:space:]]*$' "$seed_tmp" | tail -n 1)
    seed_pw=$(grep '^DB_PASSWORD=' "$seed_tmp" | tail -n 1 | cut -d= -f2- | tr -d "\"' ")
    if [ -z "$seed_rel" ] || [ "$seed_last" != "# seed-end $seed_rel" ] || [ -z "$seed_pw" ] || [ "$seed_pw" = "__DB_PASSWORD__" ]; then
        rm -f "$seed_tmp"
        SEED_NOTE="seed incomplete or invalid, waiting"
        log "seed incomplete or invalid, waiting"
        return 1
    fi

    seed_ok=1
    # Strip the marker line(s) from what gets installed.
    (umask 077; grep -v '^# seed-end ' "$seed_tmp" > "$seed_tmp.2") && mv -f "$seed_tmp.2" "$seed_tmp" || seed_ok=0
    if [ "$seed_ok" = 1 ]; then
        seed_key=$(grep '^APP_KEY=' "$seed_tmp" | tail -n 1 | cut -d= -f2- | tr -d "\"' ")
        if [ -z "$seed_key" ] && [ -f "$ENV_FILE" ]; then
            old_key_raw=$(grep '^APP_KEY=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d '\r')
            old_key=$(printf '%s' "$old_key_raw" | tr -d "\"' ")
            if [ -n "$old_key" ]; then
                (umask 077; { grep -v '^APP_KEY=' "$seed_tmp"; printf 'APP_KEY=%s\n' "$old_key_raw"; } > "$seed_tmp.2") \
                    && mv -f "$seed_tmp.2" "$seed_tmp" || seed_ok=0
            fi
        fi
    fi
    if [ "$seed_ok" = 1 ] && chmod 600 "$seed_tmp" && mv -f "$seed_tmp" "$ENV_FILE"; then
        rm -f "$SEED_FILE"
        env_changed=1
        log "installed shared/.env from seed"
    else
        rm -f "$seed_tmp" "$seed_tmp.2"
        SEED_NOTE="seed install failed"
        log "FAILED to install shared/.env from seed (seed left in place; ignoring it this run)"
    fi
}

# Write deploy/hostinger/status.txt and status.log atomically. No secrets, no command output.
write_status() {
    st_release=''
    st_done=''
    [ -f "$RELEASE_FILE" ] && st_release=$(tr -d ' \r\n' < "$RELEASE_FILE")
    [ -f "$DONE_FILE" ] && st_done=$(tr -d ' \r\n' < "$DONE_FILE")
    st_env=no
    st_key=no
    if [ -f "$ENV_FILE" ]; then
        st_env=yes
        [ -n "$(grep '^APP_KEY=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d "\"' \r")" ] && st_key=yes
    fi
    st_last=''
    [ -f "$SHARED/last_migrate" ] && st_last=$(tr -d '\r\n' < "$SHARED/last_migrate")
    st_access=''
    [ -f "$SHARED/last_access" ] && st_access=$(tr -cd 'a-z0-9 =_' < "$SHARED/last_access" | cut -c1-120)
    # Nightly backup result, written by hrm:backup-database. Plain text tools only; any problem reads as unknown.
    st_bk=''
    st_bk_ok=unknown
    bk_file="$STORAGE/app/private/backup-status/last-backup.json"
    if [ -f "$bk_file" ]; then
        st_bk=$(sed -n 's/.*"last_success_at"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$bk_file" 2>/dev/null | head -n 1 | tr -cd '0-9A-Za-z:+.-' | cut -c1-40)
        if grep -q '"ok"[[:space:]]*:[[:space:]]*true' "$bk_file" 2>/dev/null; then
            st_bk_ok=yes
        elif grep -q '"ok"[[:space:]]*:[[:space:]]*false' "$bk_file" 2>/dev/null; then
            st_bk_ok=no
        fi
    fi
    php_info=$("$PHP_BIN" -r 'echo PHP_VERSION, " ", function_exists("proc_open") ? "yes" : "no";' 2>/dev/null)
    st_php=unknown
    st_proc=unknown
    if [ -n "$php_info" ]; then
        st_php=${php_info% *}
        st_proc=${php_info#* }
    fi
    st_tmp="$SCRIPT_DIR/status.txt.tmp.$$"
    {
        echo "time=$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
        echo "release=$st_release"
        echo "release_done=$st_done"
        echo "state=$STATE"
        echo "detail=$DETAIL${SEED_NOTE:+${DETAIL:+; }$SEED_NOTE}"
        echo "env_present=$st_env"
        echo "app_key_set=$st_key"
        echo "php=$st_php"
        echo "proc_open=$st_proc"
        echo "last_migrate=$st_last"
        echo "access=$st_access"
        echo "last_backup=$st_bk"
        echo "last_backup_ok=$st_bk_ok"
    } > "$st_tmp" && mv -f "$st_tmp" "$SCRIPT_DIR/status.txt"
    # Only the lines this script wrote itself: they start with a UTC timestamp.
    grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z ' "$LOG_FILE" 2>/dev/null | tail -n 40 > "$SCRIPT_DIR/status.log.tmp.$$" \
        && mv -f "$SCRIPT_DIR/status.log.tmp.$$" "$SCRIPT_DIR/status.log"
    rm -f "$st_tmp" "$SCRIPT_DIR/status.log.tmp.$$"
}

main() {
    SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd) || exit 1
    APP_ROOT=$(cd "$SCRIPT_DIR/../.." && pwd) || exit 1
    SHARED="$(dirname "$APP_ROOT")/shared"
    STORAGE="$SHARED/storage"
    ENV_FILE="$SHARED/.env"
    RELEASE_FILE="$SCRIPT_DIR/release.txt"
    MANIFEST_FILE="$SCRIPT_DIR/release.manifest"
    SEED_FILE="$SCRIPT_DIR/env.seed"
    PENDING_FILE="$SHARED/release.pending"
    DONE_FILE="$SHARED/release.done"
    LOCK_DIR="$SHARED/cron.lock"
    LOG_DIR="$STORAGE/logs"
    LOG_FILE="$LOG_DIR/cron.log"
    OUT_FILE="$SHARED/cron.out.$$"
    STATE=ok
    DETAIL=
    SEED_NOTE=
    env_changed=0

    # 1. Shared layout (idempotent). shared/ itself is private (it holds .env); the
    #    storage tree below keeps default modes.
    (umask 077; mkdir -p "$SHARED") || exit 1
    for d in app/private app/public framework/cache/data framework/sessions framework/views logs; do
        mkdir -p "$STORAGE/$d" || exit 1
    done

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

    if ! acquire_lock; then
        exit 0   # another run is active; it will do the work
    fi
    trap 'write_status; rm -rf "$LOCK_DIR" "$OUT_FILE" "$SHARED"/.env.seed-tmp.*' EXIT
    trap 'exit 1' HUP INT TERM
    # leftovers of killed runs (we hold the lock)
    rm -rf "$SHARED"/cron.out.* "$SHARED"/.env.seed-tmp.* "$ENV_FILE".tmp.*

    # 1b. Env seed (see header): install before looking for shared/.env.
    if [ -f "$SEED_FILE" ]; then
        install_seed
    fi

    # 2. Wait for the environment file (created separately, never by a deploy).
    if [ ! -f "$ENV_FILE" ]; then
        STATE=waiting_for_env
        DETAIL="shared/.env not created yet"
        log "waiting for shared/.env (not created yet); nothing to do"
        exit 0
    fi

    # 3. APP_KEY. artisan key:generate writes to the environment file the app
    #    loads, which is shared/.env (see bootstrap/app.php).
    key=$(grep '^APP_KEY=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d "\"' \r")
    if [ -z "$key" ]; then
        grep -q '^APP_KEY=' "$ENV_FILE" || printf '\nAPP_KEY=\n' >> "$ENV_FILE"
        # Normalise APP_KEY="" / APP_KEY='' to a bare APP_KEY= (key:generate only
        # replaces what follows the "="). Atomic: temp file next to it (cp -p keeps
        # the mode), then mv over the original.
        cp -p "$ENV_FILE" "$ENV_FILE.tmp.$$" \
            && sed 's/^APP_KEY=.*$/APP_KEY=/' "$ENV_FILE" > "$ENV_FILE.tmp.$$" \
            && mv -f "$ENV_FILE.tmp.$$" "$ENV_FILE"
        rm -f "$ENV_FILE.tmp.$$"
        log "APP_KEY is empty: running key:generate"
        if artisan key:generate --force; then
            log "APP_KEY generated"
            rm -f "$DONE_FILE"   # cached config would hold the old (empty) key
        else
            STATE=post_deploy_failed
            DETAIL="artisan key:generate failed"
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
    if [ -z "$release" ]; then
        DETAIL="no release.txt; post-deploy skipped"
        log "no deploy/hostinger/release.txt; skipping post-deploy step"
    elif [ "$release" = "$done_id" ]; then
        # Release already done: never consult the manifest. Only a newer .env needs
        # a config rebuild (config is cached).
        rm -f "$PENDING_FILE"
        if [ "$env_changed" = 1 ] || [ "$ENV_FILE" -nt "$DONE_FILE" ]; then
            need=1
        fi
    else
        # New release: only when the upload is complete and confirmed.
        if release_ready "$release"; then
            need=1
        else
            STATE=upload_in_progress
            DETAIL="$not_ready_reason"
            log "upload still in progress: $not_ready_reason (release $release; will retry)"
            post_deploy_ok=0
        fi
    fi

    if [ "$need" = 1 ]; then
        log "post-deploy for release $release"
        for cmd in "config:clear" "migrate --force" "hrm:sync-access" "config:cache" "route:cache" "view:cache"; do
            # shellcheck disable=SC2086
            if artisan $cmd; then
                log "ok: artisan $cmd"
                if [ "$cmd" = "hrm:sync-access" ]; then
                    # One line of [a-z0-9 =_] (no email, no password); sanitise anyway.
                    access_line=$(grep -m1 '^access_sync ' "$OUT_FILE" | tr -cd 'a-z0-9 =_' | cut -c1-120)
                    printf '%s\n' "$access_line" > "$SHARED/last_access"
                    log "access sync: ${access_line:-no result line}"
                fi
            else
                STATE=post_deploy_failed
                DETAIL="artisan $cmd failed (release $release)"
                log "FAILED: artisan $cmd (release $release stays pending, will retry)"
                log_output
                post_deploy_ok=0
                break
            fi
        done
        if [ "$post_deploy_ok" = 1 ]; then
            echo "$release" > "$DONE_FILE"
            date -u '+%Y-%m-%dT%H:%M:%SZ' > "$SHARED/last_migrate"
            DETAIL="release $release done"
            rm -f "$PENDING_FILE"
            log "release $release done"
        fi
    fi

    # 5. Scheduler (skipped while a release is pending or failing: tree/schema may be stale).
    if [ "$post_deploy_ok" = 1 ]; then
        if artisan schedule:run; then
            # Only log when something actually ran.
            grep -qi 'no scheduled commands' "$OUT_FILE" || { log "schedule:run"; log_output; }
        else
            DETAIL="schedule:run failed"
            log "FAILED: artisan schedule:run"
            log_output
        fi
    else
        log "schedule:run skipped (release pending or failing)"
    fi

    exit 0
}

main "$@"
exit $?
