# HUBzero content and template architecture

Use this reference when building a content-rich hub or a custom site template.
The central rule is simple: content belongs to HUBzero components; a template
renders those components.

## Contents

- [Choose the native owner](#choose-the-native-owner)
- [Write for the hub's locale](#write-for-the-hubs-locale)
- [Provision articles](#provision-articles)
- [Connect menus to articles](#connect-menus-to-articles)
- [Build a template](#build-a-template)
- [Create a project-local template](#create-a-project-local-template)
- [Avoid the page-router anti-pattern](#avoid-the-page-router-anti-pattern)
- [Verify the boundary](#verify-the-boundary)

## Choose the native owner

Classify every requested page or record before writing code.

| Requested material | Native owner | Manifest section |
|---|---|---|
| Homepage copy, about, policies, help, landing pages | `com_content` article | `articles:` |
| News or durable educational prose | `com_content` article | `articles:` |
| Dataset, tool, downloadable research object | `com_resources` | `resource_types:` and `resources:` |
| Versioned publication or image publication | `com_publications` | `projects:` and `publications:` |
| Course, unit, or learning asset | `com_courses` | `courses:` |
| Research team workspace | `com_projects` | `projects:` |
| Question-and-answer archive, FAQ, help article | `com_kb` | `kb:` |
| Research community | `com_groups` | `groups:` |
| User/account | `com_members` / `com_users` | component configuration and `users:` |
| Primary navigation and route aliases | `com_menus` | `menus:` |
| Header, footer, grid, typography, component presentation | site template | template files |

Do not invent a `Pages` resource type for ordinary site pages. A resource is a
catalogued research object with resource behavior; it is not a substitute for
an editable article.

Likewise, do not build an FAQ as one article full of `<details>` accordions.
`com_kb` is installed and enabled in a stock hub and owns exactly that shape,
giving search, per-article routes (`/kb/<category>/<alias>`), categories, and
helpful/not-helpful voting that an accordion throws away. Declare it under
`kb:` with `categories:` and `articles:`; each article needs a `category`,
because a zero category is unreachable, and provisioning sets `access: 1` by
default since the underlying column defaults to a level no one can see.

Use an installed domain component when it owns the concept. For example, use
native groups rather than writing group cards that merely look interactive.
Presentation may summarize native records, but the canonical record must still
exist in the component.

## Write for the hub's locale

Settle the language and regional variant before authoring — British English,
American English, Spanish, and so on — and ask the user when the request does
not make it unambiguous. It decides spelling, punctuation, date and number
formats, and terminology in every article, menu title, template string, and
metadata description, and retrofitting it means rewriting all of them.

Mechanically, each article and menu item carries a `language` field that
defaults to `*` (all languages). Leave it at `*` for a single-language hub and
express the locale in the prose itself. Set it explicitly only when the hub
genuinely serves several languages — and note that `#__menu`'s unique key
includes `language`, so per-language menu items legitimately share an alias
under the same parent. Aliases and route paths remain lowercase ASCII whatever
the content language.

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

Never give an article an alias that matches a native component route
(`support`, `resources`, `groups`, `members`, `search`, `login`, `register`,
`tags`): the article shadows the component — `/support` serves the article, the
ticketing component becomes unreachable, and every reachability check still
passes because the route answers HTTP 200. A hub's "Submit a ticket" page
pointing back at itself is the observed symptom. The provisioner rejects an
alias matching any enabled component, and the architecture audit flags the
reserved names before provisioning.

Store prose, semantic content markup, links, and content-specific media
references in the article. Store shared styles and behavior in the template.
Do not embed secrets, environment-specific hostnames, or generated credentials.

Use one semantic page title. Prefer the native article title and begin the body
below it. If a custom landing-page body supplies its own `<h1>`, declare the
article and `com_content` title/metadata options needed to suppress duplicate
chrome, then verify the rendered DOM has exactly one visible `<h1>`. Do not
solve duplicate titles with route-aware or adjacent-selector CSS.

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
        params:
          show_page_heading: 0

      - title: About
        alias: about
        article: about
```

Provisioning resolves each alias to a published `#__content` row and writes the
normal `com_content` article link with the correct component id. A missing or
unpublished article fails that menu step rather than creating a dead route.

Menu aliases are unique on `(client_id, parent_id, alias, language)` — across
**all** menutypes, not per menu. Declaring an alias that already exists under
the same parent in another menu (for example the CMS's shipped `mainmenu` Home
row) adopts that row into the declared menu instead of inserting a duplicate.
An adopted row keeps its params, which is why the `params:` map matters: it
merges into the item's existing params, and the shipped `home` row carries
`show_page_heading: 1` — a second `<h1>` above an article that supplies its
own, unfixable from the manifest before `params:` existed. Provisioning also
treats "no published default (home) item exists afterwards" as a hard failure,
because that state 404s the front page.

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

**Ship a sign-out affordance.** A template with a sign-in link and no way back
out strands every authenticated user, and the bug report is simply "I cannot
log out". Immediate logout carries the session form token; the tokenless
`com_users&view=logout` route only reaches a confirmation view:

```php
Route::url('index.php?option=com_login&task=logout&' . Session::getFormToken() . '=1');
```

Put it in the header chrome behind `User::isGuest()`, alongside the account
name. Note that `system/incomplete`, if enabled without a real profile policy,
intercepts navigation *including this route* until the user fills in Residency,
Citizenship and Racial Background — which presents as the same bug.

Load assets through the document asset API and derive template image/script
paths from the CMS base URL and active template name. Do not hard-code
`/app/templates/<name>` URLs.

`site.less` must begin by importing core's stylesheet
(`@import "../../../../core/assets/less/site.less";`) — a template layers on
core, it does not replace it. Constrain the main content column itself (the
starter's `.ah-main`) rather than a fixed list of component wrapper classes:
components emit wrappers a template cannot enumerate (`#content-header`,
`section.section`, …), and anything missing from a child-selector width rule
runs flush against the viewport edge. Never author `icon-`-prefixed classes —
core fontcons claims that prefix.

Keep native typography and controls restrained, then scope expressive
landing-page design under a project-specific wrapper. Follow
[native component styling and acceptance](native-component-styling.md) for the
required base primitives and browser matrix.

## Create a project-local template

Use the scaffolded workflow for a new template:

```bash
cli/autohub template create --name researchhub --json
cli/autohub assets lint --json
```

The command copies the complete starter into `templates/researchhub`, updates
the project-local mount in `.env`, and registers and activates the alias in
`hub.yml`. Do not edit `docker-compose.yml` to invent a one-off bind mount.

The host-side lint blocks mixed-unit CSS `min()` and `max()` calls that
HUBzero's legacy LesserPHP compiler attempts and fails to evaluate. Use
`width`/`max-width` or `height`/`min-height` constraints instead. Compile again
inside the running CMS because the runtime compiler remains authoritative.

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
component buffer, a `Pages` resource type, a missing native article section,
a stylesheet that neither imports core's stylesheet nor defines the grid
primitives, an article alias that shadows a native component route, missing
shared native-component style surfaces, or hard-coded template asset paths in
the PHP shell.

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
