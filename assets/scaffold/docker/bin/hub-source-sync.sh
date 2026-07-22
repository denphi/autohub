#!/bin/bash
# Put the HUBzero source at $HUB_ROOT and move it to $HUBZERO_REF.
#
# This is what makes "update the hub without rebuilding the image" work: the
# source lives in a volume, and updating is a fetch + checkout in place.
#
# init + fetch + checkout rather than `git clone`, because $HUB_ROOT already
# holds the app/ volume by the time this runs and clone refuses a non-empty
# target. app/ is gitignored upstream, so checkout never touches it.
#
# Usage: hub-source-sync [--update]
#   (no args)  set up on first boot; afterwards only sync if HUBZERO_AUTO_UPDATE=1
#   --update   always fetch and move to HUBZERO_REF

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

: "${HUB_SOURCE_MODE:=git}"

force_update=0
[ "${1:-}" = '--update' ] && force_update=1

git_hub() { as_web git -C "$HUB_ROOT" "$@"; }

# A bind-mounted working copy belongs to the developer, not to this script.
if [ "$HUB_SOURCE_MODE" = 'external' ]; then
	if [ ! -f "$HUB_ROOT/core/bootstrap/app.php" ]; then
		die "HUB_SOURCE_MODE=external but no HUBzero source is mounted at $HUB_ROOT"
	fi
	log "using externally managed source at $HUB_ROOT"
	exit 0
fi

fresh=0

if [ ! -d "$HUB_ROOT/.git" ]; then
	if [ -f "$HUB_ROOT/index.php" ]; then
		warn "source at $HUB_ROOT is not a git checkout -- cannot manage it; leaving as is"
		exit 0
	fi

	log "initialising $HUBZERO_REPO ($HUBZERO_REF) in $HUB_ROOT"
	as_web git init --quiet "$HUB_ROOT"
	git_hub remote add origin "$HUBZERO_REPO"
	fresh=1
fi

if [ "$fresh" = '0' ] && [ "$force_update" = '0' ] && [ "$HUBZERO_AUTO_UPDATE" != '1' ]; then
	log "source at $(git_hub rev-parse --short HEAD) -- set HUBZERO_AUTO_UPDATE=1 to auto-update"
	exit 0
fi

before=''
[ "$fresh" = '0' ] && before=$(git_hub rev-parse HEAD)

# Refuse to clobber local edits rather than silently discarding someone's work.
if [ "$fresh" = '0' ] && ! git_hub diff --quiet HEAD --; then
	die "uncommitted changes in $HUB_ROOT -- commit, stash or revert them before updating"
fi

git_hub remote set-url origin "$HUBZERO_REPO"

log "fetching $HUBZERO_REPO"
# Full history, not --depth 1: a shallow clone cannot later move to an
# arbitrary tag or older branch, which is the whole point of this setup.
git_hub fetch --prune --tags origin || die "fetch failed -- check HUBZERO_REPO"

# HUBZERO_REF may be a branch, a tag or a sha.
if git_hub rev-parse --verify --quiet "refs/remotes/origin/$HUBZERO_REF" >/dev/null; then
	target="origin/$HUBZERO_REF"
elif git_hub rev-parse --verify --quiet "refs/tags/$HUBZERO_REF" >/dev/null; then
	target="refs/tags/$HUBZERO_REF"
elif git_hub rev-parse --verify --quiet "${HUBZERO_REF}^{commit}" >/dev/null; then
	target="$HUBZERO_REF"
else
	die "HUBZERO_REF '$HUBZERO_REF' is not a branch, tag or commit in $HUBZERO_REPO"
fi

# Detached on purpose: the deployed revision is pinned by HUBZERO_REF, and a
# local branch would only invite a divergent history nobody looks at.
git_hub checkout --quiet --force --detach "$target" || die "cannot check out $HUBZERO_REF"

after=$(git_hub rev-parse --short HEAD)

if [ "$fresh" = '1' ]; then
	log "source installed at $after ($HUBZERO_REF)"
elif [ "$before" = "$(git_hub rev-parse HEAD)" ]; then
	log "already at $after"
else
	log "updated $(git_hub rev-parse --short "$before") -> $after"
fi
