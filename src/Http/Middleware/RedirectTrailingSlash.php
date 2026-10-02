<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Http\Middleware;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Asignua\FilamentRedirects\Support\RedirectResolver;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/old/` -> the target of the redirect for `/old`, in ONE hop.
 *
 * Without it a slashed address would first be sent to `/old` (by the site's own slash handling,
 * if it has any) and only then to the target: two 301s in a row, which an SEO audit flags as a
 * chain - and data cannot fix that, because a slashed address is never stored in the table.
 *
 * It only acts when the trimmed path IS an `old_path`. Two exceptions keep the plain slash
 * behaviour: a Gone row (the fallback then 404s `/old` as usual), and a LIVE page at `/old` with
 * a stale row left in the table - a redirect is a fallback for a 404, so `/old/` and `/old`
 * must not diverge ({@see Redirects::resolvesUsing()} tells us about the live page; without it
 * the table is trusted).
 *
 * `trailing_slash.canonical` additionally 301s every other slashed address to its slashless
 * form. The home page (`/`) is never touched: there the slash IS the address.
 *
 * Put it FIRST in the stack, before anything that works with the address.
 */
class RedirectTrailingSlash
{
    public function __construct(private RedirectResolver $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        // HEAD too: same reason as in the fallback middleware.
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || !str_ends_with($path, '/')) {
            return $next($request);
        }

        $trimmed = rtrim($path, '/');

        // Nothing left: that was the root, the one address where a slash belongs.
        if ($trimmed === '') {
            return $next($request);
        }

        // `//evil.example/` would become `//evil.example` - protocol-relative, i.e. off-site.
        // Leading slashes (and backslashes, which browsers read as slashes) collapse to one.
        $target = '/'.ltrim($trimmed, '/\\');

        if ($target === '/') {
            return $next($request);
        }

        $direct = $this->redirectFromTable($target, $request);

        if ($direct !== null) {
            return $direct;
        }

        if (!(bool) config('filament-redirects.trailing_slash.canonical', false)) {
            return $next($request);
        }

        $query = $request->getQueryString();

        // A relative Location: the request's Host header never shapes the target.
        return new RedirectResponse(
            RedirectPath::location($target.($query !== null && $query !== '' ? '?'.$query : '')),
            301,
        );
    }

    private function redirectFromTable(string $target, Request $request): ?Response
    {
        [$language, $path] = Redirects::localeUrls()->parse(RedirectPath::decode($target));

        $entry = $this->redirects->find($language, $path);

        if ($entry === null || !RedirectCode::from($entry['code'])->isRedirect()) {
            return null;
        }

        // A live page beats a stale row. Asked only when a row exists, so ordinary `/x/`
        // requests do not pay for the lookup.
        if (Redirects::resolves($language, $path)) {
            return null;
        }

        return $this->redirects->respond($entry, $language, $request->getQueryString());
    }
}
