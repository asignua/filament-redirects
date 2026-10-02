<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Support;

use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\NotFoundRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the 404 log. One race-safe upsert through the query builder - no Eloquent and no model
 * events (so it never touches the redirect cache). A failed write NEVER breaks the response:
 * the log is a side effect, not a feature of the page.
 */
class NotFoundLog
{
    public function record(Request $request, string $language, string $path): void
    {
        if (!(bool) config('filament-redirects.not_found.enabled', true)) {
            return;
        }

        // Paths longer than the column are skipped, NOT truncated: truncated ones would collide
        // on unique(path, language) and merge different URLs into one row.
        if (mb_strlen($path) > (int) config('filament-redirects.not_found.max_path_length', 191)) {
            return;
        }

        $agent = UserAgent::detect($request->userAgent());

        if ($this->ignored($request, $language, $path, $agent['bot'])) {
            return;
        }

        $referrer = trim((string) $request->headers->get('referer'));

        try {
            app(NotFoundRepository::class)->recordHit(
                $path,
                $language,
                $referrer === '' ? null : mb_substr($referrer, 0, 191),
                $agent['family'],
                $agent['bot'],
            );
        } catch (Throwable) {
            // Silent on purpose: the host may not have migrated the table yet; the 404 must go out.
        }
    }

    /**
     * Would this request stay out of the log (config patterns, bots, the registry closure)?
     */
    public function ignored(Request $request, string $language, string $path, bool $bot): bool
    {
        if (Str::is($this->patterns('ignore'), mb_strtolower($path))) {
            return true;
        }

        $userAgent = mb_strtolower((string) $request->userAgent());

        if ($userAgent !== '' && Str::is($this->patterns('ignore_user_agents'), $userAgent)) {
            return true;
        }

        if ($bot && (bool) config('filament-redirects.not_found.ignore_bots', false)) {
            return true;
        }

        return Redirects::shouldIgnore($request, $language, $path);
    }

    /**
     * @return list<string>
     */
    private function patterns(string $key): array
    {
        $patterns = [];

        foreach ((array) config('filament-redirects.not_found.'.$key, []) as $pattern) {
            $pattern = mb_strtolower(trim((string) $pattern));

            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }
}
