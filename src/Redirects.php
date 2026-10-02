<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects;

use Asignua\FilamentRedirects\Contracts\LocaleUrls;
use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Http\Middleware\RedirectFallbackMiddleware;
use Asignua\FilamentRedirects\Http\Middleware\RedirectTrailingSlash;
use Asignua\FilamentRedirects\Locale\PrefixedLocaleUrls;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Support\RedirectCache;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * The static registry: everything that is a closure rather than a config value, plus the small
 * public API. Configure it in `AppServiceProvider::register()` or `boot()`; the panel plugin
 * only draws the UI, and the middleware, the commands and the schedule work without any panel.
 *
 *     Redirects::locales(default: 'uk', all: ['uk', 'en'], unprefixed: 'uk');
 *     Redirects::resolvesUsing(fn (string $locale, string $path): bool => Page::query()->where('slug', $path)->exists());
 *
 * Every resolver has a default that suits a single-language site.
 */
final class Redirects
{
    /** The stored form of the home page (a redirect target; an empty target means "Gone"). */
    public const string ROOT = '/';

    private static ?LocaleUrls $localeUrls = null;

    private static ?Closure $localeUrlsResolver = null;

    private static ?Closure $ownUrls = null;

    private static ?Closure $resolves = null;

    private static ?Closure $entity = null;

    private static ?Closure $suggest = null;

    private static ?Closure $ignore = null;

    private static ?Closure $status = null;

    /**
     * The languages of the site. `unprefixed` is the one served without a `/{locale}/` URL
     * prefix (`null` = every language is prefixed). Default: the single `app.locale`, unprefixed.
     *
     * @param list<string> $all
     */
    public static function locales(string $default, array $all = [], ?string $unprefixed = null): void
    {
        self::useLocaleUrls(new PrefixedLocaleUrls($default, $all, $unprefixed));
    }

    /**
     * A complete replacement of the URL scheme. A closure is resolved at the moment of use, so
     * it may read languages from a config that can change after boot (a CMS).
     */
    public static function useLocaleUrls(LocaleUrls|Closure $localeUrls): void
    {
        if ($localeUrls instanceof Closure) {
            self::$localeUrlsResolver = $localeUrls;
            self::$localeUrls = null;

            return;
        }

        self::$localeUrls = $localeUrls;
        self::$localeUrlsResolver = null;
    }

    public static function localeUrls(): LocaleUrls
    {
        if (self::$localeUrlsResolver !== null) {
            /** @var LocaleUrls */
            return (self::$localeUrlsResolver)();
        }

        if (self::$localeUrls !== null) {
            return self::$localeUrls;
        }

        $locale = (string) config('app.locale', 'en');

        return new PrefixedLocaleUrls($locale, [$locale], $locale);
    }

    /**
     * The addresses the site is reachable at. A target on one of these hosts is a page of
     * THIS site (the host is stripped on save); any other host is an external target.
     * The first one is the base URL. Default: `app.url`.
     *
     * @param Closure(): (list<string>|string) $resolver
     */
    public static function ownUrlsUsing(Closure $resolver): void
    {
        self::$ownUrls = $resolver;
    }

    /**
     * @return list<string>
     */
    public static function ownUrls(): array
    {
        $urls = self::$ownUrls !== null ? (self::$ownUrls)() : config('app.url');

        return array_values(array_filter(
            array_map(static fn (mixed $url): string => rtrim(trim((string) $url), '/'), (array) $urls),
            static fn (string $url): bool => $url !== '',
        ));
    }

    /**
     * Scheme + host of the site, no trailing slash. A scheduler has no request, so `url()` is
     * not an option.
     */
    public static function baseUrl(): string
    {
        return self::ownUrls()[0] ?? '';
    }

    /**
     * "Does this path show a page to an anonymous visitor right now?" Used by the 404 recheck
     * (a row for a path that now resolves is closed) and by the trailing-slash middleware (a
     * live page beats a stale redirect). Without it the recheck only closes paths that now have
     * a redirect, and the trailing-slash middleware trusts the table.
     *
     * Return true only for what a visitor really gets: a draft is NOT a page.
     *
     * @param Closure(string, string): bool $resolver (language, path without the language prefix)
     */
    public static function resolvesUsing(Closure $resolver): void
    {
        self::$resolves = $resolver;
    }

    public static function resolves(string $language, string $path): bool
    {
        return self::$resolves !== null && (bool) (self::$resolves)($language, $path);
    }

    /**
     * Ties a redirect to the record its target leads to (the nullable `entity` morph columns).
     * Called by the repository when a redirect with an internal target is saved and has no
     * entity yet.
     *
     * @param Closure(string, string): ?Model $resolver (language, target path)
     */
    public static function entityUsing(Closure $resolver): void
    {
        self::$entity = $resolver;
    }

    public static function entityFor(string $language, string $path): ?Model
    {
        return self::$entity !== null ? (self::$entity)($language, $path) : null;
    }

    /**
     * Prefills the target of the "Create redirect" action in the 404 log (the editor still has
     * to confirm it). Return a stored path, `/` or an external URL; null = no suggestion.
     *
     * @param Closure(string, string): ?string $resolver (language, 404 path)
     */
    public static function suggestUsing(Closure $resolver): void
    {
        self::$suggest = $resolver;
    }

    public static function suggestionFor(string $language, string $path): ?string
    {
        return self::$suggest !== null ? (self::$suggest)($language, $path) : null;
    }

    /**
     * A badge for the rows of the 404 log, e.g. "Draft in this language" for a path where a page
     * exists but is not published. Return `['label' => string, 'color' => 'warning'|...]` or null
     * for a plain 404. The column appears only when this hook is set, and is called once per
     * visible row, so keep it cheap.
     *
     * @param Closure(string, ?string): (array{label: string, color?: string}|null) $resolver (path, language)
     */
    public static function statusUsing(Closure $resolver): void
    {
        self::$status = $resolver;
    }

    public static function hasStatusHook(): bool
    {
        return self::$status !== null;
    }

    /**
     * @return array{label: string, color?: string}|null
     */
    public static function statusFor(string $path, ?string $language): ?array
    {
        return self::$status !== null ? (self::$status)($path, $language) : null;
    }

    /**
     * One more reason NOT to log a 404, on top of the `not_found.ignore*` config.
     *
     * @param Closure(Request, string, string): bool $resolver (request, language, path)
     */
    public static function ignoreUsing(Closure $resolver): void
    {
        self::$ignore = $resolver;
    }

    public static function shouldIgnore(Request $request, string $language, string $path): bool
    {
        return self::$ignore !== null && (bool) (self::$ignore)($request, $language, $path);
    }

    /**
     * The middleware to put on the catch-all route of a site that serves everything through one
     * route (a CMS front):
     *
     *     Route::middleware(['web', ...Redirects::middleware()])->get('/{path?}', FrontController::class)->where('path', '.*');
     *
     * THE TRAP: it must be ROUTE middleware (or appended through `bootstrap/app.php`, see
     * {@see appendTo()}). A package cannot push a global middleware from its own boot(): the
     * HTTP kernel is built in public/index.php before providers boot, so
     * `afterResolving(Kernel)->pushMiddleware()` and `pushMiddlewareToGroup()` silently do
     * nothing in a real request - while "working" in tests, which resolve the kernel later.
     * That is why this plugin registers nothing by itself.
     *
     * @return list<class-string>
     */
    public static function middleware(): array
    {
        return [RedirectTrailingSlash::class, RedirectFallbackMiddleware::class];
    }

    /**
     * For an ordinary Laravel app: a GLOBAL middleware from `bootstrap/app.php`.
     *
     *     ->withMiddleware(fn (Middleware $middleware) => Redirects::appendTo($middleware))
     */
    public static function appendTo(Middleware $middleware): void
    {
        $middleware->append(self::middleware());
    }

    /**
     * Creates a redirect from code (a slug-change bridge, a seeder, a migration). Goes through
     * the repository, so the paths are normalised, a loop is rejected with a
     * `ValidationException` and chains are collapsed to one hop.
     *
     * @param string|null $language null = the default language
     * @param string      $to       a path, `/` for the home page, an external URL, or '' for Gone
     */
    public static function create(
        string $from,
        string $to,
        RedirectCode|int $status = RedirectCode::Permanent,
        ?string $language = null,
        ?Model $entity = null,
        bool $active = true,
    ): Redirect {
        return app(RedirectRepository::class)->create([
            'old_path' => $from,
            'to_path' => $to,
            'language' => $language ?? self::localeUrls()->default(),
            'code' => $status instanceof RedirectCode ? $status->value : $status,
            'active' => $active,
        ], $entity);
    }

    /**
     * Forget the cached map. The model events do this on every save and delete; call it after a
     * raw `DB::table(...)` update that bypasses them.
     */
    public static function flushCache(): void
    {
        app(RedirectCache::class)->flush();
    }

    /**
     * Resets the registry (tests).
     */
    public static function flush(): void
    {
        self::$localeUrls = null;
        self::$localeUrlsResolver = null;
        self::$ownUrls = null;
        self::$resolves = null;
        self::$entity = null;
        self::$suggest = null;
        self::$ignore = null;
        self::$status = null;
    }
}
