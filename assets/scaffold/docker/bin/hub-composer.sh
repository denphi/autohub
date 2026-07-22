#!/bin/bash
# Install core/vendor, skipping the work when composer.lock has not moved.
#
# Usage: hub-composer [--force]

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

require_source

lock="$HUB_ROOT/core/composer.lock"
stamp="$HUB_ROOT/app/.composer-lock-hash"
current=$(md5sum "$lock" 2>/dev/null | cut -d' ' -f1)

if [ "${1:-}" != '--force' ] \
	&& [ -f "$HUB_ROOT/core/vendor/autoload.php" ] \
	&& [ "$(cat "$stamp" 2>/dev/null)" = "$current" ]; then
	log "composer dependencies up to date"
	exit 0
fi

log "installing composer dependencies (this takes a few minutes on first run)"

# --no-scripts: composer.json's post-install hook runs phpcs, a dev-only tool
# that is absent from a --no-dev install and would abort the whole thing.
dev_flag=--no-dev
[ "${HUB_COMPOSER_DEV:-0}" = '1' ] && dev_flag=--dev

as_web composer install \
	--working-dir="$HUB_ROOT/core" \
	--no-interaction \
	--no-progress \
	--no-scripts \
	--optimize-autoloader \
	"$dev_flag" \
	|| die "composer install failed"

mkdir -p "$(dirname "$stamp")"
printf '%s' "$current" > "$stamp"
chown "$HUB_USER:$HUB_USER" "$stamp"

log "composer dependencies installed"
