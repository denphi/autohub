#!/bin/bash
# Create the initial Super User, or reset its password.
#
# Usage: hub-admin [username] [password]
#        (defaults come from HUB_ADMIN_USER / HUB_ADMIN_PASSWORD)

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

require_source
require_vendor
wait_for_db

[ -n "${1:-}" ] && HUB_ADMIN_USER="$1"
[ -n "${2:-}" ] && HUB_ADMIN_PASSWORD="$2"

if [ -z "$HUB_ADMIN_PASSWORD" ]; then
	die "no admin password: set HUB_ADMIN_PASSWORD or pass one as the second argument"
fi

as_web php "$(dirname "$(readlink -f "$0")")/create-admin.php"
