<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Http\Middleware;

use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Support\NotFoundLog;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Asignua\FilamentRedirects\Support\RedirectResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An AFTER middleware: it acts ONLY when there is no real page (the response is a 404). It
 * looks for an active redirect by (the path without the language prefix, language) and answers
 * with its status (+ a hit). A Gone redirect leaves the 404 as it is.
 *
 * It must be ROUTE middleware (or appended in `bootstrap/app.php`) - see
 * {@see Redirects::middleware()} for the trap.
 */
class RedirectFallbackMiddleware
{
    public function __construct(private RedirectResolver $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // HEAD too: Laravel matches it with the same GET route, and some crawlers and link
        // checkers (httpstatus.io and the like) use exactly HEAD - otherwise they would see a
        // 404 where a browser gets the 301.
        if ($response->getStatusCode() !== 404 || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        // The path is matched DECODED: `/%D0%BF...` must find the row typed as `привіт`.
        [$language, $oldPath] = Redirects::localeUrls()->parse(RedirectPath::decode($request->path()));

        if ($oldPath === '') {
            return $response;
        }

        $entry = $this->redirects->find($language, $oldPath);

        if ($entry === null) {
            // An unclosed 404 goes to the log (a matched redirect, Gone included, is a
            // deliberate record). GET only: HEAD usually comes in a pair with a GET for the same
            // address and would just double the counter.
            if ($request->isMethod('GET')) {
                app(NotFoundLog::class)->record($request, $language, $oldPath);
            }

            return $response;
        }

        $redirect = $this->redirects->respond($entry, $language, $request->getQueryString());

        if ($redirect !== null) {
            return $redirect;
        }

        // Gone: keep the site's own error page, with the configured status (410 by default).
        $status = (int) config('filament-redirects.redirects.gone_status', 410);

        if ($status >= 400 && $status < 600) {
            $response->setStatusCode($status);
        }

        return $response;
    }
}
