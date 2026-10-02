<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Tests\TestCase;
use Illuminate\Http\Request;

class NotFoundLogTest extends TestCase
{
    public function test_a_404_is_aggregated_by_path_with_a_counter(): void
    {
        $this->get('/missing')->assertNotFound();
        $this->get('/missing')->assertNotFound();
        $this->get('/other-missing')->assertNotFound();

        $row = NotFoundEntry::query()->where('path', 'missing')->firstOrFail();

        $this->assertSame(2, $row->hits);
        $this->assertSame('en', $row->language);
        $this->assertSame(2, NotFoundEntry::query()->count());
        $this->assertGreaterThanOrEqual($row->created_at, $row->last_seen_at);
    }

    public function test_the_language_is_part_of_the_key(): void
    {
        $this->twoLanguages();

        $this->get('/missing');
        $this->get('/en/missing');

        $this->assertSame(['en', 'uk'], NotFoundEntry::query()->where('path', 'missing')->orderBy('language')->pluck('language')->all());
    }

    public function test_a_referrer_and_a_user_agent_family_are_sampled(): void
    {
        $this->get('/missing', ['Referer' => 'https://google.com/search?q=x', 'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);

        $row = NotFoundEntry::query()->firstOrFail();

        $this->assertSame('https://google.com/search?q=x', $row->referrer);
        $this->assertSame('Googlebot', $row->user_agent);
        $this->assertTrue($row->is_bot);
    }

    public function test_a_later_request_without_headers_does_not_erase_the_sample(): void
    {
        $this->get('/missing', ['Referer' => 'https://a.test/page', 'User-Agent' => 'Mozilla/5.0 Firefox/121.0']);
        $this->get('/missing', ['User-Agent' => '']);

        $row = NotFoundEntry::query()->firstOrFail();

        $this->assertSame(2, $row->hits);
        $this->assertSame('https://a.test/page', $row->referrer);
        $this->assertSame('Firefox', $row->user_agent);
    }

    public function test_a_newer_referrer_replaces_the_sample(): void
    {
        $this->get('/missing', ['Referer' => 'https://a.test/1']);
        $this->get('/missing', ['Referer' => 'https://b.test/2']);

        $this->assertSame('https://b.test/2', NotFoundEntry::query()->firstOrFail()->referrer);
    }

    public function test_scanner_noise_is_ignored_by_pattern(): void
    {
        foreach (['wp-login.php', 'wp-admin/setup.php', 'blog/wp-includes/x', 'xmlrpc.php', '.env', '.git/config', 'shell.php', 'logo.png', 'app.js.map', 'phpmyadmin/index.html', 'WP-Login.PHP'] as $path) {
            $this->get('/'.$path)->assertNotFound();
        }

        $this->assertSame(0, NotFoundEntry::query()->count());

        $this->get('/real-typo')->assertNotFound();

        $this->assertSame(1, NotFoundEntry::query()->count());
    }

    public function test_patterns_are_configurable(): void
    {
        config()->set('filament-redirects.not_found.ignore', ['private/*']);

        $this->get('/private/x');
        $this->get('/wp-login.php');

        $this->assertSame(['wp-login.php'], NotFoundEntry::query()->pluck('path')->all());
    }

    public function test_user_agents_can_be_ignored(): void
    {
        config()->set('filament-redirects.not_found.ignore_user_agents', ['*semrushbot*']);

        $this->get('/missing', ['User-Agent' => 'Mozilla/5.0 (compatible; SemrushBot/7)']);

        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_bots_can_be_left_out_but_are_logged_by_default(): void
    {
        $this->get('/by-googlebot', ['User-Agent' => 'Googlebot/2.1']);

        $this->assertSame(1, NotFoundEntry::query()->count());

        config()->set('filament-redirects.not_found.ignore_bots', true);

        $this->get('/by-googlebot-again', ['User-Agent' => 'Googlebot/2.1']);
        $this->get('/by-human', ['User-Agent' => 'Mozilla/5.0 Chrome/120.0']);

        $this->assertSame(['by-googlebot', 'by-human'], NotFoundEntry::query()->orderBy('path')->pluck('path')->all());
    }

    public function test_a_closure_can_veto_a_request(): void
    {
        Redirects::ignoreUsing(fn (Request $request, string $language, string $path): bool => str_starts_with($path, 'ignored'));

        $this->get('/ignored-path');
        $this->get('/kept-path');

        $this->assertSame(['kept-path'], NotFoundEntry::query()->pluck('path')->all());
    }

    public function test_a_path_longer_than_the_column_is_skipped_not_truncated(): void
    {
        $this->get('/'.str_repeat('a', 192))->assertNotFound();

        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_log_can_be_switched_off(): void
    {
        config()->set('filament-redirects.not_found.enabled', false);

        $this->get('/missing')->assertNotFound();

        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_home_page_is_never_logged(): void
    {
        $this->get('/')->assertOk();
        $this->get('/en')->assertOk();

        $this->assertSame(0, NotFoundEntry::query()->count());
    }
}
