<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Unit;

use Asignua\FilamentRedirects\Locale\PrefixedLocaleUrls;
use PHPUnit\Framework\TestCase;

class PrefixedLocaleUrlsTest extends TestCase
{
    public function test_parse_splits_a_known_prefixed_language(): void
    {
        $urls = new PrefixedLocaleUrls('uk', ['uk', 'en', 'de'], 'uk');

        $this->assertSame(['uk', 'about'], $urls->parse('about'));
        $this->assertSame(['en', 'about/team'], $urls->parse('/en/about/team/'));
        $this->assertSame(['en', ''], $urls->parse('en'));
        $this->assertSame(['uk', ''], $urls->parse('/'));
        $this->assertSame(['uk', 'english/about'], $urls->parse('english/about'));
        $this->assertSame(['uk', 'fr/about'], $urls->parse('fr/about'));
    }

    public function test_the_unprefixed_language_is_never_a_prefix(): void
    {
        $urls = new PrefixedLocaleUrls('uk', ['uk', 'en'], 'uk');

        $this->assertSame(['uk', 'uk/about'], $urls->parse('uk/about'));
    }

    public function test_a_site_with_every_language_prefixed(): void
    {
        $urls = new PrefixedLocaleUrls('en', ['en', 'de'], null);

        $this->assertSame(['en', 'about'], $urls->parse('en/about'));
        $this->assertSame(['en', 'about'], $urls->parse('about'));
        $this->assertSame('/de/about', $urls->url('de', 'about'));
        $this->assertSame('/en', $urls->url('en', '/'));
    }

    public function test_url_builds_relative_addresses(): void
    {
        $urls = new PrefixedLocaleUrls('uk', ['uk', 'en'], 'uk');

        $this->assertSame('/about', $urls->url('uk', 'about'));
        $this->assertSame('/', $urls->url('uk', '/'));
        $this->assertSame('/en/about', $urls->url('en', 'about'));
        $this->assertSame('/en', $urls->url('en', '/'));
    }

    public function test_the_default_language_is_always_among_all(): void
    {
        $this->assertSame(['uk', 'en'], (new PrefixedLocaleUrls('uk', ['en']))->all());
    }
}
