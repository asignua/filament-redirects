# Filament Redirects

[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://stand-with-ukraine.pp.ua)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/asignua/filament-redirects.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-redirects)
[![Tests](https://img.shields.io/github/actions/workflow/status/asignua/filament-redirects/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/asignua/filament-redirects/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/asignua/filament-redirects.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-redirects)
[![License](https://img.shields.io/packagist/l/asignua/filament-redirects.svg?style=flat-square)](https://github.com/asignua/filament-redirects/blob/main/LICENSE.md)
[![Plumb score](https://plumbphp.dev/badges/asignua/filament-redirects/composite.svg)](https://plumbphp.dev/asignua/filament-redirects)

<img class="filament-hidden" src="https://raw.githubusercontent.com/asignua/filament-redirects/v1.0.0/art/cover.jpg" alt="Filament Redirects">

A redirect manager **and a 404 log** for [Filament](https://filamentphp.com) panels, with one button that turns a
dead URL from the log into a redirect.

The best-known Filament 5 redirect plugin has a few thousand downloads and none of the existing ones combines a 404 log with a one-click redirect. Redirect plugins stop at the table of rules. This one also watches what your visitors (and Googlebot) actually hit,
aggregates it by path with a referrer sample and the kind of client, drops the scanner noise, and lets you answer a
row with a redirect in one click. And the redirects themselves are the kind an SEO audit will not flag: they only
fire on a real 404, they are always **one hop** (chains are collapsed when you save, loops are refused), a slashed
`/old/` reaches the target of `/old` in a single hop, and `HEAD` is served like `GET`.

- [Screenshots](#screenshots)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Putting the middleware on your site](#putting-the-middleware-on-your-site) — read this one
- [How paths are stored](#how-paths-are-stored)
- [The 404 log](#the-404-log)
- [Multilingual sites](#multilingual-sites)
- [Creating redirects from code](#creating-redirects-from-code)
- [Hooks of the registry](#hooks-of-the-registry)
- [Authorization](#authorization)
- [Commands and schedule](#commands-and-schedule)
- [Configuration](#configuration)
- [Gotchas](#gotchas)
- [Translations](#translations)
- [AI agents](#ai-agents)
- [Testing](#testing)

## Screenshots

The redirects list - one hop, any status code, a hit counter per redirect:

![Redirects](https://raw.githubusercontent.com/asignua/filament-redirects/v1.0.0/art/redirects.jpg)

The 404 log - what visitors and Googlebot actually hit, with a referrer sample and the kind of client:

![404 log](https://raw.githubusercontent.com/asignua/filament-redirects/v1.0.0/art/not-found-log.jpg)

**Create redirect** on a row opens a modal prefilled by `Redirects::suggestUsing()`; the row leaves the log and the old URL redirects at once:

![Create a redirect from a 404 row](https://raw.githubusercontent.com/asignua/filament-redirects/v1.0.0/art/create-from-404.jpg)

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Filament 5

## Installation

```bash
composer require asignua/filament-redirects
php artisan vendor:publish --tag=filament-redirects-migrations
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=filament-redirects-config
```

The package ships no CSS or JavaScript (it is built from Filament's own components), so there is nothing to
`filament:assets`.

Register the plugin in your panel provider:

```php
use Asignua\FilamentRedirects\RedirectsPlugin;

$panel->plugin(RedirectsPlugin::make());
```

Then put the middleware on your site - the next sections explain why the package cannot do that for you.

## Quick start

An ordinary Laravel app: append the middleware globally in `bootstrap/app.php`.

```php
use Asignua\FilamentRedirects\Redirects;
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    Redirects::appendTo($middleware);
})
```

Add a redirect in the **Redirects** resource - or just visit a dead URL on your site, open **404 log** and click
**Create redirect** on its row.

## Putting the middleware on your site

`Redirects::middleware()` returns `[RedirectTrailingSlash::class, RedirectFallbackMiddleware::class]`. Both are
**after** middleware: nothing is looked up until the request has already produced a 404.

For a site that serves everything through one catch-all route (a CMS front), put it on that route:

```php
Route::middleware(['web', ...Redirects::middleware()])
    ->get('/{path?}', FrontController::class)
    ->where('path', '.*');
```

**The trap that is the reason the package registers nothing by itself:** a package cannot push a global middleware
(or one into the `web` group) from its own `boot()`. The HTTP kernel is built in `public/index.php` before the service
providers boot, so `afterResolving(Kernel::class)->pushMiddleware(...)` and `pushMiddlewareToGroup(...)` silently do
nothing in a real request - while appearing to work in tests, which resolve the kernel later. Route middleware is
resolved at dispatch, and `bootstrap/app.php` is read before the kernel exists; those two are reliable.

You do not need a locale middleware in front of them: both parse the language from the path themselves.

## How paths are stored

`old_path` and `to_path` hold the shape of a request path **after** the language prefix is split off:

| Value | Stored as | Served as (default language / `en`) |
| --- | --- | --- |
| A page | `shop/item` | `/shop/item` / `/en/shop/item` |
| The home page | `/` | `/` / `/en` |
| An external site | `https://other.site/x` (the whole URL) | `https://other.site/x` |
| Gone | `` (empty) with code 404 | the site's own error page with `redirects.gone_status` (410) |

Everything is normalised on save, in one place (`RedirectRepository`), so pasting `https://site.test/en/shop/item/`
into a field of an `en` row stores `shop/item`: our own domain (`app.url`, plus anything you add with
`Redirects::ownUrlsUsing()`, each with its `www.` twin and port) and the row's own language prefix are stripped, a
foreign domain is kept. The leading slash exists only in the form - the field shows `https://site.test` + `/shop` as
an affix, so a redirect to the home page is typed as `/` (an empty target means Gone, not home).

The form refuses spaces, a foreign host as a *source*, the home page as a source (it never 404s), a duplicate source in
the same language, a source that starts with another language's prefix (it could never match), a target equal to the
source or leading back to it, and a broken URL as a target.

## The 404 log

The fallback middleware writes every unmatched `GET` that has no redirect into the log: one row per
(path, language) with a hit counter, first/last seen, a **referrer sample** and the **User-Agent family**
(`Googlebot`, `Chrome`, `curl`... - the full string is deliberately not stored).

- **Create redirect** (row action) opens a modal for the target; `Redirects::suggestUsing()` can prefill it. The row
  leaves the log and the redirect counts the hits from then on. A loop is refused on the target field.
- **Create redirects** (bulk action) sends many paths to one target; rows that would loop stay in the log and are
  reported.
- **Recheck** closes rows that now have a redirect - and, with `Redirects::resolvesUsing()`, rows whose path now shows
  a page. **Prune old entries** removes rows by age.
- Filters: language, bots / no bots, with / without a referrer. A `Gone` redirect and a matched redirect are deliberate
  decisions and are never logged.

Noise never reaches the table (`filament-redirects.not_found`):

```php
'ignore' => ['wp-*', '*/wp-*', '*.php', '.env*', '.git/*', '*.png', '*.map', /* ... */], // wildcards, case-insensitive
'ignore_user_agents' => ['*semrushbot*'],
'ignore_bots' => false, // off on purpose: a Googlebot request for a dead URL is what you want to see
```

and for anything the config cannot say: `Redirects::ignoreUsing(fn (Request $request, string $language, string $path): bool => ...)`.
Paths longer than the column (191) are skipped, not truncated - truncated ones would merge different URLs.
A failed log write never breaks the response.

## Multilingual sites

A single-language site needs no setup. Otherwise tell the registry how your URLs map to languages:

```php
Redirects::locales(default: 'uk', all: ['uk', 'en', 'de'], unprefixed: 'uk');
// /about -> uk, /en/about -> en, /de/about -> de
```

- The row has a `language` column and `unique(old_path, language)`; the form shows the language select only when there
  is more than one language.
- A row's own prefix is stripped from what you type; another language's prefix is kept in a target (`en/shop` is a valid
  target of a `uk` row) and refused in a source of the unprefixed language.
- For a CMS that keeps languages in its own config, pass a closure - it is resolved at the moment of use:
  `Redirects::useLocaleUrls(fn (): LocaleUrls => new PrefixedLocaleUrls(...))`.
- For any other URL scheme implement `Asignua\FilamentRedirects\Contracts\LocaleUrls` (`parse`, `url`, `prefix`,
  `default`, `all`, `unprefixed`) - for example, a CMS that resolves URLs through its own link table.

## Creating redirects from code

The `Redirects::create()` API is the door for anything that is not the panel - a listener that redirects the old slug
when a record is renamed, a seeder, a migration:

```php
use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Redirects;

Redirects::create('old/page', 'new/page');                                   // 301, default language
Redirects::create('old/page', 'new/page', 302, 'en');                        // 302 for the `en` row
Redirects::create('old/page', 'https://other.site/x', RedirectCode::PermanentKeepMethod);
Redirects::create('retired', '', RedirectCode::Gone);                        // keep the 404
Redirects::create('old/page', 'new/page', entity: $post);                    // remember what it leads to
```

It normalises, rejects a loop (`ValidationException`), collapses chains and flushes the cache. Never write the model
directly: it has `$guarded = ['*']`, and a field that the repository does not assign is silently not saved. If you
must update the table with raw SQL, call `Redirects::flushCache()` afterwards.

The optional `entity` morph (`entity_type`, `entity_id`) records which record a redirect leads to; give it explicitly,
or let `Redirects::entityUsing(fn (string $language, string $path): ?Model => ...)` find it from the target.

## Hooks of the registry

Everything that is a closure lives on `Asignua\FilamentRedirects\Redirects` (set it in `AppServiceProvider`); each
hook has a neutral default.

| Hook | Used for |
| --- | --- |
| `locales()` / `useLocaleUrls()` | the language scheme (above) |
| `ownUrlsUsing(fn (): array)` | the hosts that count as "this site" (default `app.url`); the first is the base URL |
| `resolvesUsing(fn (string $language, string $path): bool)` | "does an anonymous visitor get a page here?" - used by the recheck and the trailing-slash middleware |
| `entityUsing(fn (string $language, string $path): ?Model)` | ties a new redirect to the record its target leads to |
| `suggestUsing(fn (string $language, string $path): ?string)` | prefills the "Create redirect" modal |
| `statusUsing(fn (string $path, ?string $language): ?array)` | a badge column in the 404 log, e.g. `['label' => 'Draft', 'color' => 'warning']`; hidden when unset |
| `ignoreUsing(fn (Request $request, string $language, string $path): bool)` | one more reason not to log a 404 |

`resolvesUsing` must answer for an **anonymous** visitor: if it shows drafts to a logged-in admin, a draft counts as
found and its 404 row disappears while visitors still get a 404 (the recheck also runs from the panel).

## Authorization

```php
RedirectsPlugin::make()
    ->authorize(fn (): bool => auth()->user()?->isAdmin())
    ->navigationGroup('SEO')
    ->navigationSort(30)
    ->navigationIcon('heroicon-o-arrows-right-left')
    ->redirects()        // or ->redirects(false) to hide the resource
    ->notFoundLog();     // or ->notFoundLog(false)
```

Without `authorize()` the `redirects.manage` gate decides when it is defined; otherwise everyone who can enter the
panel can use the resources. The 404 log item follows the redirects item in the navigation.

## Commands and schedule

| Command | |
| --- | --- |
| `redirects:flush` | forget the cached map |
| `redirects:prune {--days=}` | delete 404 rows not seen for `retention_days` (90) |
| `redirects:recheck {--dry-run}` | close the rows that now have a redirect or a page |

`filament-redirects.schedule.enabled` (off by default, needs the Laravel scheduler) runs `prune` at `03:10` and
`recheck` at `03:20`; both times are configurable.

## Configuration

| Key | Default | |
| --- | --- | --- |
| `tables.redirects` / `tables.not_found` | `redirects` / `not_found_log` | rename **before** running the published migrations |
| `models.redirect` / `models.not_found` | the shipped models | point at your own subclass to add traits (an audit log, say) |
| `redirects.cache_store` / `cache_key` | `null` / `filament-redirects.map` | the map is cached forever and flushed on every save and delete |
| `redirects.cache_ttl` | `3600` | seconds in `Cache-Control: max-age` of a 301/308 (see Gotchas) |
| `redirects.gone_status` | `410` | status served for a Gone redirect |
| `redirects.preserve_query` | `false` | append the request's query string to an internal target |
| `trailing_slash.canonical` | `false` | also 301 every other slashed address to its slashless form |
| `not_found.enabled` | `true` | the 404 log |
| `not_found.retention_days` | `90` | horizon of `redirects:prune` |
| `not_found.ignore` / `ignore_user_agents` / `ignore_bots` | see the file | what is never logged |
| `schedule.enabled` / `schedule.times` | `false` | housekeeping |

## Gotchas

- **The middleware must be route middleware or appended in `bootstrap/app.php`** - see above. Nothing will tell you
  when it is not running; `hits` staying at 0 will.
- **One hop only.** Chains are collapsed when a redirect is *saved* (saving `B -> C` re-points the existing `A -> B` to
  `A -> C`). Do not add hop-following to the middleware: it would cost a query per hop on every 404, scanners included.
- **A redirect never wins over a real page.** It is a fallback for a 404: a row whose source is a live URL is inert.
  `/old/` follows the same rule when `resolvesUsing()` is set.
- **Browsers cache a 301 (and 308) forever**, so a later edit (a new target, "Gone") never reaches a visitor who
  already followed it. The response carries `Cache-Control: max-age=<cache_ttl>, must-revalidate` so changes arrive
  within an hour. A 301 that is already cached in a browser cannot be recalled; clear that cache once.
- **Livewire and `Cache-Control`.** Livewire pushes `DisableBackButtonCacheMiddleware` onto the kernel's *global* stack,
  and once any Livewire component rendered on a 404 page it overwrites the response's `Cache-Control` with `no-store`.
  The redirect response resets that flag, so the bounded `max-age` survives.
- **Gone.** Stored with code `404`, served with `redirects.gone_status` (default `410`; set `404` for plain not-found) on
  the site's own error page. Unlike an unmatched path it is not logged - it is a decision.
- **A scanned URL arrives collapsed.** A scanner that requests `https://site.com/x` as a *path* leaves `https:/site.com/x`
  in your log after nginx merges the slashes; the path field recognises that form too and strips your own domain.
- **Bots in the log.** `ignore_bots` is off on purpose. Turn it on only if you want humans' broken links alone.
- **Raw SQL bypasses the cache invalidation.** Call `Redirects::flushCache()` after it.

## Translations

English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish. Publish to
override:

```bash
php artisan vendor:publish --tag=filament-redirects-translations
```

## AI agents

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines (`resources/boost/guidelines/core.blade.php`)
covering the registry, the middleware trap, the path canon and the write path.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

The tests run on Orchestra Testbench with an in-memory SQLite database and a workbench panel (`admin`).

## Changelog

See the [CHANGELOG](https://github.com/asignua/filament-redirects/blob/main/CHANGELOG.md).

## License

MIT - see the [LICENSE](https://github.com/asignua/filament-redirects/blob/main/LICENSE.md).
