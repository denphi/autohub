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

# 3b. Templates baked into the image (see the Dockerfile: cluster deployments
#     have no bind mounts).
#
#     Install a template the app/ volume lacks, and refresh one this container
#     installed earlier when the image's copy has changed -- otherwise a
#     template edit rebuilt into the image would never reach a cluster, which
#     serves the first-installed version forever with nothing in the logs.
#     The stamp file records which content we installed: a directory without
#     one was put there by something else (a live bind mount, an operator, a
#     `hub.yml` extension clone) and is never touched.
for baked in /usr/local/share/hubzero-templates/*/; do
	[ -d "$baked" ] || continue
	name=$(basename "$baked")
	target="$HUB_ROOT/app/templates/$name"
	stamp="$target/.autohub-baked"
	want=$(find "$baked" -type f -exec sha256sum {} + | sort | sha256sum | cut -d' ' -f1)

	if mountpoint -q -- "$target" 2>/dev/null \
		|| awk -v t="$target" '$2 == t { found = 1 } END { exit !found }' /proc/self/mounts 2>/dev/null; then
		# A developer's working copy, bind-mounted over this path. Being a
		# mountpoint is the one signal a bind mount cannot fake, and it holds
		# even when the mounted directory is empty.
		#
		# Compare the mount point as a FIELD, never as a grep pattern:
		# /proc/self/mounts octal-escapes whitespace (a space becomes \040),
		# and a path used as a regex would silently fail to match -- falling
		# through to the refresh branch, which deletes the developer's files.
		action=mounted
	elif [ ! -d "$target" ] || [ -z "$(ls -A "$target" 2>/dev/null)" ]; then
		action=install
	elif [ ! -f "$stamp" ]; then
		action=skip           # present but not installed from an image
	elif [ "$(cat "$stamp" 2>/dev/null)" = "$want" ]; then
		action=current
	else
		action=refresh
	fi

	case "$action" in
		install|refresh)
			# Safe to replace: either nothing is there, or the stamp says this
			# is a copy we installed from a previous image.
			mkdir -p "$target"
			find "$target" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
			cp -R "${baked}." "$target/"
			printf '%s\n' "$want" > "$stamp"
			chown -R "$HUB_USER:$HUB_USER" "$target"
			log "${action}ed baked template '$name' in app/templates"
			;;
		mounted)
			log "template '$name' is a bind mount -- leaving the working copy alone"
			;;
		skip)
			log "template '$name' exists and was not installed from the image -- leaving it alone"
			;;
	esac
done

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
