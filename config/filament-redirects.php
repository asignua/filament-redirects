<?php

declare(strict_types=1);

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Models\Redirect;

/*
 * Filament Redirects.
 *
 * Closures (languages, base URL, "does this path resolve", entity lookup) cannot live in a
 * config file that has to stay cacheable: set them on the `Redirects` registry in
 * AppServiceProvider. Panel options (authorization, navigation, which resources to show)
 * live on `RedirectsPlugin`.
 */
return [

    /*
     * Database tables. Rename before running the published migrations.
     */
    'tables' => [
        'redirects' => 'redirects',
        'not_found' => 'not_found_log',
    ],

    /*
     * Eloquent models. Point a key at your own subclass to add traits (an audit log, say).
     * Writes always go through the repositories, which resolve the class from here.
     */
    'models' => [
        'redirect' => Redirect::class,
        'not_found' => NotFoundEntry::class,
    ],

    'redirects' => [
        // The map of active redirects is cached forever and flushed whenever a redirect is
        // saved or deleted. null = the default cache store.
        'cache_store' => null,
        'cache_key' => 'filament-redirects.map',

        // A 301/308 is cached by browsers WITHOUT an expiry, so a later change in the panel
        // (a different target, "Gone") would never reach a visitor who already followed it.
        // These seconds are sent as `Cache-Control: max-age=…, must-revalidate`.
        // 0 = revalidate every time. 302/307 are left alone (they are not cached anyway).
        'cache_ttl' => 3600,

        // Append the request's query string to the target of an INTERNAL redirect
        // (/old?utm_source=x -> /new?utm_source=x). Off: the target is used exactly as stored.
        'preserve_query' => false,

        // The HTTP status answered for a "Gone" redirect (stored with code 404). 410 is the
        // correct "removed for good"; set 404 to keep the plain not-found status. The site's
        // own error page is kept either way.
        'gone_status' => 410,
    ],

    /*
     * `/old/` -> the target of `/old` in ONE hop, instead of `/old/` -> `/old` -> target
     * (an SEO audit calls two 301s in a row a chain, and a slashed address cannot be stored in
     * the table). Only active when `Redirects::middleware()` is used.
     */
    'trailing_slash' => [
        // true: a slashed address that has no redirect of its own is also redirected (301) to
        // the same address without the slash. Leave it off if your site serves both forms.
        'canonical' => false,
    ],

    /*
     * The 404 log: unmatched GET requests that ended in a 404 and have no redirect, one row
     * per (path, language) with a hit counter.
     */
    'not_found' => [
        'enabled' => true,

        // `php artisan redirects:prune` deletes rows not seen for this many days.
        'retention_days' => 90,

        // Longer paths are skipped, not truncated: truncated ones would collide on the unique
        // index and merge different URLs into one row.
        'max_path_length' => 191,

        // Paths that are never logged. `*` is a wildcard; matched against the path without the
        // language prefix and without a leading slash, case-insensitively.
        'ignore' => [
            'wp-*', '*/wp-*', 'wordpress/*', 'xmlrpc.php',
            '*.php', '*.asp', '*.aspx', '*.jsp', '*.cgi',
            '.env*', '.git/*', '.svn/*', '.well-known/*',
            'cgi-bin/*', 'phpmyadmin*', 'pma/*', 'admin.php',
            '*.css', '*.js', '*.map', '*.ico',
            '*.png', '*.jpg', '*.jpeg', '*.gif', '*.webp', '*.avif', '*.svg',
            '*.woff', '*.woff2', '*.ttf', '*.eot',
        ],

        // Requests whose User-Agent matches are not logged either (wildcards, case-insensitive),
        // e.g. ['*ahrefsbot*', '*semrushbot*'].
        'ignore_user_agents' => [],

        // true: do not log anything a bot fetched. Off by default: a Googlebot request for a
        // dead URL is exactly what you want to see.
        'ignore_bots' => false,
    ],

    /*
     * Scheduled housekeeping (needs the Laravel scheduler). Off by default.
     */
    'schedule' => [
        'enabled' => false,
        'times' => [
            'prune' => '03:10',
            'recheck' => '03:20',
        ],
    ],
];
