<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Contracts;

/**
 * How the site maps URLs to languages. The default implementation
 * ({@see \Asignua\FilamentRedirects\Locale\PrefixedLocaleUrls}) covers the usual scheme - one
 * language without a URL prefix, the others under `/{locale}/` - and a single-language site;
 * implement this for anything else (a CMS that keeps its own link table, say) and register it
 * with `Redirects::useLocaleUrls()`.
 *
 * Redirect paths are stored the way a request path looks AFTER {@see parse()}: from the site
 * root, without a host, without the language prefix, without a leading slash; the language
 * lives in its own column.
 */
interface LocaleUrls
{
    /**
     * The default language: used for new rows and as the fallback of the form.
     */
    public function default(): string;

    /**
     * Every language of the site.
     *
     * @return list<string>
     */
    public function all(): array;

    /**
     * The language served without a URL prefix; null when every language is prefixed.
     */
    public function unprefixed(): ?string;

    /**
     * Splits a request path into `[language, path without the language prefix]`.
     * A first segment is a language only when it is a known, prefixed one.
     *
     * @return array{0: string, 1: string}
     */
    public function parse(string $path): array;

    /**
     * The address of a stored path in a language, e.g. `/en/shop`. `/` is the home page.
     * With `$absolute` the base URL of the site is put in front.
     */
    public function url(string $language, string $path, bool $absolute = false): string;

    /**
     * What stands in front of a path typed by an editor: the base URL of the site plus the
     * language segment, without a trailing slash (`https://site.test/en`).
     */
    public function prefix(string $language): string;
}
