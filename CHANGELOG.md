# Changelog

All notable changes to `asignua/filament-redirects` are documented here.

## v1.0.0 - unreleased

- Redirect manager: 301, 302, 307, 308 and "Gone" (the 404 stays a 404), an active flag, a hit counter and a last-hit time, an optional language per row, `unique(source, language)`.
- Fallback middleware that only acts on a 404 (GET and HEAD), with a cached map, a bounded `Cache-Control` on permanent redirects and an opt-in query-string pass-through.
- One hop, always: chains are collapsed when a redirect is saved (A -> B -> C is stored as A -> C), loops are rejected, and `/old/` reaches the target of `/old` in a single hop through the optional trailing-slash middleware.
- Path canon: a stored path has no host, no language prefix and no leading slash; the home page is `/`; a target on a foreign domain stays a whole URL. Our own domain is stripped from anything pasted.
- 404 log: one row per (path, language) with hits, first/last seen, a referrer sample and the User-Agent family; ignore patterns (`/wp-*`, `*.php`, assets...), User-Agent patterns, an optional "no bots" switch and a closure veto.
- `redirects:recheck` closes rows that now have a redirect (or a page, via `Redirects::resolvesUsing()`); `redirects:prune` removes rows by age; `redirects:flush` clears the cache. Optional schedule.
- Filament: "Redirects" and "404 log" resources, the one-click "Create redirect" row action (prefilled through `Redirects::suggestUsing()`), a bulk "Create redirects" (one target for many paths), recheck and prune header actions, bulk (de)activate.
- `LocaleUrls` contract and the `Redirects` registry for multilingual sites; a single-language site needs no setup.
- `Redirects::create($from, $to, $status)` for code that writes redirects (a slug-change listener, a seeder, a migration).
- `RedirectsPlugin`: `authorize()`, the `redirects.manage` gate, navigation group, sort and icon, each resource switchable.
- Translations: English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish.
- Laravel Boost guidelines.
