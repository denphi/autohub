---
name: autohub
description: Create, deploy, diagnose, back up, restore, upgrade, and safely maintain HUBzero CMS hubs with the bundled AutoHub scaffold and `cli/autohub` command. Use to start a new Docker or Kubernetes HUBzero project, operate an existing AutoHub project, change its manifest or template, investigate failures, or perform guarded day-2 operations. Do not use for unrelated PHP applications or direct production mutations outside an AutoHub project.
---

# Operate HUBzero with AutoHub

Resolve `<skill-dir>` to the directory containing this `SKILL.md`. Keep the
bundled project under `assets/scaffold/` immutable; create a working project
from it or operate an existing project that already contains `cli/autohub`.
Run `<skill-dir>/scripts/*` from anywhere with an absolute path; run
`cli/autohub` from inside the working project directory (or pass
`--project-dir`), never from `<skill-dir>`.

Use `--json` for every non-streaming AutoHub command. Treat its
`{ok, action, details, checks, next, data?}` object and exit status as the contract.
Never infer success from a command merely finishing, and never report success
while a required check failed, was skipped, or still needs visual inspection.

## Route the task

- For a new hub or project, scaffold first and follow **Create a project**.
- For an existing AutoHub project, start with **Establish the target and
  baseline**.
- For a broken page or service, use **Diagnose a hub** before changing state.
- For configuration, template, upgrade, reset, backup, or restore work, follow
  the corresponding guarded workflow below.
- Read [references/scaffold.md](references/scaffold.md) for configuration,
  project layout, first-boot behavior, and production notes.
- Read [references/design.md](references/design.md) when changing the CLI,
  driver abstraction, manifest behavior, verification, or backup architecture.
- Read [DESIGN.md](DESIGN.md) for the implemented Projects, Resources,
  Publications, and Courses adapter contract.
- Read [references/content-and-templates.md](references/content-and-templates.md)
  before creating site pages, navigation, a custom template, or a substantial
  content catalog.
- Read [references/native-component-styling.md](references/native-component-styling.md)
  before creating or changing a site template. It defines the mandatory
  native-component styling and browser acceptance matrix.
- Read [references/template-override-mechanics.md](references/template-override-mechanics.md)
  before overriding a core view, stylesheet, or script, and before auditing
  overrides a hub already carries. It covers the failures that produce no error:
  unreachable overrides, asset paths the loader ignores, stylesheets that
  replace core instead of layering, cascade and `!important` conflicts, and
  scripts that run before the body exists.

## Create a project

Create a project only in the user-approved destination:

```bash
python3 <skill-dir>/scripts/create_project.py <target-directory> --json
cd <target-directory>
```

The creator refuses to merge into a non-empty directory. Do not copy individual
files by hand or edit the scaffold asset in place. Once created, initialize the
hub identity and configuration:

```bash
cli/autohub init --site "Research Hub" --json
cli/autohub tls setup --json
cli/autohub up --wait --json
cli/autohub verify --scope tls --json
cli/autohub verify --json
```

`init` assigns a project-specific Compose namespace and probes host ports on
wildcard IPv4 and IPv6 interfaces. Do not replace its generated namespace with
a shared name. First boot can take several minutes while the CMS is cloned,
dependencies are installed, and the schema is loaded and repaired. Use
`up --wait`; do not replace it with ad-hoc log polling.

For local Docker projects, `tls setup` uses mkcert to create and install a
local certificate authority in the host trust stores, then issues a leaf
certificate into the ignored `.autohub/tls/` directory. Installing a local CA
changes host trust and can prompt for administrator credentials, so obtain
explicit user/tool authorization before running it. Never copy, expose, or
commit mkcert's `rootCA-key.pem`. If mkcert is unavailable, install it using
the platform's supported package manager with authorization; do not accept a
browser warning as a completed HTTPS setup.

`init` stores generated credentials in `.env` with mode 600. Report that the
credentials were created and where they are stored, but never reproduce a
password or token in chat, JSON, logs, commits, or command arguments. Store
private repository tokens only in `.env` and reference environment variables
from `hub.yml`.

## Establish the target and baseline

Before changing an existing hub, run:

```bash
cli/autohub status --json
cli/autohub verify --json
```

Record the exact target ID, driver, URL, service state, and failed checks. On
Kubernetes, pin context, namespace, and release in `autohub.yml`; do not rely on
an implicit current context for destructive work.

Separate pre-existing failures from the requested change. Diagnose failures
that block the requested work, but do not silently expand the task into
unrelated repairs. Report unrelated failures and request direction before
making materially broader changes.

## Diagnose a hub

Use the narrowest useful sequence:

```bash
cli/autohub doctor --json
cli/autohub logs --errors --json
cli/autohub verify --scope <site|tls|assets|components|mail|login|db> --json
cli/autohub db query "SELECT ... FROM jos_extensions" --json
```

Keep database queries read-only and avoid credential, session, token, or
personal-data columns. Use interactive `db shell`, streaming logs, or raw
`muse` only when the task requires them; those operations do not produce a
single JSON result.

Map common evidence to the smallest relevant action:

| Symptom | Evidence | Usual action |
|---|---|---|
| Site is unstyled or links to `/` | asset verification and LESS output | fix the reported LESS line; run `assets build` |
| Core action returns 500 | doctor reports unreachable SMTP | restore the mail service or relay; verify mail and the action |
| Missing table or column | error log and DB scope | run `migrate`, then full verification |
| Required seed is missing | doctor identifies a known seed | run `provision`, then full verification |
| Changed CSS appears stale | no server error; old browser bytes | clear and warm caches, then hard-reload |
| Browser reports an untrusted certificate | TLS scope or `tls status` fails | run authorized `tls setup`, recreate the web service, then verify TLS |
| Provision refuses a stale manifest, or reports "manifest is empty" against a non-empty hub.yml | provision preflight; mounted hub.yml differs from the project file | Docker: `down` then `up --wait` (a single-file bind mount is not remounted by `up` alone); Kubernetes: `up` refreshes the manifest ConfigMap and rolls the pod |
| A route serves the wrong content but returns 200 | an article alias matches a component route, or a resource uses a middleware `Tools` type | rename the article alias; model the record on a non-middleware resource type |

## Apply configuration changes

Represent supported additions and parameter changes in `hub.yml`, then run:

```bash
cli/autohub provision --json
cli/autohub verify --json
```

`provision` first proves the manifest mounted in the container matches the
project `hub.yml` and refuses to run against a stale mount, reporting the
driver-specific remedy. Follow that remedy rather than editing files inside
the container.

Provisioning is additive-only. Treat `hub.yml` as authoritative for supported
configuration that provisioning can apply, not as a convergent inventory of
live state. Removing an entry from YAML does not delete the live object. For a
requested removal, take a full snapshot, identify a supported disable/removal
operation, confirm the exact target and blast radius, persist any supported
state in the manifest, and verify.

Set CMS configuration through a reproducible source, never by hand-editing the
rendered `app/config/*.php` files. Those files are generated on every boot and
update by `hub-config-render` from layered inputs. Supply a value the hub lacks
as a `HUBCFG_<group>__<key>` variable in `.env` (for example
`HUBCFG_app__virus_scanner=…`), or through `hub.yml` when the provisioner maps
that key. A value written directly into `app/config/app.php` is not part of the
committed manifest, so it does not survive a rebuild into a fresh environment
even when it survives a restart. If a required setting has no supported
`.env`/`hub.yml` path, extend the provisioner rather than editing generated PHP.

Do not make a live database edit the final fix. A diagnostic edit is incomplete
until a reproducible manifest or provisioning mechanism exists.

## Build native projects, resources, publications, and courses

Translate ordinary user language to the native owner before writing content:

| User intent | Native owner | Command |
|---|---|---|
| Team workspace or publication owner | `com_projects` | `project` |
| Dataset, tool, download, or catalogued research object | `com_resources` | `resource` |
| Authored, versioned research output or image record | `com_publications` | `publication` |
| Course, unit, reading, or linked learning sequence | `com_courses` | `course` |

Do not ask the user to name these components. Infer the owner, then discover
the live capability before creating the manifest section:

```bash
cli/autohub <project|resource|publication|course> describe --json
cli/autohub <project|resource|publication|course> inspect --json
```

Use the description's supported fields and limitations; do not invent a
column, lifecycle state, category, license, or asset type. Put files under the
project's `content/` directory and reference them from `hub.yml` as
`content/<relative-path>`. Never copy an arbitrary absolute path into component
storage. The adapter rejects traversal, escaping symlinks, executable content,
oversize files, MIME mismatches, and undecodable images.

Declare dependencies and apply them in this order:

```text
users/groups -> projects -> resources -> publications -> courses
```

Project aliases on the pinned HUBzero revision must contain 3–30 lowercase
alphanumeric characters; the other native aliases may also contain hyphens.
Publications require a declared or existing project and author. Courses may
link to a declared or existing resource or publication. Prefer a draft unless
the user clearly requested publication.

Always review the component plan before mutation:

```bash
cli/autohub <domain> plan --manifest hub.yml --max-items <requested-limit> --json
cli/autohub <domain> apply --manifest hub.yml --max-items <requested-limit> --json
cli/autohub <domain> verify --manifest hub.yml --json
cli/autohub verify --scope components --json
```

Pass each exact `--authorize <value>` reported by the plan only when the user
authorized that transition. Publishing, access changes, team changes, and
archival are separate authorizations. Never infer deletion from an absent
manifest item. Do not enroll users, change grades/progress, remove team members
or authors, replace published files, withdraw publications, or remove
units/assets through these additive tools.

After applying, inspect the populated native list and detail routes in a
browser at desktop and mobile widths. Confirm the requested records, metadata,
files/images, navigation, and relevant owner/editor state render through the
native component. HTTP success alone is insufficient.

## Build site content and templates

### Establish the content locale first

Before authoring any prose, determine the language and regional variant the
hub's text must use — British English, American English, Spanish, and so on.
Infer it only when the request, the existing manifest, or the hub's stated
audience makes it unambiguous; otherwise ask the user, offering the plausible
options for that audience. Ask once, early, rather than per page: the answer
governs spelling, punctuation, quotation and date conventions, number and
currency formats, and domain terminology across every article body, menu
title, template string, alt text, and metadata description.

Apply the chosen variant consistently and do not mix conventions within a hub.
Record it in the manifest so later sessions inherit it rather than guessing:
set the hub's configured language, and set `language:` on articles and menu
items when the hub genuinely serves multiple languages. Aliases and route
paths stay lowercase ASCII regardless of the content language.

Keep content and presentation separate. Provision ordinary pages, homepage
copy, policies, news, and learning articles through the `articles:` section of
`hub.yml`. Use `resources:` for datasets, tools, downloads, and catalogued
research objects; `publications:` for native versioned publications;
`courses:` for native learning hierarchies; `projects:` for their team
workspaces; `groups:` for communities; `kb:` for question-and-answer archives,
FAQs, and help articles; and `menus:` for routes. Do not build an FAQ as one
article of `<details>` accordions — `com_kb` owns that shape and supplies
search, per-article routes, categories, and voting. Use
`article: <alias>` on a menu item to resolve a native article without
hard-coding its database id.

Treat the active template as presentation chrome only. Its `index.php` must
render the component buffer and module positions; it must not dispatch on the
request path or menu alias, include a `pages/*.php` tree, hold a hard-coded
content catalog, or replace native components with page-specific PHP. Put
reusable styling, layout, images, JavaScript, module chrome, and narrowly scoped
HTML overrides in the template. Keep editable prose and records in native
HUBzero content.

Before provisioning, write down the mapping from each requested item to its
native owner (`articles`, `projects`, `resources`, `publications`, `courses`,
`kb`,
`groups`, or `menus`). If the manifest lacks a required native content surface,
extend the provisioner and its tests instead of hiding the content in the
template.

For a content-rich build, run the deterministic boundary audit before
provisioning:

```bash
python3 <skill-dir>/scripts/audit_site_architecture.py \
  <project-directory> --require-native-content --json
```

Treat any failed check as blocking. Fix the ownership violation rather than
weakening or bypassing the audit.

For a new project-local template, use the supported generator instead of
editing Docker Compose or inventing a mount:

```bash
cli/autohub template create --name <template-alias> --json
cli/autohub assets lint --json
```

The command creates the complete template baseline, registers and activates it
in `hub.yml`, and configures its project-local mount in `.env`.

Use exactly one semantic page title. Prefer the native article title and style
the component heading. If the article body supplies its own `<h1>`, explicitly
suppress native title/metadata chrome, then verify that the rendered DOM has
exactly one visible `<h1>`; do not depend on adjacent selectors or route-specific
CSS to hide duplicates.

After provisioning, confirm that article aliases exist in `#__content`, menu
items point to `com_content`, resources exist in `#__resources`, and each route
renders through the active component. Content is incomplete if it exists only
as a file beneath the template or cannot be edited through the appropriate
administrator component.

## Change templates and styles

After changing template files, run:

```bash
cli/autohub assets lint --json
cli/autohub assets build --json
cli/autohub cache clear --warm --json
cli/autohub verify --scope assets --json
cli/autohub verify --scope components --json
cli/autohub verify --json
```

Pass each authored route with `--route <path>` when it is not discoverable from
the main menu. The component scope inventories main-menu routes plus
`/resources`, `/groups`, `/members`, `/search`, and `/support`, and sweeps each
route's static assets. It proves reachability, not visual correctness.

Then inspect every authored route and the mandatory native-component matrix
with an available browser or browser automation capability. Check anonymous
desktop and narrow/mobile states; check authenticated states where applicable.
Test empty states for every reachable public component and populated list/detail
states for components the hub uses. Check headings, main/aside layouts, filters,
forms, actions, tables, tabs, pagination, messages, focus, overflow, and errors.
Perform local visual and administrator QA over HTTPS after
`verify --scope tls` passes. A certificate warning or browser-automation trust
failure is an incomplete deployment prerequisite: run authorized `tls setup`,
recreate the web service, and retry. If host-trust authorization is denied,
report HTTPS/browser verification as incomplete; do not silently switch to HTTP
and claim completion. If no browser capability is available, report visual
verification as incomplete instead of claiming success.

Legacy LesserPHP cannot evaluate mixed-unit CSS `min()`/`max()` expressions.
Run the host-side lint before startup and replace them with compatible width,
max-width, height, or min-height constraints. Only `site.less` auto-compiles.
Rebuild paired template, vendor, group, and course-layout assets. Inspect load
order and specificity, and hard-reload static assets that lack a version query.

When the change overrides a core view, stylesheet, or script, follow
[references/template-override-mechanics.md](references/template-override-mechanics.md).
Prove the override is reachable before rebasing it, place assets at
`html/<extension>/<file>` where the loader resolves them, import core's
stylesheet before layering on it, and remember that component and plugin CSS
loads *after* the template's and often scopes under an id. Confirm a deployed
change by fetching the route and matching a marker only the new code emits —
"my change is wrong" and "my change is not deployed" look identical otherwise.

Before publishing template commits, run `template status --json` and review the
branch, remote, ahead/behind state, and dirty files. Push only when the user
explicitly asks to publish to that remote. Prefer a normal push; use
`--force` (`--force-with-lease`) only with explicit authorization after
reviewing remote divergence.

## Create and restore recovery points

Before risky work, create a full-state snapshot:

```bash
cli/autohub backup create --label before-change --json
```

The snapshot contains the database, `hub_app`, TLS, CMS source commit, and
project configuration. It includes `.env`; never commit or casually share it.
Copy production snapshots to independent off-host or off-cluster storage and
periodically test restoration in a separate project or namespace.

`db dump --json` and the Kubernetes backup CronJob are database-only. Do not
treat either as a complete disaster-recovery backup.

Restore only during an authorized maintenance window. Resolve and state the
destination target first:

```bash
cli/autohub backup restore backups/<snapshot> \
  --force --confirm '<exact-target-id>' --json
cli/autohub verify --json
```

Review the snapshot's saved project configuration separately instead of
blindly overwriting the live `.env`.

## Upgrade or reset

Test upgrades against a separate project or namespace restored from a
representative snapshot:

```bash
cli/autohub update --ref <pinned-tag-or-sha> --json
cli/autohub verify --json
```

`update` takes a host-side full-state snapshot before changing source,
dependencies, schema, configuration, caches, or assets. Do not pass
`--no-backup` in routine operation. If verification regresses, restore the
coordinated snapshot rather than restoring only its database under newer
source.

Reset only when the user explicitly requests destruction of the resolved hub:

```bash
cli/autohub status --json
cli/autohub backup create --label pre-reset --json
cli/autohub destroy --force --confirm '<exact-target-id>' \
  --snapshot 'backups/<completed-snapshot>' --json
cli/autohub up --wait --json
cli/autohub verify --json
```

Do not destroy a target when its requested and resolved identities differ. A
confirmation flag does not replace explicit authorization or a durable backup.

## Preserve these invariants

- Keep `.env` for secrets and infrastructure, `hub.yml` for supported hub
  configuration, and `autohub.yml` for the deployment target.
- Use HTTPS for browser and administrator verification. Local Docker should
  use `autohub tls setup` and a host-trusted mkcert leaf certificate;
  production must use a publicly trusted certificate or trusted ingress, never
  the mkcert development CA.
- Stay behind `cli/autohub`. If a required operation is missing, improve the
  CLI instead of embedding raw Docker- or Kubernetes-specific commands here.
- Never reproduce `.env`, passwords, tokens, sessions, private keys, or
  sensitive query results. If one is exposed, call out rotation or revocation.
- Require explicit authorization and exact-target confirmation for destroy,
  restore, forced initialization, and other irreversible operations.
