#!/bin/bash
# Night purge of the disabled W3TC object cache (site/wp-content/cache/object).
#
# The cache was switched off on 2026-10-06; ~3 GB / ~200k small files remain.
# Deleting them makes sda write latency jump to ~40 ms PER IO no matter how
# slowly we go (journal writes on a slow disk), and Zabbix alerts on
# "write await > 20 ms for 15m" (min over 15 one-minute samples). So the
# throttle is time-sliced: delete for WORK s, then stay idle for REST s --
# every 15-minute window always contains several clean minutes.
#
# Load guard: the await measured during the IDLE slice is foreign load (site,
# MySQL, sitemap job). Above BUSY_MS we back off BACKOFF s; three busy idle
# slices in a row end the night.
#
# Window 01:00-05:00 UTC, on Sundays until 03:30 (the weekly sitemap --full
# starts 03:47). When the directory is empty the script removes its own cron
# line (crontab backed up first) and leaves a done-marker.
#
# root cron (see server/cron/root.crontab):
#   5 1 * * * /usr/bin/flock -n /run/lock/sph-oc-purge.lock /usr/bin/timeout 230m
#             /usr/bin/nice -n 19 /opt/services/savepearlharbor.com/bin/oc-purge-night.sh
#             >> /opt/services/savepearlharbor.com/oc-purge.log 2>&1
#
# Test: oc-purge-night.sh --test 120   (one work slice of 120 s, any time of day)
set -u
set -o pipefail

TARGET=/opt/services/savepearlharbor.com/site/wp-content/cache/object
W3TC_CONF=/opt/services/savepearlharbor.com/site/wp-content/w3tc-config/master.php
MARKER=/opt/services/savepearlharbor.com/.oc-purge-done
DISK=sda
WORK=240 REST=240 BUSY_MS=10 BACKOFF=300 MAX_BUSY=3
PAUSE=${PAUSE:-1.5}    # seconds between chunks (one top-level dir each)
TEST=0

[ "${1:-}" = "--test" ] && { TEST=1; WORK=${2:-120}; }

log() { echo "$(date -u +%FT%TZ) $*"; }

# writes completed, ms spent writing (fields 8 and 11 of /proc/diskstats)
wstat() { awk -v d="$DISK" '$3==d {print $8, $11}' /proc/diskstats; }
await_since() {  # $1 $2 = values from wstat at the start of the interval
    local w t; read -r w t < <(wstat)
    local dw=$((w - $1))
    if [ "$dw" -gt 0 ]; then echo $(( (t - $2) / dw )); else echo 0; fi
}

deadline() {  # epoch of the end of tonight's window
    if [ "$(date -u +%u)" = 7 ]; then date -u -d 'today 03:30' +%s
    else date -u -d 'today 05:00' +%s; fi
}

finish() {
    log "DONE: $TARGET is empty"
    touch "$MARKER"
    if crontab -l 2>/dev/null | grep -q 'oc-purge-night.sh'; then
        local bak=/root/crontab.bak-$(date -u +%F)-oc-purge-done
        if crontab -l > "$bak" && [ -s "$bak" ] && chmod 600 "$bak"; then
            grep -v 'oc-purge-night.sh' "$bak" | crontab - \
                && log "cron line removed (backup $bak)"
        else
            log "crontab backup failed, cron line left in place (marker set)"
        fi
    fi
    exit 0
}

# ---- safety: exact path, real directory, engine disabled ----
if [ ! -e "$TARGET" ] && [ ! -L "$TARGET" ]; then
    log "no $TARGET"; [ "$TEST" = 1 ] || finish; exit 0
fi
real=$(realpath -e -- "$TARGET") || { log "ABORT: cannot resolve $TARGET"; exit 2; }
[ "$real" = "$TARGET" ] || { log "ABORT: $TARGET resolves to $real"; exit 2; }
[ -d "$TARGET" ] && [ ! -L "$TARGET" ] || { log "ABORT: not a plain directory"; exit 2; }
# checked before every chunk: someone re-enabling the cache must stop us
engine_off() {
    [ -f "$W3TC_CONF" ] && [ -r "$W3TC_CONF" ] \
        || { log "ABORT: W3TC config $W3TC_CONF missing or unreadable"; exit 2; }
    grep -q '"objectcache.enabled": false' "$W3TC_CONF" \
        || { log "ABORT: W3TC object cache is enabled again, not purging"; exit 2; }
}
engine_off

if [ "$TEST" = 0 ]; then
    h=$(date -u +%H)
    [ "$h" -ge 1 ] && [ "$h" -lt 5 ] || { log "outside 01:00-05:00 UTC, exit"; exit 0; }
fi
# Work from INSIDE the directory: every path below is relative to the cwd,
# which is bound to this inode, so swapping object/ or any parent for a
# symlink later cannot redirect the deletes. Re-checked before each chunk.
DEV_INO=$(stat -c %d:%i -- "$real") || { log "ABORT: stat $real"; exit 2; }
cd -P -- "$TARGET" || { log "ABORT: cd $TARGET"; exit 2; }
# where we actually are must be the exact path, and the inode checked above
[ "$(pwd -P)" = "$TARGET" ] && [ "$(stat -c %d:%i .)" = "$DEV_INO" ] \
    || { log "ABORT: cwd $(pwd -P) is not $TARGET"; exit 2; }
same_dir() {
    [ "$(stat -c %d:%i .)" = "$DEV_INO" ] && [ "$(stat -L -c %d:%i -- "$TARGET" 2>/dev/null)" = "$DEV_INO" ] \
        || { log "ABORT: $TARGET is no longer the directory we started in"; exit 2; }
}
end=$(deadline); [ "$TEST" = 1 ] && end=$(( $(date +%s) + WORK + 5 ))

remaining() { find . -mindepth 1 -maxdepth 1 -printf . | wc -c; }
log "start: top-level entries=$(remaining) window_end=$(date -u -d @"$end" +%T)"

# delete one chunk = one top-level directory (~60 files). find -delete walks
# with fts in physical mode (unlinkat relative to directory fds), so it never
# follows a symlink, also not one swapped in mid-walk; -xdev stays on this fs.
# Returns 1 only when the directory is verifiably empty.
delete_one() {
    local top rc
    same_dir
    engine_off
    top=$(find . -mindepth 1 -maxdepth 1 -print -quit); rc=$?
    if [ "$rc" -ne 0 ]; then log "ABORT: cannot list $TARGET (rc=$rc)"; exit 2; fi
    [ -n "$top" ] || return 1
    case "$top" in
        ./?*) ;;
        *) log "ABORT: unexpected entry $top"; exit 2 ;;
    esac
    ionice -c3 find "$top" -xdev -depth -delete \
        || { log "ABORT: find -delete failed on $top"; exit 2; }
    return 0
}

busy=0 total=0
while :; do
    # ---- work slice ----
    read -r w0 t0 < <(wstat)
    s=$(date +%s) n=0
    while [ $(( $(date +%s) - s )) -lt "$WORK" ] && [ "$(date +%s)" -lt "$end" ]; do
        delete_one || finish
        n=$((n + 1)); sleep "$PAUSE"
        # re-check after the pause too (the loop head is re-evaluated anyway;
        # this keeps the overrun to at most one PAUSE)
        [ $(( $(date +%s) - s )) -lt "$WORK" ] && [ "$(date +%s)" -lt "$end" ] || break
    done
    total=$((total + n))
    log "work ${WORK}s: chunks=$n w_await=$(await_since "$w0" "$t0")ms left_top=$(remaining)"
    [ "$TEST" = 1 ] && break
    [ "$(date +%s)" -lt "$end" ] || break

    # ---- idle slice: lets Zabbix see clean minutes; measures foreign load ----
    read -r w0 t0 < <(wstat)
    sleep "$REST"
    a=$(await_since "$w0" "$t0")
    if [ "$a" -gt "$BUSY_MS" ]; then
        busy=$((busy + 1))
        log "idle w_await=${a}ms > ${BUSY_MS}ms (foreign load), backoff ${BACKOFF}s ($busy/$MAX_BUSY)"
        [ "$busy" -ge "$MAX_BUSY" ] && { log "disk stays busy, stopping for tonight"; break; }
        sleep "$BACKOFF"
    else
        busy=0
    fi
    [ "$(date +%s)" -lt "$end" ] || break
done
log "stop: chunks=$total left_top=$(remaining)"
