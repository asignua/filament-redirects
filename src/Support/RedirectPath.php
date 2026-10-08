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
     * @param bool              $target   a TARGET (`to_path`), not a source: it is served back as a
     *                                    URL, so an encoded `%3F` / `%23` / `%25` stays encoded
     *                                    (decoding it would turn it into a query string, a
     *                                    fragment or an invalid escape) and a query string or a
     *                                    fragment is left untouched. Sources are decoded in full -
     *                                    they are matched against a decoded request path
     */
    public static function normalize(?string $raw, string $language, ?array $ownHosts = null, bool $target = false): string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return '';
        }

        if (preg_match(self::ABSOLUTE, $raw, $matches) === 1) {
            if (!in_array(mb_strtolower($matches[1]), $ownHosts ?? self::ownHosts(), true)) {
                return $raw; // a foreign site: the target stays a whole URL
            }

            $rest = $matches[2];

            // A target of a PREFIXED row that was pasted as an address of the unprefixed language
            // (`https://site.test/about` on an `en` row) is explicit about its language, and the
            // canon below has no way to say "from the site root, no prefix": it would be served as
            // `/en/about`. Such an address stays whole and is served from the root as it is.
            if ($target && self::leavesLanguage($rest, $language)) {
                return $raw;
            }

            $raw = $rest;
        }

        $tail = '';

        if ($target && ($cut = strcspn($raw, '?#')) < strlen($raw)) {
            $tail = substr($raw, $cut);
            $raw = substr($raw, 0, $cut);
        }

        // One canon for every alphabet: the DECODED path (`привіт`, not `%D0%BF...`). The request
        // side decodes too ({@see decode()}), so a typed and a pasted address meet in the middle.
        $path = ltrim(self::decode($raw, keepReserved: $target), '/');

        if ($language !== Redirects::localeUrls()->unprefixed()
            && ($path === $language || str_starts_with($path, $language.'/'))) {
            $path = substr($path, strlen($language) + 1);
        }

        $path = trim($path, '/');

        return ($path === '' ? Redirects::ROOT : $path).$tail;
    }

    /**
     * An address of the site (the host is already cut off) that belongs to the UNPREFIXED
     * language while the row is of a prefixed one.
     */
    private static function leavesLanguage(string $path, string $language): bool
    {
        $urls = Redirects::localeUrls();
        $unprefixed = $urls->unprefixed();

        if ($unprefixed === null || $language === $unprefixed) {
            return false;
        }

        return $urls->parse(ltrim(self::decode(substr($path, 0, strcspn($path, '?#'))), '/'))[0] === $unprefixed;
    }

    /**
     * Is the value an absolute address (`scheme://host`, `//host`)? Own or foreign.
     */
    public static function isAbsolute(?string $value): bool
    {
        return self::hostOf((string) $value) !== null;
    }

    /**
     * The part after the host of an absolute address, as a path with a leading slash (with its
     * query string): `https://site.test/about?a=1` -> `/about?a=1`.
     */
    public static function pathOf(string $value): string
    {
        if (preg_match(self::ABSOLUTE, trim($value), $matches) !== 1) {
            return $value;
        }

        return '/'.ltrim($matches[2], '/');
    }

    /**
     * A request path (or a pasted one) in the canon of the columns: percent-decoded UTF-8.
     * `$request->path()` is the RAW, still encoded path; without decoding, a redirect typed as
     * `привіт` would never meet a request for `/%D0%BF%D1%80...`. A sequence that does not
     * decode to valid UTF-8 is left as it came (the database would refuse it anyway).
     */
    public static function decode(string $path, bool $keepReserved = false): string
    {
        if (!str_contains($path, '%')) {
            return $path;
        }

        // `%25`, `%3F` and `%23` are re-escaped before decoding, so they survive it as they were
        // (`%253F` decodes to `%3F`): a target keeps them as URL syntax, never as a live `?`.
        $decoded = rawurldecode($keepReserved ? (string) preg_replace('/%(25|3F|23)/i', '%25$1', $path) : $path);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $path;
    }

    /**
     * The inverse of {@see decode()} for the characters that mean something in a URL: a stored
     * (decoded) path such as `what?` (the request was `/what%3F`) is turned back into input that
     * {@see normalize()} reads as the same path, instead of as a path with a query string.
     * `$reserved = false` escapes only whitespace: a stored TARGET already keeps its `%3F`.
     */
    public static function escape(string $path, bool $reserved = true): string
    {
        if ($reserved) {
            $path = strtr($path, ['%' => '%25', '?' => '%3F', '#' => '%23']);
        }

        // Whitespace and control characters too: the forms refuse them raw, and the stored path
        // (the request was `/my%20page`) must come back from the form unchanged.
        return preg_replace_callback(
            '/[\s\x00-\x1F\x7F]/u',
            static fn (array $char): string => rawurlencode($char[0]),
            $path,
        ) ?? $path;
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
     * The value of a form field: the canon with a leading slash (an absolute URL is unchanged).
     * The inverse of {@see normalize()}: `normalize(display($x))` gives `$x` back, so a stored
     * `my page`, `what?` or `https:/site.test/x` can be saved again from the edit form. (A source
     * that starts with the row's own language segment, `en/foo` on an `en` row, cannot be
     * written in the canon - the edit page leaves an unchanged source alone, see
     * {@see unchanged()}.)
     *
     * @param list<string>|null $ownHosts
     * @param bool              $target   the value is a target (`to_path`): it keeps its encoded
     *                                    reserved characters, only whitespace is escaped
     */
    public static function display(?string $stored, ?array $ownHosts = null, bool $target = false): string
    {
        $stored = trim((string) $stored);

        // `scheme:/host` (one slash) is the collapsed form of a scanned URL, a plain path; only a
        // real address (`scheme://`, `//`) is shown as it is.
        $collapsed = preg_match('#^https?:/(?!/)#i', $stored) === 1;

        if ($stored === '' || (self::isAbsolute($stored) && ($target || !$collapsed))) {
            return $stored;
        }

        $value = '/'.ltrim(self::escape($stored, reserved: !$target), '/');

        // A SOURCE is never an address: the nginx-collapsed `https:/site.test/x` of the 404 log is
        // a plain path, so its `scheme:/` is escaped and {@see normalize()} does not read it as a
        // host (and does not strip an own one).
        return $target ? $value : (string) preg_replace('#^/(https?):/#i', '/$1%3A/', $value);
    }

    /**
     * Is the submitted value of a source field exactly what {@see display()} showed for the
     * stored one? Such a value is not re-normalised on save: some stored sources (a log path
     * that looks like an address or starts with its own language) have no input form that
     * normalize() would read back unchanged.
     */
    public static function unchanged(?string $submitted, ?string $stored): bool
    {
        return $stored !== null && $stored !== '' && trim((string) $submitted) === self::display($stored);
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
