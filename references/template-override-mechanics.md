# Template override mechanics

Use this reference when a template overrides a core view, stylesheet, or script,
and when auditing overrides a hub already carries. Every rule here comes from a
failure observed on a running hub, and most of them **fail silently** — no
error, no warning, just markup that does nothing or styling that never lands.

## Contents

- [Prove the override is reachable](#prove-the-override-is-reachable)
- [Put assets where the loader looks](#put-assets-where-the-loader-looks)
- [Import core stylesheets before layering](#import-core-stylesheets-before-layering)
- [Win the cascade deliberately](#win-the-cascade-deliberately)
- [Remember what a shim stops declaring](#remember-what-a-shim-stops-declaring)
- [Keep extracted scripts out of the head trap](#keep-extracted-scripts-out-of-the-head-trap)
- [Never hardcode the template directory](#never-hardcode-the-template-directory)
- [Build routed URLs, do not concatenate them](#build-routed-urls-do-not-concatenate-them)
- [Read model fields with get()](#read-model-fields-with-get)
- [Import facades inside namespaces](#import-facades-inside-namespaces)
- [Choose PHP or CSS by capability](#choose-php-or-css-by-capability)
- [Validate before shipping](#validate-before-shipping)

## Prove the override is reachable

Before diffing or rebasing an override, establish what selects it. Overrides
that nothing renders are common, and they read exactly like live code.

Ask three questions:

1. **What names this layout?** Component controllers set layouts explicitly.
   `com_resources` picks a resource-type view only when a file matching the type
   *alias* exists, so `_workshops.php` or `_remove_courses.php` can never be
   selected — a leading underscore is not an alias. Files renamed to disable
   them (`item.php_`) are the same category.
2. **Do the paths it references still exist?** An override that includes
   `components/<com_x>/site/views/<view>/tmpl/<file>.php` is dead once core
   moves or removes that directory.
3. **Are its entry points still called?** Joomla-era hook functions
   (`pagination_list_footer`, `pagination_item_active`) are not invoked by
   current HUBzero, so a file defining them is inert wherever it sits.

Some override points no longer exist at all. `Paginator::render()` constructs
its view with no override path, so **template pagination overrides cannot fire**
regardless of location. Confirm against rendered output: request a paginated
route and look for markup only one implementation emits.

Auditing this way on one hub retired four files that had been carried, and
reviewed, as if they were live.

## Put assets where the loader looks

`Asset\File::overridePath()` resolves to:

```
<template>/html/<extension>/<file>
```

`<extension>` is `com_x`, `plg_<group>_<name>`, or `mod_x`. **The view directory
is not part of the path.** A plugin script belongs at
`html/plg_projects_publications/vacheck.js`, not
`html/plg_projects_publications/draft/vacheck.js`.

Assets that resolve nowhere are skipped without error — `AssetAware::js()` tests
`exists()` first — so a misplaced file produces a page that simply lacks the
behavior. Load them through the API rather than by hand:

```php
\Hubzero\Document\Assets::addPluginStylesheet('resources', 'usage');
\Hubzero\Document\Assets::addPluginScript('resources', 'usage', 'usage');
```

A file that exists **only** in the template still resolves, so this is also the
way to ship template-authored scripts for a component or plugin.

## Import core stylesheets before layering

A template stylesheet **replaces** core's file of the same name; it does not
merge with it. Start every component or plugin stylesheet with the core import:

```css
@import url("/core/components/com_groups/site/assets/css/groups.css");
```

Without it, all of core's styling for that extension disappears. This is easy to
miss when the override is small: a 15-line `media.css` silently replaced core's
671-line media-manager styling on one hub, and a commented-out import left a
component running entirely on template CSS.

Audit with a size comparison — an override much smaller than its core
counterpart and lacking an `@import` is the signature.

## Win the cascade deliberately

Load order on a component page:

1. `site.css` (the compiled template, including any modernisation layer)
2. component and plugin stylesheets, added by the component at render time

Component and plugin CSS therefore lands **after** the template's, and wins
every tie. Template rules restyling native components must out-specify, not
merely match.

Core also scopes much of its component CSS under ids — `#usage-section
.usage-wrap`, `#usage-section tbody th` — which outranks any number of classes.
Match the id and add the component class:

```less
.com_resources #usage-section .usage-wrap { … }   // (1,2,0) beats (1,1,0)
```

And core uses `!important` in places:

```css
.upperpane .rankarea, .section .extracontent, .upperpane .launcharea {
    margin-top: 0 !important;
    padding-top: 0 !important;
}
```

No specificity defeats that. Match `!important` from a more specific selector,
and say why in a comment — an unexplained `!important` is what the next person
deletes.

## Remember what a shim stops declaring

Reducing an override to a shim that includes core's template is the best outcome
for maintenance, but **assets are declared by the file you no longer include**.
Core templates call `$this->css()` and `$this->js()` at the top; a shim that
includes core only on some branches skips those calls on the others.

Symptom: one tab or view is styled and the rest are bare, with no error. Declare
the assets in the shim itself, before branching:

```php
$this->css();          // every branch needs the component stylesheet
if ($view == 'tags')
{
    include Component::path('com_resources') . '/site/views/browse/tmpl/tags.php';
}
```

## Keep extracted scripts out of the head trap

`Document::addScript()` and `Assets::addPluginScript()` emit into `<head>`.
Script extracted from a view therefore runs **before the body exists**.

Inline view JS gets away with reading the DOM immediately because it sits after
the markup. Extracted JS must not:

```js
var config = JSON.parse(document.getElementById('data').textContent); // null in <head>
if (!config) { return; }                                              // whole module dies
```

Read the document inside a ready handler, and keep module scope free of DOM
access. A single early `return` here disables everything the file does — charts,
handlers, and all — with no console error beyond the one you never see.

## Never hardcode the template directory

`/app/templates/<Name>/...` breaks when the template is renamed and when it is
installed as a protected (core-side) template. Resolve it:

```php
(App::get('template')->protected ? '/core' : '/app')
    . '/templates/' . App::get('template')->template . '/js/vendor/lity.min.js'
```

Prefer `Assets::addPluginScript()` / `addPluginStylesheet()` where the file is a
component or plugin asset, which removes the question entirely.

## Build routed URLs, do not concatenate them

`Route::url()` is not string formatting. Component routers turn query keys into
path segments, and only some values parse back:

- `com_resources` places `task` in a segment, so
  `index.php?option=com_resources&task=browsetags&type=tools` builds
  `/resources/browsetags/tools`, which its own `parse()` rejects — a 404. The
  routed form is the bare type alias; `parse()` restores the task.
- A **numeric** `type` builds `/resources/<id>`, and a numeric first segment is
  read as a *resource id*. Pass the alias.
- The SEF layer renames `limitstart` to `start` on output. Pagination that reads
  `limitstart` never sees the value its own links carry; read both.

Verify a generated link by requesting it, not by reading it.

## Read model fields with get()

In the ORM, transformers take precedence over selected columns
(`Relational::__get`). A model with `transformUsers()` returns that transform for
`$model->users` **even when the query selected a `users` column** — running an
extra query per row and returning a different type.

```php
$count = $line->get('users');            // the selected column
if (is_numeric($count) && $count > 0) { … }
```

Related: `Entry::allWithFilters()` applies `GROUP BY id`, and `total()` counts
without stripping it, so `allWithFilters(...)->paginated()` reports a total of 1.
Build the query directly when you need pagination totals.

When filtering records for display, apply the access rule the site views use —
public for everyone, plus registered once authenticated:

```php
$access = array(0);
if (!User::isGuest())
{
    $access[] = 1;
}
$query->whereIn($table . '.access', $access);
```

Omitting it exposes registered- and protected-level records, including their
full text. Check this explicitly in any override or endpoint that lists records.

## Import facades inside namespaces

Inside `namespace Components\<X>\...`, an unqualified `Config::`, `User::`, or
`Component::` resolves **into that namespace**, not to the global facade, and
throws "class not found" on first use. Add the imports:

```php
use Config;
use User;
```

This hides well: code paths that are rarely reached (an API version that loses
version negotiation, a helper only called when a component is installed) can
carry the bug for years.

## Choose PHP or CSS by capability

CSS can restyle, show/hide, reorder within a formatting context, and add
pseudo-content. It cannot change data or text, add or remove attributes, unwrap
an element, or alter logic.

Decide on that basis, then prefer CSS — a stylesheet rule does not block core
upgrades, and a whole-file override does. Two traps:

- **Truncated overrides.** A copy of a core template with the tail deleted keeps
  everything above it frozen against future core changes, with no diff to warn
  anyone. If the goal is "hide this section", a scoped `display: none` tracks
  core and a truncated file does not.
- **Hiding what still works.** Hiding a control with CSS leaves it in the DOM
  and its route reachable. That is fine when the route is equally reachable
  either way; it is not a substitute for a permission or a configuration flag.

Where core already has a parameter for the behavior, set the parameter instead
of overriding the view, and propose one upstream where it is missing.

## Validate before shipping

- **Lint PHP** with the hub's own interpreter — `docker exec -i <web> php -l < file`
  works when no host PHP is installed.
- **Compile LESS** with the runtime compiler rather than trusting an editor:

  ```php
  require '<core>/vendor/autoload.php';
  require '<core>/libraries/Hubzero/Document/Lessc.php';
  $less = new \Hubzero\Document\Lessc();
  $less->setImportDir(array(dirname($file)));
  $css = $less->compile(file_get_contents($file));
  ```

  Compile the entry point, not a fragment — a fragment fails on variables the
  entry point supplies, which looks like a real error and is not. Where a
  template keeps a hand-maintained `.css` beside its `.less`, generate the CSS
  from the LESS instead of writing it twice.
- **Check duplicate ids.** Core suffixes ids when a module can appear more than
  once (`self::$instances`); overrides that drop that logic emit repeated
  `id="searchform"` / `id="searchword"`. Grep rendered output for
  `id="…"` counts.
- **Confirm against rendered output.** After deploying, fetch the route and
  check for a marker only the new code emits. Comparing `Last-Modified` on a
  static asset distinguishes "my change is wrong" from "my change is not
  deployed" in seconds.
