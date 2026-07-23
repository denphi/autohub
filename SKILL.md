---
name: autohub
description: Create, deploy, diagnose, back up, restore, upgrade, and safely maintain HUBzero CMS hubs with the bundled AutoHub scaffold and `cli/autohub` command. Use to start a new Docker or Kubernetes HUBzero project, operate an existing AutoHub project, change its manifest or template, investigate failures, or perform guarded day-2 operations. Do not use for unrelated PHP applications or direct production mutations outside an AutoHub project.
---

# Operate HUBzero with AutoHub

Resolve `<skill-dir>` to the directory containing this `SKILL.md`. Keep the
bundled project under `assets/scaffold/` immutable; create a working project
from it or operate an existing project that already contains `cli/autohub`.

Use `--json` for every non-streaming AutoHub command. Treat its
`{ok, action, details, checks, next}` object and exit status as the contract.
Never infer success from a command merely finishing, and never report success
while a required check failed, was skipped, or still needs visual inspection.

## Route the task

- For a new hub or project, scaffold first and follow **Create a hub**.
- For an existing AutoHub project, start with **Establish the target**.
- For a broken page or service, use **Diagnose a hub** before changing state.
- For configuration, template, upgrade, reset, backup, or restore work, follow
  the corresponding guarded workflow below.
- Read [references/scaffold.md](references/scaffold.md) for configuration,
  project layout, first-boot behavior, and production notes.
- Read [references/design.md](references/design.md) when changing the CLI,
  driver abstraction, manifest behavior, verification, or backup architecture.
- Read [references/content-and-templates.md](references/content-and-templates.md)
  before creating site pages, navigation, a custom template, or a substantial
  content catalog.
- Read [references/native-component-styling.md](references/native-component-styling.md)
  before creating or changing a site template. It defines the mandatory
  native-component styling and browser acceptance matrix.

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
cli/autohub up --wait --json
cli/autohub verify --json
```

`init` assigns a project-specific Compose namespace and probes host ports on
wildcard IPv4 and IPv6 interfaces. Do not replace its generated namespace with
a shared name. First boot can take several minutes while the CMS is cloned,
dependencies are installed, and the schema is loaded and repaired. Use
`up --wait`; do not replace it with ad-hoc log polling.

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
cli/autohub verify --scope <site|assets|components|mail|login|db> --json
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

## Apply configuration changes

Represent supported additions and parameter changes in `hub.yml`, then run:

```bash
cli/autohub provision --json
cli/autohub verify --json
```

Provisioning is additive-only. Treat `hub.yml` as authoritative for supported
configuration that provisioning can apply, not as a convergent inventory of
live state. Removing an entry from YAML does not delete the live object. For a
requested removal, take a full snapshot, identify a supported disable/removal
operation, confirm the exact target and blast radius, persist any supported
state in the manifest, and verify.

Do not make a live database edit the final fix. A diagnostic edit is incomplete
until a reproducible manifest or provisioning mechanism exists.

## Build site content and templates

Keep content and presentation separate. Provision ordinary pages, homepage
copy, policies, news, and learning articles through the `articles:` section of
`hub.yml`. Provision datasets, tools, publications, and other catalogued
research objects through `resources:`; communities through `groups:`; and
routes through `menus:`. Use `article: <alias>` on a menu item to resolve a
native article without hard-coding its database id.

Treat the active template as presentation chrome only. Its `index.php` must
render the component buffer and module positions; it must not dispatch on the
request path or menu alias, include a `pages/*.php` tree, hold a hard-coded
content catalog, or replace native components with page-specific PHP. Put
reusable styling, layout, images, JavaScript, module chrome, and narrowly scoped
HTML overrides in the template. Keep editable prose and records in native
HUBzero content.

Before provisioning, write down the mapping from each requested item to its
native owner (`articles`, `resources`, `groups`, `menus`, or another installed
component). If the manifest lacks a required native content surface, extend
the provisioner and its tests instead of hiding the content in the template.

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
For local visual QA, use the equivalent HTTP frontend if automation rejects the
self-signed certificate; retain HTTPS for administrator login verification. If
no browser capability is available, report visual verification as incomplete
instead of claiming success.

Legacy LesserPHP cannot evaluate mixed-unit CSS `min()`/`max()` expressions.
Run the host-side lint before startup and replace them with compatible width,
max-width, height, or min-height constraints. Only `site.less` auto-compiles.
Rebuild paired template, vendor, group, and course-layout assets. Inspect load
order and specificity, and hard-reload static assets that lack a version query.

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
- Use HTTPS for administrator login. Local Docker can use a self-signed
  certificate; production must use a trusted certificate.
- Stay behind `cli/autohub`. If a required operation is missing, improve the
  CLI instead of embedding raw Docker- or Kubernetes-specific commands here.
- Never reproduce `.env`, passwords, tokens, sessions, private keys, or
  sensitive query results. If one is exposed, call out rotation or revocation.
- Require explicit authorization and exact-target confirmation for destroy,
  restore, forced initialization, and other irreversible operations.
