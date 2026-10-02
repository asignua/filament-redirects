<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Locale;

use Asignua\FilamentRedirects\Contracts\LocaleUrls;
use Asignua\FilamentRedirects\Redirects;

/**
 * The common URL scheme: one language lives at `/about`, every other at `/en/about`.
 * A site with a single language is the degenerate case (`all = [default]`, nothing prefixed).
 */
final class PrefixedLocaleUrls implements LocaleUrls
{
    /** @var list<string> */
    private array $all;

    /**
     * @param list<string> $all
     */
    public function __construct(
        private readonly string $default,
        array $all = [],
        private readonly ?string $unprefixed = null,
    ) {
        $this->all = array_values(array_unique([$default, ...$all]));
    }

    public function default(): string
    {
        return $this->default;
    }

    public function all(): array
    {
        return $this->all;
    }

    public function unprefixed(): ?string
    {
        return $this->unprefixed;
    }

    public function parse(string $path): array
    {
        $path = trim($path, '/');

        if ($path === '') {
            return [$this->unprefixed ?? $this->default, ''];
        }

        $segments = explode('/', $path);
        $first = $segments[0];

        if ($first !== $this->unprefixed && in_array($first, $this->all, true)) {
            return [$first, implode('/', array_slice($segments, 1))];
        }

        return [$this->unprefixed ?? $this->default, $path];
    }

    public function url(string $language, string $path, bool $absolute = false): string
    {
        $path = trim($path, '/');
        $segment = $language === $this->unprefixed ? '' : '/'.$language;
        $url = $segment.($path === '' ? '' : '/'.$path);

        // The home page of an unprefixed language is `/`; a prefixed one is `/en` (no trailing
        // slash - the same form the canonical-slash redirect produces, so no extra hop).
        if ($url === '') {
            $url = '/';
        }

        return $absolute ? Redirects::baseUrl().$url : $url;
    }

    public function prefix(string $language): string
    {
        return Redirects::baseUrl().($language === $this->unprefixed ? '' : '/'.$language);
    }
}
