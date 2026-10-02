<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Contracts\LocaleUrls;
use Asignua\FilamentRedirects\Http\Middleware\RedirectFallbackMiddleware;
use Asignua\FilamentRedirects\Http\Middleware\RedirectTrailingSlash;
use Asignua\FilamentRedirects\Locale\PrefixedLocaleUrls;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Asignua\FilamentRedirects\Tests\TestCase;
use Illuminate\Foundation\Configuration\Middleware;

class RegistryTest extends TestCase
{
    public function test_the_default_is_a_single_unprefixed_language(): void
    {
        $urls = Redirects::localeUrls();

        $this->assertSame('en', $urls->default());
        $this->assertSame(['en'], $urls->all());
        $this->assertSame('en', $urls->unprefixed());
        $this->assertSame(['en', 'en/about'], $urls->parse('en/about'));
        $this->assertSame('https://site.test/about', $urls->url('en', 'about', true));
    }

    public function test_locales_can_be_set_directly_or_by_a_closure(): void
    {
        $this->twoLanguages();
        $this->assertSame(['uk', 'en'], Redirects::localeUrls()->all());

        Redirects::useLocaleUrls(fn (): LocaleUrls => new PrefixedLocaleUrls('de', ['de', 'fr'], null));

        $this->assertSame(['de', 'fr'], Redirects::localeUrls()->all());
        $this->assertNull(Redirects::localeUrls()->unprefixed());
    }

    public function test_a_custom_locale_urls_implementation_is_used_by_the_middleware(): void
    {
        Redirects::useLocaleUrls(new class implements LocaleUrls
        {
            public function default(): string
            {
                return 'en';
            }

            public function all(): array
            {
                return ['en', 'xx'];
            }

            public function unprefixed(): ?string
            {
                return 'en';
            }

            public function parse(string $path): array
            {
                // Languages as a SUFFIX: /page.xx
                return str_ends_with($path, '.xx') ? ['xx', substr($path, 0, -3)] : ['en', trim($path, '/')];
            }

            public function url(string $language, string $path, bool $absolute = false): string
            {
                return '/'.trim($path, '/').($language === 'xx' ? '.xx' : '');
            }

            public function prefix(string $language): string
            {
                return 'https://site.test';
            }
        });

        $this->redirect('old', 'new', ['language' => 'xx']);

        $this->get('/old.xx')->assertRedirect('/new.xx');
    }

    public function test_own_urls_drive_what_counts_as_external(): void
    {
        $this->assertTrue(RedirectPath::isExternal('https://alias.test/x'));

        Redirects::ownUrlsUsing(fn (): array => ['https://site.test', 'https://alias.test/']);

        $this->assertFalse(RedirectPath::isExternal('https://alias.test/x'));
        $this->assertSame('x', RedirectPath::normalize('https://alias.test/x', 'en'));
        $this->assertSame('https://site.test', Redirects::baseUrl());
    }

    public function test_the_middleware_list(): void
    {
        $this->assertSame([RedirectTrailingSlash::class, RedirectFallbackMiddleware::class], Redirects::middleware());
    }

    public function test_it_can_be_appended_to_the_global_stack(): void
    {
        $middleware = new Middleware;

        Redirects::appendTo($middleware);

        $this->assertSame(Redirects::middleware(), array_slice($middleware->getGlobalMiddleware(), -2));
    }

    public function test_suggestions_and_resolvers_have_neutral_defaults(): void
    {
        $this->assertNull(Redirects::suggestionFor('en', 'x'));
        $this->assertFalse(Redirects::resolves('en', 'x'));
        $this->assertNull(Redirects::entityFor('en', 'x'));

        Redirects::suggestUsing(fn (string $language, string $path): ?string => 'shop/'.$path);

        $this->assertSame('shop/x', Redirects::suggestionFor('en', 'x'));
    }

    public function test_flush_resets_the_registry(): void
    {
        $this->twoLanguages();
        Redirects::resolvesUsing(fn (): bool => true);

        Redirects::flush();

        $this->assertSame(['en'], Redirects::localeUrls()->all());
        $this->assertFalse(Redirects::resolves('en', 'x'));
    }
}
