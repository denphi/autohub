# AutoHub scaffold reference

A deployable [HUBzero CMS](https://github.com/hubzero/hubzero-cms) stack that
installs itself and updates without an image rebuild. Paths in this document
refer to a project created from `assets/scaffold/`, not to the skill package
root.

## Contents

- [Quick start](#quick-start)
- [The app-less image](#the-one-idea-worth-knowing)
- [Project layout](#layout)
- [Development](#development)
- [Commands](#commands)
- [Configuration](#configuration)
- [Declaring the hub](#declaring-the-hub-hubyml)
- [Native articles and templates](#native-articles-and-template-boundary)
- [First boot](#how-first-boot-installs-the-hub)
- [Upstream behaviors](#four-upstream-behaviours-that-will-surprise-you)
- [Production notes](#production-notes)
- [Requirements](#requirements)

## Quick start

```bash
./scripts/hub-init.sh --site "My Hub"    # generates .env + hub.yml, all secrets random
cli/autohub tls setup --json             # installs a local CA and issues a trusted leaf
make up
cli/autohub verify --scope tls --json
```

`hub-init.sh` probes wildcard IPv4 and IPv6 bindings for free ports, generates
every secret, assigns a project-specific Compose namespace, writes `.env` mode
600, and refuses to clobber existing files without `--force`. `--help` lists
the options; `make init ARGS="--site ..."` is the same thing. Generated
passwords remain in `.env` and are deliberately not printed.

`tls setup` requires [mkcert](https://github.com/FiloSottile/mkcert). It
installs mkcert's local CA into host trust stores, which may request
administrator credentials, and writes only the project leaf certificate and
key under the ignored `.autohub/tls/` directory. Get explicit authorization
before changing host trust. Never copy or commit mkcert's `rootCA-key.pem`.

First boot takes a few minutes (clone, `composer install`, schema load, schema
repair). Watch it with `make logs`. When it settles:

- site — <http://localhost:8080> or <https://localhost:8443>
- admin — <https://localhost:8443/administrator> (**https only**, see below)

HTTPS should open without a certificate warning after `tls setup`. If setup is
skipped, the container generates a self-signed fallback so HUBzero can start,
but browser-trusted HTTPS verification remains incomplete.

## The one idea worth knowing

**The image contains no application code.**

It is PHP 8.2 + Apache + extensions + a handful of `hub-*` scripts, and nothing
else. The CMS is checked out into a Docker volume the first time a container
starts. That inversion is what makes updates cheap:

```bash
make update              # fetch HUBZERO_REF, reinstall deps, run migrations
make update REF=v2.4.2   # or move to a different branch/tag/sha
```

No `docker build`, no new image, no container recreation — just a `git fetch`
and `checkout` inside the running container followed by `muse migration -f`.
You only rebuild the image when PHP itself changes, which is roughly never.

## Layout

| Path | What it is |
|---|---|
| [docker/Dockerfile](../assets/scaffold/docker/Dockerfile) | The runtime image |
| [docker/apache/hubzero.conf](../assets/scaffold/docker/apache/hubzero.conf) | the `:80` and `:443` vhosts |
| [docker/apache/hubzero-common.conf](../assets/scaffold/docker/apache/hubzero-common.conf) | front-controller rewrite + what must never be served |
| [docker/php/hubzero.ini](../assets/scaffold/docker/php/hubzero.ini) | PHP tuning |
| [docker/bin/](../assets/scaffold/docker/bin/) | `hub-*` management commands, on `PATH` in every container |
| [scripts/hub-init.sh](../assets/scaffold/scripts/hub-init.sh) | Scaffolds `.env` + `hub.yml` for a new hub |
| [template-starter/](../assets/scaffold/template-starter/) | Native-component-first baseline used by `autohub template create` |
| [hub.yml.example](../assets/scaffold/hub.yml.example) | Declarative hub setup — copy to `hub.yml` |
| [component-tools/](../assets/scaffold/component-tools/) | Native component registry and manifest schemas |
| [content/](../assets/scaffold/content/) | Read-only import root for approved resource/publication files |
| [docker-compose.yml](../assets/scaffold/docker-compose.yml) | Production stack: web, cron, db |
| [docker-compose.dev.yml](../assets/scaffold/docker-compose.dev.yml) | Dev overlay: local checkout, Adminer, Mailpit |

Five storage mounts, with deliberately different lifetimes:

- `hub_src` → `/var/www/html` — the CMS checkout. Disposable; recreated from git.
- `hub_app` → `/var/www/html/app` — **your data**: config, uploads, logs, sessions.
  Nested inside `hub_src` so resetting the source can never take it with it.
- `${HUB_TLS_PATH:-hub_tls}` → `/etc/hubzero/tls` — a named volume for the
  self-signed fallback, `.autohub/tls` after local trusted setup, or an
  operator-managed production certificate directory.
- `${HUB_CONTENT_PATH:-./content}` → `/etc/hubzero/content` — read-only
  operator-approved imports; adapters validate and copy files into `hub_app`.
- `db_data` → MariaDB.

On Kubernetes, the same component `apply` command packages only the file paths
reported by the reviewed plan, streams them to a per-target temporary pod
directory, and points the provisioner at that directory for the duration of
the apply. Files still undergo the same in-runtime validation before entering
component-owned persistent storage.

## Development

```bash
git clone https://github.com/hubzero/hubzero-cms.git   # anywhere you like
make dev                                               # serves ./hubzero-cms
```

The dev overlay bind-mounts your checkout over `/var/www/html`, so host edits
are live. `HUB_SOURCE_MODE=external` tells `hub-source-sync` the working copy is
yours and to leave it alone. Point it elsewhere with `HUB_SOURCE_PATH`.

It also brings up [Adminer](http://localhost:8081) and
[Mailpit](http://localhost:8025), which swallows every outgoing mail.

## Commands

`make help` lists them. Each one is a thin wrapper over a script you can also
run directly with `docker compose exec web <script>`:

| | |
|---|---|
| `hub-source-sync [--update]` | clone / fast-forward the checkout |
| `hub-composer [--force]` | install `core/vendor` (skipped when `composer.lock` is unchanged) |
| `hub-config-render` | regenerate `app/config/*.php` from the environment |
| `hub-db-init [--force]` | load `schema.sql` + `data.sql`, baseline, then repair |
| `hub-tls` | preserve a mounted certificate or generate a self-signed fallback |
| `hub-migrate [--dry-run\|--baseline]` | run pending migrations |
| `hub-admin <user> <pass>` | low-level admin reset; prefer `cli/autohub admin <user>`, which reads the password from `.env` rather than argv |
| `hub-muse <args>` | HUBzero's own CLI, as the web user |
| `hub-backup [-]` | dump the database |
| `hub-provision [file]` | apply hub.yml -- extensions, template, plugins, content |
| `hub-component <domain> <verb>` | native component discovery, planning, inspection, verification, and export |
| `hub-assets [--clean]` | compile the active template's LESS, reporting syntax errors |
| `hub-update [ref]` | source + deps + config + migrations, in one step |

Everything is idempotent — a restart re-runs the lot and changes nothing.

The host CLI also owns local template creation and verification:

```bash
cli/autohub tls setup --json
cli/autohub tls status --json
cli/autohub template create --name researchhub --json
cli/autohub assets lint --json
cli/autohub verify --scope tls --json
cli/autohub verify --scope components --route /about --json
cli/autohub publication describe --json
cli/autohub publication plan --max-items 3 --json
```

The TLS command validates hostnames, installs mkcert's local CA, issues an
ignored project certificate, configures its Docker bind mount, and verifies
that the host accepts the running HTTPS endpoint without `-k` or an insecure
SSL context.

The template command creates and mounts `templates/researchhub`, updates
`hub.yml`, and avoids one-off Compose edits. Component verification inventories
the primary menu and standard public component routes, then checks their linked
static assets.

## Configuration

HUBzero reads no environment variables. `Hubzero\Config\FileLoader` loads plain
PHP arrays out of `app/config/`, and upstream expects you to produce them with
the interactive `/install` wizard.

[docker/bin/render-config.php](../assets/scaffold/docker/bin/render-config.php) is the bridge. It
takes upstream's own templates in `core/bootstrap/Install/config/` as the base —
rather than duplicating them here, so new settings appear on their own after an
update — and layers on top, in order:

1. upstream defaults
2. whatever is already in `app/config/` (**admin-panel edits survive restarts**)
3. mapped variables from `.env` (`DB_HOST`, `HUB_SITENAME`, …)
4. `HUBCFG_<group>__<key>` for anything without a dedicated variable:

```bash
HUBCFG_app__list_limit=50        # -> app/config/app.php     ['list_limit' => '50']
HUBCFG_session__lifetime=120     # -> app/config/session.php ['lifetime' => '120']
```

`app.secret` is generated once on first boot and then preserved forever —
rotating it silently invalidates every session and password-reset token. Set
`HUB_SECRET` explicitly if you run more than one web replica.

> `DB_PASSWORD` must not be empty. HUBzero reads a blank database password as
> "not installed yet" and redirects the entire site to `/install`.

## Declaring the hub: hub.yml

The admin UI is the only supported way to set up a HUBzero hub, which makes a
hub impossible to rebuild from scratch and impossible to review. [hub.yml](../assets/scaffold/hub.yml.example)
is the alternative: a committed manifest describing what the hub *is*, applied
on every boot and by `make provision`.

```yaml
extensions:                       # cloned, registered, migrations run
  - type: template
    alias: custom
    url: https://git.example.org/hub/tpl_custom.git
    branch: main
    token: ${GITLAB_TOKEN}        # resolved from .env, never written here

template:
  site: custom                    # the active site template

plugins:
  enable: [user/hubzero]
  disable: [user/constantcontact]

components:
  com_members:
    allowUserRegistration: 1

resource_types:                   # ids pinned so content stays portable
  - { id: 7, alias: tools, type: Tools, category: 27, state: 1 }

projects:  [...]                  # native team workspaces
resources: [...]                  # datasets, tools, and downloads
publications: [...]               # native versioned research outputs
courses:   [...]                  # course/unit/asset hierarchies
users:     [...]                  # extra accounts (admin comes from .env)
groups:    [...]
menus:     { site: [...] }        # including which item is the front page
```

Rules that make it safe to run unattended:

- **Idempotent.** Every section matches on a natural key (`alias`, `username`,
  `title`, pinned `id`), so re-running updates in place instead of duplicating.
- **Additive only.** It never deletes anything it did not create — a half-written
  manifest must not be able to destroy content.
- **Non-fatal.** A bad entry is reported and skipped; the hub still starts, so
  you can read the error on a running site instead of a crashlooped container.
- **Secrets stay in `.env`.** `${VAR}` is expanded from the environment, and
  tokens are stripped from any error output. `hub.yml` is meant to be committed.

Division of labour: **`.env` owns infrastructure** (ports, database, TLS, mail,
debug); **`hub.yml` owns the hub** (extensions, template, plugins, content).

## Native articles and template boundary

Provision homepage copy, about/help/policy pages, news, and other general prose
as native `com_content` records:

```yaml
articles:
  - title: Home
    alias: home
    content: |
      <h1>Welcome</h1>
      <p>This content remains editable in Article Manager.</p>
    attribs: { show_title: 0 }

menus:
  site:
    items:
      - { title: Home, alias: home, article: home, home: true }
```

The `article:` menu shorthand resolves a published article by alias and creates
the normal `com_content` link. Datasets, tools, and downloads use `resources:`;
versioned research outputs use `publications:`; learning hierarchies use
`courses:`; team workspaces use `projects:`; and communities use `groups:`.

A template owns shared presentation, assets, module positions, and optional
component markup overrides. It must render the component buffer. Do not put
site pages in template-side `pages/*.php` files, dispatch page content from
`index.php`, or store the content catalog in PHP arrays. See
[content and template architecture](content-and-templates.md) for the full
mapping and verification checklist.

Custom templates must also keep `/resources`, `/groups`, `/members`, `/search`,
and `/support` readable and responsive even when those components are not in
the primary navigation. See
[native component styling and acceptance](native-component-styling.md).

### Replicating an existing hub

Two routes, and the second is usually faster:

1. **Declare it** in `hub.yml` — reproducible from nothing, reviewable in git,
   but you have to describe the hub.
2. **Restore a dump** of the real hub — `make restore FILE=dump.sql.gz` — then
   layer `hub.yml` on top for the extensions and template. This is what
   [qubeshub/hubzero-docker](https://github.com/qubeshub/hubzero-docker) does
   (it ships `databasedump_qubeshub.sql.gz` and runs migrations over it), and it
   is independent confirmation that installing from `schema.sql` is not a route
   upstream expects anyone to take. A dump brings real content, menus and
   groups that no manifest will reproduce by hand.

Mixing them works well: restore the dump for content, keep `hub.yml` for the
things that must be reproducible (extensions, template, plugin state).

## How first boot installs the hub

Worth reading, because **upstream does not support installing the CMS from the
git repository** — their Debian/RedHat packages own database setup, and the
bundled SQL has drifted from the code as a result. Most of the work below
exists to close that gap.

1. **Source** — `git init` + `fetch` + `checkout` (not `clone`: `/var/www/html`
   already holds the `app` volume, and `clone` refuses a non-empty target).
2. **Dependencies** — `composer install --no-scripts`. The `--no-scripts` matters:
   upstream's `post-install-cmd` runs `phpcs`, which a `--no-dev` install does
   not have, and would otherwise abort the build.
3. **`app/` tree** — created from scratch. It is gitignored upstream and ships
   in no release.
4. **Schema** — `schema.sql` and `data.sql` are piped into MariaDB with `#__`
   rewritten to `DB_PREFIX`. Nothing in the CMS references these files.
5. **Migration baseline** — `schema.sql` is a dump taken at release time, so the
   ~1,390 migrations that produced it are recorded as applied rather than
   replayed (replaying them fails: only 520 of 748 are written defensively
   enough to survive it). The cutoff is `HDATE` from `core/bin/muse` — upstream's
   own build timestamp, regenerated alongside `schema.sql`, so a new release
   moves it without anyone editing this repo.
6. **Repair** — the interesting part. The bundled SQL lags the migrations badly,
   in ways that are not optional:

   | Gap | Consequence if ignored |
   |---|---|
   | ~130 tables that migrations create are absent, e.g. `#__users_log_auth` | `plgAuthenticationHubzero` queries it on every login attempt → 500 |
   | ~95 columns are absent — `#__users` alone is missing `loginShell`, `givenName`, `registerIP`, `secret`… | `plg_user_xusers` does an unconditional `UPDATE #__users SET loginShell` on save, so **no account can be created at all**; `plg_user_hubzero` reads `secret` on every login |
   | `#__extensions` still registers `plg_user_joomla`, renamed to `plg_user_hubzero` in 2018 and gone from disk; ~100 shipped plugins are unregistered | `plg_user_hubzero` is what populates the session user, so login dies with `E_HUBZERO_USER_PLUGIN_FAILED` — **nobody can log in** |

   Neither blunt instrument works. Replaying *every* migration trips an
   uncatchable PHP 8 fatal in `Macros\SavePluginParams` (incompatible `__invoke`
   signature), and `muse migration -f` aborts on the first error regardless.

   So [repair-schema.php](../assets/scaffold/docker/bin/repair-schema.php) replays a targeted
   subset, worked out from the migrations themselves rather than a hand-written
   list that would rot. It reads every migration and queues three groups:

   1. migrations that `CREATE` a table the database lacks — skipping tables a
      later migration deliberately dropped
   2. migrations that `ADD` a column the database lacks
   3. **every extension's own migrations** — an extension's `migrations/`
      directory *is* its install script, per HUBzero's docs, and it is the only
      place some columns are defined at all (`#__users.secret` is declared as a
      PHP array there, not as literal SQL, so no amount of SQL parsing finds it)

   All of it runs in one chronological pass, because migrations are written to
   apply in order from any earlier state. Failures are tolerated per migration,
   so one unused component cannot block the rest. On 2.4.1 that is ~720
   migrations, of which 2 fail as expected (two `plg_cron_*` registrations that
   insert `NULL` into a `NOT NULL` column upstream, leaving those two optional
   cron jobs unregistered). `HUB_VERBOSE_REPAIR=1` lists them.
7. **Pending migrations** — `muse migration -f` for anything committed upstream
   after the release. `HUB_SKIP_MIGRATIONS` retires ones that cannot succeed
   here; it defaults to a single `com_tools` migration that targets HUBzero's
   *middleware* database, which this stack does not run.
8. **Administrator** — `data.sql` seeds the usergroup tree but no accounts, and
   there is no `muse user:create`, so a stock install has nobody who can log in.

On later boots, steps 4–6 and 8 are skipped and only `muse migration -f` runs,
so a source update never leaves the schema behind.

## Four upstream behaviours that will surprise you

**TLS is mandatory.** `com_login`'s controller hardcodes a redirect to `https://`
with no setting to disable it, so the admin panel is unreachable over plain HTTP.
The stack therefore serves `:443`. For local Docker, run
`cli/autohub tls setup --json` to mount a host-trusted mkcert pair; the
container generates a self-signed pair only as a startup fallback. You can
instead set `HUB_TLS_PATH` to a directory containing operator-managed
`hub.crt` and `hub.key`. Behind a proxy, the vhost honours
`X-Forwarded-Proto`, which `com_login` needs since it reads
`$_SERVER['HTTPS']` directly.

**`/usr/bin/php` is symlinked into place.** `core/bin/muse` hardcodes
`#!/usr/bin/php`, but the official PHP images install the binary at
`/usr/local/bin/php`. The admin UI shells out to muse *by path*
(`com_installer`'s `Cli::call`), so without the symlink every such call dies
with "not found", returns empty stdout, and the UI reports the unhelpful
`MUSE <command> returned NULL` — breaking custom extension installs, repository
status and update checks alike.

**A LESS syntax error silently unstyles the whole site.**
`Assets::getSystemStylesheet()` wraps the template's LESS compile in a
try/catch that returns `''` on any error, so the template renders
`<link href="/">` and you get a completely unstyled page with nothing in the
logs — it looks like a broken theme rather than a one-character bug. `hub-assets`
runs the same compile deliberately at boot and prints the file, line and
offending declaration. Note the compiled output lands in `app/cache/<client>/site.css`,
which the vhost must serve even though the rest of `app/` is denied.
Run `cli/autohub assets lint --json` before startup to catch mixed-unit CSS
`min()`/`max()` expressions that legacy LesserPHP cannot evaluate.

**Administrator consent can replace the login form.** Schema repair may enable
`system/userconsent`, so an anonymous administrator request can show a consent
interstitial instead of the CSRF login form. AutoHub reports this distinction.
Configure the required consent policy in production; disable the plugin only
when that is intentional for a local demonstration.

**`HUB_ERROR_REPORTING=none` is deliberate.** HUBzero's error handler turns *any*
PHP warning into a fatal error page, and 2.4.1 still emits warnings on PHP 8 —
`com_content`'s featured-articles view reads an array key it only conditionally
sets, so the stock front page 500s at any other level. Nothing is lost: the
handler logs the warning to `app/logs` *before* deciding whether to die.

## Production notes

- **TLS**: mkcert is development-only. For production, set `HUB_TLS_PATH` to an
  operator-managed directory containing a publicly trusted `hub.crt` and
  `hub.key`, or terminate TLS upstream and let the vhost's
  `X-Forwarded-Proto` handling take over. Set
  `HUB_FORCE_SSL=1` and `HUB_LIVE_SITE=https://your.hub`, and uncomment the HSTS
  header in [hubzero.conf](../assets/scaffold/docker/apache/hubzero.conf) once the hostname has a
  real certificate.
- **Don't publish the database port.** The base compose file doesn't; the dev
  overlay does, deliberately.
- **`HUBZERO_AUTO_UPDATE=0`** in production. Updating on every restart makes the
  deployed revision a function of when a container last happened to bounce.
  Pin `HUBZERO_REF` to a tag and run `make update` when you mean it.
- **Back up `hub_app` and the database together.** They are a matched pair — the
  database references uploads by path. `cli/autohub backup create --json`
  writes a host-side snapshot containing both, plus TLS, the source revision and
  project configuration. Copy production snapshots off-host/off-cluster and
  test restoration separately. `db dump` and the Kubernetes CronJob are
  database-only and are not complete disaster-recovery backups.
- **MariaDB runs with `--sql-mode=`** (strict mode off). HUBzero's schema is full
  of `'0000-00-00'` defaults that strict mode rejects outright. This is a
  property of the CMS, not a shortcut taken here.
- The `cron` service runs `muse cron:jobs run` on a loop in its own container,
  so a failing job shows up in `docker compose logs cron` instead of taking the
  site down with it.

## Requirements

Docker Engine 20.10+ with Compose v2. Builds and runs on both `amd64` and
`arm64`. Browser-trusted local HTTPS requires mkcert; Firefox may also require
the NSS tools described by mkcert for the host platform.
