#!/bin/bash
# Bring a container to a serving state, then hand off to the given command.
#
# Every step is idempotent, so a restart is cheap and a fresh volume
# self-installs. Set HUB_SKIP_BOOTSTRAP=1 to get a bare shell for debugging.

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

# Only the web server needs a bootstrapped hub; hub-cron, bash, hub-muse etc.
# run straight through.
case "${1:-}" in
	apache2*) ;;
	*) exec "$@" ;;
esac

if [ "$HUB_SKIP_BOOTSTRAP" = '1' ]; then
	warn "HUB_SKIP_BOOTSTRAP=1 -- serving whatever is already on disk"
	exec "$@"
fi

log "HUBzero container starting"

mkdir -p "$HUB_ROOT"
chown "$HUB_USER:$HUB_USER" "$HUB_ROOT"

# 0. TLS, before anything else: without a certificate Apache will not start the
#    :443 vhost, and nobody can reach the admin login at all.
hub-tls

# 1. Source: clone on first boot, fast-forward when HUBZERO_AUTO_UPDATE=1.
hub-source-sync

# 2. Dependencies: no-op unless composer.lock moved.
[ "$HUB_SKIP_COMPOSER" = '1' ] || hub-composer

# 3. Config: app/ tree + app/config/*.php rendered from the environment.
hub-config-render

# 4. Database: load schema on an empty database, otherwise apply pending
#    migrations so a source update never leaves the schema behind.
db_is_installed || hub-db-init

# A failed migration is an operational problem, not a reason to take a working
# site offline -- exiting here would crashloop the container and make the hub
# unreachable even for the admin trying to diagnose it. Complain and serve.
if ! hub-migrate; then
	warn "MIGRATIONS FAILED -- the site is starting with an out-of-date schema."
	warn "Inspect with: docker compose exec web hub-migrate --dry-run"
fi

if [ -n "$HUB_ADMIN_PASSWORD" ]; then
	hub-admin || warn "could not create the administrator account"
elif ! db_had_users; then
	warn "HUB_ADMIN_PASSWORD is unset -- no administrator account was created."
	warn "Run 'docker compose exec web hub-admin <user> <password>' to make one."
fi

# 5. Declarative setup: extensions, template, plugins, content. Non-fatal for
#    the same reason migrations are -- a typo in hub.yml must not make the hub
#    unreachable, and the errors are far easier to read on a running site.
if ! hub-provision; then
	warn "provisioning reported errors -- see above; the site is still starting"
fi

# 6. Compile the active template's LESS. A syntax error here would otherwise be
#    invisible: Assets::getSystemStylesheet() swallows it and emits no
#    stylesheet at all, which looks like a broken theme rather than a bug.
if ! hub-assets; then
	warn "TEMPLATE ASSETS FAILED TO COMPILE -- the site will render unstyled."
fi

# Writable-by-web paths. Everything else stays read-only to the server.
chown -R "$HUB_USER:$HUB_USER" "$HUB_ROOT/app"

log "bootstrap complete -- starting ${1}"

exec "$@"
