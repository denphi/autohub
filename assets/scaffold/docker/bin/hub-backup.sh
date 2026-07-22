#!/bin/bash
# Dump the database to stdout (or to a file under app/backups).
#
# Usage: hub-backup            write app/backups/<db>-<timestamp>.sql.gz
#        hub-backup -          write uncompressed SQL to stdout

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

wait_for_db 15

dump_bin=mariadb-dump
command -v "$dump_bin" >/dev/null 2>&1 || dump_bin=mysqldump

dump() {
	MYSQL_PWD="$DB_PASSWORD" "$dump_bin" \
		--host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
		--single-transaction --quick --routines --triggers --events \
		--default-character-set=utf8mb4 \
		"$DB_NAME"
}

if [ "${1:-}" = '-' ]; then
	dump
	exit 0
fi

dir="$HUB_ROOT/app/backups"
mkdir -p "$dir"

out="$dir/${DB_NAME}-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"

dump | gzip -9 > "$out"
chown "$HUB_USER:$HUB_USER" "$out"

log "wrote $out ($(du -h "$out" | cut -f1))"
