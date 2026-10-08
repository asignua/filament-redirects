<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Support;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache;

/**
 * One lookup of a redirect, at two entrances:
 * {@see \Asignua\FilamentRedirects\Http\Middleware\RedirectFallbackMiddleware} (the address
 * answered 404) and {@see \Asignua\FilamentRedirects\Http\Middleware\RedirectTrailingSlash}
 * (`/old/` - so that it is not a 301 to `/old` followed by another 301 to the target: two hops
 * in a row are a chain for a search engine).
 *
 * There are no chains in the table itself - they are flattened on write
 * ({@see RedirectRepository}) - so this is exactly one lookup and never follows a hop.
 */
class RedirectResolver
{
    public function __construct(private RedirectCache $cache) {}

    /**
     * The active entry for (language, path without the language prefix), or null.
     *
     * @return array{id: int, to: string, code: int}|null
     */
    public function find(string $language, string $path): ?array
    {
        if ($path === '') {
            return null; // the home page never 404s, a redirect on it has no effect
        }

        return $this->cache->map()[$language.'|'.$path] ?? null;
    }

    /**
     * The response for a found entry; null for Gone (the caller then serves the site's error page
     * with `redirects.gone_status`, 410 by default). Counts the hit -
     * call it exactly once per request.
     *
     * @param array{id: int, to: string, code: int} $entry
     * @param string|null                           $basePath the path the app is served under
     *                                                        (`/sub`), null = the current
     *                                                        request's {@see Request::getBaseUrl()}
     */
    public function respond(array $entry, string $language, ?string $query = null, ?string $basePath = null): ?RedirectResponse
    {
        // A query-builder increment: it fires no model events, so the cache is not flushed.
        app(RedirectRepository::class)->incrementHits($entry['id']);

        $code = RedirectCode::from($entry['code']);

        if (!$code->isRedirect()) {
            return null;
        }

        // A target on a foreign domain is stored as a whole URL: running it through the URL
        // builder would glue it into the path as `/https://other.site/x` (see RedirectPath).
        $external = RedirectPath::isExternal($entry['to']);

        if ($external) {
            $target = $entry['to'];
        } elseif (RedirectPath::isAbsolute($entry['to'])) {
            // An address of the unprefixed language on a row of a prefixed one: served from the
            // site root as it is, no language segment is added.
            $target = self::basePath($basePath).RedirectPath::pathOf($entry['to']);
        } else {
            $target = self::basePath($basePath).$this->internalUrl($language, $entry['to']);
        }

        if (!$external && $query !== null && $query !== '' && (bool) config('filament-redirects.redirects.preserve_query', false)) {
            // The query goes BEFORE a fragment: after a `#` it would never reach the server.
            [$base, $fragment] = array_pad(explode('#', $target, 2), 2, null);
            $base = (string) $base;

            $target = $base.(str_contains($base, '?') ? '&' : '?').$query.($fragment === null ? '' : '#'.$fragment);
        }

        // NOT redirect()->to(): it would absolutise an internal target with the scheme and host
        // of the REQUEST, so a forged `Host` / `X-Forwarded-Host` would end up in a cacheable
        // permanent redirect. A relative `Location` (RFC 9110) keeps the visitor on the host they
        // asked for, whatever the headers say. location() also collapses `//host` and encodes
        // non-ASCII bytes.
        $redirect = new RedirectResponse(RedirectPath::location($target, internal: !$external), $code->value);

        // Browsers cache a permanent redirect without an expiry: bound it, so that a change in
        // the panel reaches visitors within `cache_ttl`. 302/307 are not cached anyway.
        if ($code->isPermanent()) {
            $ttl = max(0, (int) config('filament-redirects.redirects.cache_ttl', 3600));
            $redirect->headers->set('Cache-Control', "max-age={$ttl}, must-revalidate");
        }

        // Livewire pushes DisableBackButtonCacheMiddleware straight onto the kernel's GLOBAL
        // stack (SupportDisablingBackButtonCache::provide() calls $kernel->pushMiddleware()),
        // not into the web group or a route - so no ordering of a route's middleware can put us
        // "outside" it: a global middleware always wraps the whole routing from outside. Any
        // Livewire component on the page, even on a 404 (a header search box, say), sets the
        // public static flag $disableBackButtonCache, and that middleware (which also resets the
        // flag after use) then overwrites our Cache-Control with "no-store" / max-age=0, wiping
        // the deliberate max-age of the 301 above. A redirect response carries no Livewire state
        // that the flag is there to protect (no page with a form is rendered), so resetting it
        // here is not a workaround but the correct semantics. class_exists() keeps the package
        // from hard-depending on this internal (non-public API) Livewire class.
        if (class_exists(SupportDisablingBackButtonCache::class)) {
            SupportDisablingBackButtonCache::$disableBackButtonCache = false;
        }

        return $redirect;
    }

    /**
     * The URL of an internal target. A target whose first segment is ANOTHER prefixed language
     * (`de/aktion` on an `en` row) is served in that language, not glued under the row's own
     * prefix (`/en/de/aktion`).
     */
    private function internalUrl(string $language, string $to): string
    {
        $urls = Redirects::localeUrls();
        [$first, $rest] = array_pad(explode('/', $to, 2), 2, '');

        if ($first !== $language && $first !== (string) $urls->unprefixed() && in_array($first, $urls->all(), true)) {
            return $urls->url($first, $rest === '' ? Redirects::ROOT : $rest, false);
        }

        return $urls->url($language, $to, false);
    }

    /**
     * The prefix of a relative internal `Location`: the path the app is served under (`/sub` for
     * an install in a subdirectory, '' at the root). It comes from the request's script path
     * (SCRIPT_NAME / REQUEST_URI), never from the `Host` header, so it cannot be forged into an
     * off-site target; any leading slashes still collapse in {@see RedirectPath::location()}.
     */
    public static function basePath(?string $basePath = null): string
    {
        if ($basePath === null) {
            $basePath = app()->bound('request') ? app(Request::class)->getBaseUrl() : '';
        }

        return rtrim($basePath, '/');
    }
}
