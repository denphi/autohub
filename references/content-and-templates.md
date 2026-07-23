# HUBzero content and template architecture

Use this reference when building a content-rich hub or a custom site template.
The central rule is simple: content belongs to HUBzero components; a template
renders those components.

## Contents

- [Choose the native owner](#choose-the-native-owner)
- [Provision articles](#provision-articles)
- [Connect menus to articles](#connect-menus-to-articles)
- [Build a template](#build-a-template)
- [Avoid the page-router anti-pattern](#avoid-the-page-router-anti-pattern)
- [Verify the boundary](#verify-the-boundary)

## Choose the native owner

Classify every requested page or record before writing code.

| Requested material | Native owner | Manifest section |
|---|---|---|
| Homepage copy, about, policies, help, landing pages | `com_content` article | `articles:` |
| News or durable educational prose | `com_content` article | `articles:` |
| Dataset, tool, publication, downloadable research object | `com_resources` | `resource_types:` and `resources:` |
| Research community | `com_groups` | `groups:` |
| User/account | `com_members` / `com_users` | component configuration and `users:` |
| Primary navigation and route aliases | `com_menus` | `menus:` |
| Header, footer, grid, typography, component presentation | site template | template files |

Do not invent a `Pages` resource type for ordinary site pages. A resource is a
catalogued research object with resource behavior; it is not a substitute for
an editable article.

Use an installed domain component when it owns the concept. For example, use
native groups rather than writing group cards that merely look interactive.
Presentation may summarize native records, but the canonical record must still
exist in the component.

## Provision articles

Declare general pages under `articles:`. The provisioner matches each article
by alias and creates or updates `#__content`. The default category is the
existing `uncategorised` com_content category.

```yaml
articles:
  - title: Home
    alias: home
    category: uncategorised
    content: |
      <section class="hero">
        <p class="eyebrow">Research community</p>
        <h1>Make research easier to discover and reproduce.</h1>
        <p>Finished, editable homepage copy belongs here.</p>
      </section>
    attribs:
      show_title: 0
    metadesc: Discover the hub's research, tools, and community.

  - title: About
    alias: about
    content: |
      <h1>About</h1>
      <p>Explain the mission, audience, governance, and attribution.</p>
```

Supported article fields include `id` (optional pinned id), `title`, `alias`,
`category` (alias or id), `content`/`introtext`, `fulltext`, `state` or
`published`, `created_by`, `access`, `language`, `attribs`/`params`, `metadata`,
`metakey`, `metadesc`, `images`, `urls`, and `xreference`.

Prefer stable aliases over pinned ids. Pin an id only when another durable
external contract requires it. The provisioner rejects an id/alias collision
instead of overwriting unrelated content.

Store prose, semantic content markup, links, and content-specific media
references in the article. Store shared styles and behavior in the template.
Do not embed secrets, environment-specific hostnames, or generated credentials.

## Connect menus to articles

Use the article alias shorthand instead of copying a database id into a menu:

```yaml
menus:
  site:
    prune: true
    items:
      - title: Home
        alias: home
        article: home
        home: true

      - title: About
        alias: about
        article: about
```

Provisioning resolves each alias to a published `#__content` row and writes the
normal `com_content` article link with the correct component id. A missing or
unpublished article fails that menu step rather than creating a dead route.

Use an explicit `link:` for other components, such as `com_resources` or
`com_groups`. Use `type: url` only for a real external or intentionally raw
path. Do not use URL menu items to hide content that should be native.

## Build a template

A complete custom site template normally contains:

```text
templates/<name>/
├── index.php
├── component.php
├── error.php
├── templateDetails.xml
├── less/
│   └── site.less
├── js/
├── images/
└── html/                 optional, narrowly scoped component overrides
```

Add other files only when the template runtime requires them. Declare every
file, folder, and module position in `templateDetails.xml`.

`index.php` owns the document shell and shared presentation:

- document head and metadata output;
- accessible skip link, header, navigation, notices, and footer;
- module positions used by the manifest;
- one component output region using `<jdoc:include type="component" />`;
- shared asset loading and responsive layout.

`component.php` provides the reduced component-only document used by modal,
print, or `tmpl=component` requests. `error.php` presents CMS errors without
turning them into false successful pages. `site.less` supplies the complete
responsive visual system, and optional `html/` overrides adjust native markup
without replacing ownership of the underlying records.

Render primary navigation from the configured menu module. Do not hard-code a
second navigation list in `index.php`. Render login/account state from a module
or shared template chrome, not from page-specific content files.

## Avoid the page-router anti-pattern

Reject a template implementation that does any of the following:

- switches on `REQUEST_URI`, a menu alias, or a route to select page content;
- includes `templates/<name>/pages/home.php`, `about.php`, or similar files;
- stores datasets, guides, news, FAQs, or groups in template-side PHP arrays;
- renders a handcrafted page instead of the component buffer;
- duplicates native resources or groups as decorative cards with no native
  records behind them;
- makes routine copy changes require a template code deployment;
- passes route checks only because the template returns HTML for every alias.

This pattern may look complete in screenshots while leaving Article Manager,
resource search, permissions, feeds, metadata, menus, and administrator editing
empty. It also turns the template into an application router, which breaks the
CMS contract.

If a native component cannot express a required content type, extend
`hub.yml`, `provision.php`, and the CLI verification surface. Do not conceal the
gap with static PHP.

## Verify the boundary

Before provisioning a content-rich build, run:

```bash
python3 <skill-dir>/scripts/audit_site_architecture.py \
  <project-directory> --require-native-content --json
```

This fails on missing baseline template files, template-side PHP pages,
route-dispatch logic in `index.php`, template-side PHP catalogs, a missing
component buffer, a `Pages` resource type, or a missing native article section.

After `cli/autohub provision --json`, run read-only checks such as:

```bash
cli/autohub db query "SELECT id, alias, title, state FROM jos_content ORDER BY id" --json
cli/autohub db query "SELECT alias, link, component_id, published FROM jos_menu WHERE menutype='mainmenu' ORDER BY lft" --json
cli/autohub db query "SELECT id, title, published FROM jos_resources ORDER BY id" --json
```

Then verify the routes anonymously and while authenticated. Confirm that:

- article routes render the declared article body through `com_content`;
- an administrator can find the page in Article Manager;
- resource and group links open native component records;
- menu aliases resolve to the intended component;
- the template renders unknown/native component routes without special cases;
- changing article content and reprovisioning does not require editing the
  template;
- no `pages/*.php` or template-side catalog is acting as the content database.

HTTP 200 alone is insufficient: a catch-all template router can return 200 for
content that was never provisioned. Pair route checks with native-record checks.
