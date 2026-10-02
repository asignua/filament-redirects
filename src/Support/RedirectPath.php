<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Support;

use Asignua\FilamentRedirects\Redirects;

/**
 * The canon of a redirect path - the single source of truth for writing, the form and any
 * clean-up migration.
 *
 * `old_path` / `to_path` hold what a request path looks like after the language prefix is
 * split off: a path from the site root, WITHOUT a host, WITHOUT the language prefix (it lives
 * in the `language` column) and WITHOUT a leading slash. The home page is {@see Redirects::ROOT}
 * (`/`); an empty `to_path` means Gone. One exception: a target on a FOREIGN domain is stored
 * as the whole URL and served verbatim.
 *
 * The leading slash exists only in the form ({@see display()} / {@see prefix()}): an editor
 * sees `https://site.test` + `/shop` and can point a redirect at the home page by typing `/`.
 * The database never holds it, because what is matched against `old_path` is the output of
 * `LocaleUrls::parse()`, which is always trimmed.
 */
final class RedirectPath
{
    /**
     * An absolute (or protocol-relative) address: `scheme://host`, `//host` and - on purpose -
     * the collapsed `scheme:/host`. The last one is not a typo in the regex but field data: a
     * scanner requests `https://site.com/x` as a PATH, nginx collapses the double slash, and
     * `https:/site.com/x` is what reaches the 404 log (and, from there, a redirect form).
     */
    private const string ABSOLUTE = '#^(?:https?:/{1,2}|//)([^/?\#]+)(.*)$#i';

    /**
     * The hosts that count as "ours" and are stripped from a path: every own URL of the
     * registry, each with its `www.` twin and with and without a port.
     *
     * @return list<string>
     */
    public static function ownHosts(): array
    {
        $hosts = [];

        foreach (Redirects::ownUrls() as $url) {
            $parts = parse_url($url);
            $host = isset($parts['host']) ? mb_strtolower($parts['host']) : null;

            if ($host === null || $host === '') {
                continue;
            }

            foreach ([$host, str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host] as $variant) {
                $hosts[] = $variant;

                if (isset($parts['port'])) {
                    $hosts[] = $variant.':'.$parts['port'];
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Does the value lead to a FOREIGN site (an absolute URL with a host that is not ours)?
     *
     * @param list<string>|null $ownHosts
     */
    public static function isExternal(?string $value, ?array $ownHosts = null): bool
    {
        $host = self::hostOf((string) $value);

        return $host !== null && !in_array($host, $ownHosts ?? self::ownHosts(), true);
    }

    /**
     * Brings an entered value to the canon of the column.
     *
     * @param string            $language the language of the ROW - only its own prefix is stripped
     *                                    (a foreign language segment stays: for an unprefixed
     *                                    language `en/shop` is a valid target)
     * @param list<string>|null $ownHosts replaces the registry (tests, migrations)
     */
    public static function normalize(?string $raw, string $language, ?array $ownHosts = null): string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return '';
        }

        if (preg_match(self::ABSOLUTE, $raw, $matches) === 1) {
            if (!in_array(mb_strtolower($matches[1]), $ownHosts ?? self::ownHosts(), true)) {
                return $raw; // a foreign site: the target stays a whole URL
            }

            $raw = $matches[2];
        }

        // One canon for every alphabet: the DECODED path (`привіт`, not `%D0%BF...`). The request
        // side decodes too ({@see decode()}), so a typed and a pasted address meet in the middle.
        $path = ltrim(self::decode($raw), '/');

        if ($language !== Redirects::localeUrls()->unprefixed()
            && ($path === $language || str_starts_with($path, $language.'/'))) {
            $path = substr($path, strlen($language) + 1);
        }

        $path = trim($path, '/');

        return $path === '' ? Redirects::ROOT : $path;
    }

    /**
     * A request path (or a pasted one) in the canon of the columns: percent-decoded UTF-8.
     * `$request->path()` is the RAW, still encoded path; without decoding, a redirect typed as
     * `привіт` would never meet a request for `/%D0%BF%D1%80...`. A sequence that does not
     * decode to valid UTF-8 is left as it came (the database would refuse it anyway).
     */
    public static function decode(string $path): string
    {
        if (!str_contains($path, '%')) {
            return $path;
        }

        $decoded = rawurldecode($path);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $path;
    }

    /**
     * A value that is safe to put into a `Location` header. Bytes outside printable ASCII (a
     * decoded Cyrillic path, a stray CR/LF) are percent-encoded. For an internal (relative)
     * target, any run of leading slashes and backslashes collapses into ONE slash: `//host` and
     * `/\host` are protocol-relative to a browser and would lead off-site.
     */
    public static function location(string $url, bool $internal = true): string
    {
        if ($internal && preg_match('#^[/\\\\]#', $url) === 1) {
            $url = '/'.ltrim($url, '/\\');
        }

        return (string) preg_replace_callback(
            '/[^\x21-\x7E]/',
            static fn (array $byte): string => rawurlencode($byte[0]),
            $url,
        );
    }

    /**
     * The value of a form field: the canon with a leading slash (an external URL is unchanged).
     *
     * @param list<string>|null $ownHosts
     */
    public static function display(?string $stored, ?array $ownHosts = null): string
    {
        $stored = trim((string) $stored);

        if ($stored === '' || self::isExternal($stored, $ownHosts)) {
            return $stored;
        }

        return '/'.ltrim($stored, '/');
    }

    /**
     * The affix of a form field: the base URL of the site plus the language segment, without a
     * trailing slash (the editor types it - otherwise the home page could not be entered).
     */
    public static function prefix(string $language): string
    {
        return Redirects::localeUrls()->prefix($language);
    }

    /**
     * The host of an absolute address in lower case; null if the value is not absolute.
     */
    private static function hostOf(string $value): ?string
    {
        return preg_match(self::ABSOLUTE, trim($value), $matches) === 1
            ? mb_strtolower($matches[1])
            : null;
    }
}
