<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Unit;

use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Support\RedirectPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedirectPathTest extends TestCase
{
    private const array OWN = ['site.test', 'www.site.test'];

    protected function setUp(): void
    {
        parent::setUp();

        Redirects::flush();
        Redirects::locales(default: 'uk', all: ['uk', 'en'], unprefixed: 'uk');
    }

    protected function tearDown(): void
    {
        Redirects::flush();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function normalised(): array
    {
        return [
            'plain path' => ['shop/item', 'uk', 'shop/item'],
            'leading slash' => ['/shop/item', 'uk', 'shop/item'],
            'trailing slash' => ['shop/item/', 'uk', 'shop/item'],
            'our domain' => ['https://site.test/shop/item', 'uk', 'shop/item'],
            'our www domain' => ['http://www.site.test/shop/item', 'uk', 'shop/item'],
            'the scheme collapsed by nginx' => ['https:/site.test/shop', 'uk', 'shop'],
            'the home page' => ['/', 'uk', '/'],
            'our home page' => ['https://site.test', 'uk', '/'],
            'a row own prefix is stripped' => ['/en/shop', 'en', 'shop'],
            'a prefixed home page' => ['/en', 'en', '/'],
            'a foreign prefix stays' => ['/en/shop', 'uk', 'en/shop'],
            'a longer word is not a prefix' => ['/english/shop', 'en', 'english/shop'],
            'a foreign domain stays whole' => ['https://other.site/x?y=1', 'uk', 'https://other.site/x?y=1'],
            'empty is Gone' => ['', 'uk', ''],
            'blank is Gone' => ['   ', 'uk', ''],
        ];
    }

    #[DataProvider('normalised')]
    public function test_normalize(string $raw, string $language, string $expected): void
    {
        $this->assertSame($expected, RedirectPath::normalize($raw, $language, self::OWN));
    }

    public function test_normalize_is_idempotent(): void
    {
        foreach (self::normalised() as [$raw, $language]) {
            $once = RedirectPath::normalize($raw, $language, self::OWN);

            $this->assertSame($once, RedirectPath::normalize($once, $language, self::OWN));
        }
    }

    public function test_external_detection(): void
    {
        $this->assertTrue(RedirectPath::isExternal('https://other.site/x', self::OWN));
        $this->assertTrue(RedirectPath::isExternal('//other.site/x', self::OWN));
        $this->assertFalse(RedirectPath::isExternal('https://site.test/x', self::OWN));
        $this->assertFalse(RedirectPath::isExternal('shop/item', self::OWN));
        $this->assertFalse(RedirectPath::isExternal('', self::OWN));
        $this->assertFalse(RedirectPath::isExternal(null, self::OWN));
    }

    public function test_display_adds_the_slash_but_not_to_an_external_url(): void
    {
        $this->assertSame('/shop', RedirectPath::display('shop', self::OWN));
        $this->assertSame('/', RedirectPath::display('/', self::OWN));
        $this->assertSame('', RedirectPath::display('', self::OWN));
        $this->assertSame('https://other.site/x', RedirectPath::display('https://other.site/x', self::OWN));
    }

    public function test_decode_gives_utf8_and_leaves_broken_sequences_alone(): void
    {
        $this->assertSame('привіт/світ', RedirectPath::decode('%D0%BF%D1%80%D0%B8%D0%B2%D1%96%D1%82/%D1%81%D0%B2%D1%96%D1%82'));
        $this->assertSame('a b', RedirectPath::decode('a%20b'));
        $this->assertSame('plain', RedirectPath::decode('plain'));
        $this->assertSame('bad%FF', RedirectPath::decode('bad%FF'), 'not valid UTF-8 once decoded');
    }

    public function test_location_collapses_leading_slashes_and_encodes_non_ascii(): void
    {
        $this->assertSame('/evil.example', RedirectPath::location('//evil.example'));
        $this->assertSame('/evil.example', RedirectPath::location('/\\evil.example'));
        $this->assertSame('/%D0%BF?a=1', RedirectPath::location('/п?a=1'));
        $this->assertSame('/a%0D%0Ab', RedirectPath::location("/a\r\nb"));
        $this->assertSame('/already%20encoded', RedirectPath::location('/already%20encoded'));
        $this->assertSame('https://other.site/%D0%BF', RedirectPath::location('https://other.site/п', internal: false));
    }
}
