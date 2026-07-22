#!/bin/bash
# Create the app/ tree and regenerate app/config/*.php from the environment.

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

require_source

# app/ is gitignored upstream and never ships in the repo; it is entirely ours
# to create. PATH_APP is defined in core/bootstrap/app.php.
for dir in \
	bootstrap cache components config logs migrations modules \
	plugins sessions site templates tmp
do
	mkdir -p "$HUB_ROOT/app/$dir"
done

chown -R "$HUB_USER:$HUB_USER" "$HUB_ROOT/app"
chmod 0750 "$HUB_ROOT/app/config"

as_web php "$(dirname "$(readlink -f "$0")")/render-config.php"
