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
- Security (pre-release review): an internal redirect and the canonical trailing-slash redirect send a **relative** `Location` (no longer absolutised from the request's `Host`), and leading `//` or `/\` collapse to one slash, so `//evil.example/` can no longer become an off-site 301. Bytes outside printable ASCII in a `Location` are percent-encoded.
- Paths are stored and matched **percent-decoded**: a redirect typed as `привіт` fires for `/%D0%BF...`, and the 404 log shows readable paths. Rows stored encoded still match.
- A redirect is saved in one transaction with the chain compaction (a failed save no longer leaves other rows re-pointed), compaction never turns `B -> A` into `B -> B`, and a duplicate source or a source with `?`/`#` is refused with a `ValidationException` instead of a SQL error.
- A loop through existing redirects is shown on the target field (404-log modal included); bulk "Activate" skips a row that would loop and reports it.
- The 404 log links a path to the site only when a base URL is known; the `statusUsing()` hook is called once per row.
- Only an ACTIVE redirect compacts chains: an inactive draft `b -> c` no longer re-points a live `a -> b`; switching it on does.
- A relative `Location` keeps the app's base path (an install under `/sub` redirects to `/sub/new`, not `/new`); `RedirectResolver::respond()` takes an optional `$basePath`.
- `?`/`#` are refused only when typed literally in a source: an encoded `%3F`/`%23` is a real path character, so a 404-log row such as `what?` can become a redirect.
