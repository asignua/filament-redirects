## Filament Redirects (asignua/filament-redirects)

- A redirect manager plus a 404 log. Panel UI: `->plugin(RedirectsPlugin::make())` (resources "Redirects" and "404 log"). Everything else works WITHOUT a panel and is configured on the static registry `Asignua\FilamentRedirects\Redirects` in `AppServiceProvider`: `locales(default:, all:, unprefixed:)` or `useLocaleUrls(LocaleUrls)`, `ownUrlsUsing`, `resolvesUsing`, `entityUsing`, `suggestUsing`, `ignoreUsing`.
- Run `php artisan vendor:publish --tag=filament-redirects-migrations` and migrate (tables `redirects` and `not_found_log`, names configurable).
- The middleware is NOT registered by the package. Put `...Redirects::middleware()` on the catch-all route (`Route::middleware(['web', ...Redirects::middleware()])`) or call `Redirects::appendTo($middleware)` in `bootstrap/app.php`. Pushing it from a service provider silently does nothing in a real request (the HTTP kernel is built before providers boot) while "working" in tests.
- It acts only on a 404, GET and HEAD, in ONE hop. Chains are collapsed when a redirect is SAVED, never followed at request time; do not add hop-following.
- Stored paths: no host, no language prefix, no leading slash; home page = `/`; empty target = Gone (the 404 stays); a foreign domain stays a whole URL. Normalise with `Support\RedirectPath::normalize($raw, $language)`.
- Write redirects only through `Redirects::create($from, $to, $status = 301, $language = null)` or `RedirectRepository` (the models have `$guarded = ['*']`; a field not assigned in the repository is silently not saved). A loop raises a `ValidationException`.
- After a raw `DB::table('redirects')->update(...)` call `Redirects::flushCache()`; model saves and deletes flush it by themselves.
- 404 log noise: `filament-redirects.not_found.ignore` (wildcards), `ignore_user_agents`, `ignore_bots`. Commands: `redirects:flush`, `redirects:prune`, `redirects:recheck`. Schedule: `filament-redirects.schedule.enabled` (off by default).
- Authorization: `RedirectsPlugin::make()->authorize(fn (): bool => ...)` or define the `redirects.manage` gate.
