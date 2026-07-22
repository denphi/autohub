#!/bin/bash
# Poll loop for HUBzero's scheduled jobs (com_cron).
#
# Runs as its own container rather than a crond inside the web container, so
# job failures are visible in `docker compose logs cron` and never take the
# site down with them.

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

: "${HUB_CRON_INTERVAL:=60}"

# This container shares the source volume with web but starts alongside it, so
# wait for web's entrypoint to finish installing before polling. The schema
# check matters as much as the file checks: config and vendor appear well
# before hub-db-init has loaded any tables, and jobs would fail until it does.
log "waiting for the web container to finish bootstrapping"
while [ ! -f "$HUB_ROOT/core/vendor/autoload.php" ] || [ ! -f "$HUB_ROOT/app/config/database.php" ]; do
	sleep 5
done

wait_for_db

while ! db_is_installed; do
	sleep 5
done

log "cron runner started (every ${HUB_CRON_INTERVAL}s)"

while true; do
	# A failing job must not kill the runner.
	if ! hub-muse cron:jobs run --no-ansi --quiet; then
		warn "cron run exited non-zero"
	fi
	sleep "$HUB_CRON_INTERVAL"
done
