<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Tests\TestCase;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache;

class FallbackMiddlewareTest extends TestCase
{
    public function test_a_404_with_a_redirect_is_answered_with_it_and_counts_a_hit(): void
    {
        $redirect = $this->redirect('old', 'new');

        $this->get('/old')->assertRedirect('/new')->assertStatus(301);

        $redirect->refresh();
        $this->assertSame(1, $redirect->hits);
        $this->assertNotNull($redirect->last_hit_at);
        $this->assertSame(0, NotFoundEntry::query()->count(), 'a handled 404 is not logged');
    }

    public function test_head_is_served_too_but_not_logged(): void
    {
        $this->redirect('old', 'new');

        $this->call('HEAD', '/old')->assertStatus(301)->assertRedirect('/new');

        $this->call('HEAD', '/nowhere')->assertNotFound();
        $this->assertSame(0, NotFoundEntry::query()->count(), 'HEAD pairs with a GET and would double the counter');
    }

    public function test_the_other_codes_are_used_as_stored(): void
    {
        foreach ([302, 307, 308] as $code) {
            $this->redirect('old-'.$code, 'new', ['code' => $code]);

            $this->get('/old-'.$code)->assertStatus($code);
        }
    }

    public function test_a_live_page_is_never_touched(): void
    {
        $redirect = $this->redirect('live', 'new');

        $this->get('/live')->assertOk()->assertSee('page');

        $this->assertSame(0, $redirect->refresh()->hits);
    }

    public function test_an_inactive_redirect_does_not_fire_and_the_404_is_logged(): void
    {
        $this->redirect('old', 'new', ['active' => false]);

        $this->get('/old')->assertNotFound();

        $this->assertSame(1, NotFoundEntry::query()->where('path', 'old')->count());
    }

    public function test_a_gone_redirect_keeps_the_404_but_is_not_logged(): void
    {
        $redirect = $this->redirect('old', '', ['code' => 404]);

        $this->get('/old')->assertStatus(410);

        $this->assertSame(1, $redirect->refresh()->hits);
        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_gone_status_is_configurable(): void
    {
        config()->set('filament-redirects.redirects.gone_status', 404);
        $this->redirect('old', '', ['code' => 404]);

        $this->get('/old')->assertNotFound();
    }

    public function test_an_external_target_is_served_verbatim(): void
    {
        $this->redirect('partner', 'https://other.site/landing?a=1');

        $this->get('/partner')->assertRedirect('https://other.site/landing?a=1');
    }

    public function test_the_home_page_target(): void
    {
        $this->redirect('start', '/');

        $this->get('/start')->assertRedirect('/');
    }

    public function test_a_permanent_redirect_has_a_bounded_browser_cache(): void
    {
        config()->set('filament-redirects.redirects.cache_ttl', 600);
        $this->redirect('old', 'new');
        $this->redirect('temp', 'new', ['code' => 302]);

        $this->assertStringContainsString('max-age=600', (string) $this->get('/old')->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age=600', (string) $this->get('/temp')->headers->get('Cache-Control'));
    }

    public function test_livewires_back_button_flag_does_not_overwrite_the_cache_header(): void
    {
        config()->set('filament-redirects.redirects.cache_ttl', 600);
        $this->redirect('old', 'new');

        SupportDisablingBackButtonCache::$disableBackButtonCache = true;

        $this->get('/old')->assertStatus(301);

        $this->assertFalse(SupportDisablingBackButtonCache::$disableBackButtonCache);
    }

    public function test_the_query_string_is_dropped_unless_preserve_query_is_on(): void
    {
        $this->redirect('old', 'new');

        $this->get('/old?utm=1')->assertRedirect('/new');

        config()->set('filament-redirects.redirects.preserve_query', true);

        $this->get('/old?utm=1')->assertRedirect('/new?utm=1');
    }

    public function test_the_query_string_is_never_added_to_an_external_target(): void
    {
        config()->set('filament-redirects.redirects.preserve_query', true);
        $this->redirect('partner', 'https://other.site/x');

        $this->get('/partner?utm=1')->assertRedirect('https://other.site/x');
    }

    public function test_post_requests_are_left_alone(): void
    {
        $this->redirect('old', 'new');

        $this->post('/old')->assertStatus(405);
    }

    public function test_changes_in_the_table_reach_the_cache_at_once(): void
    {
        $redirect = $this->redirect('old', 'new');

        $this->get('/old')->assertRedirect('/new');

        $redirect->delete();

        $this->get('/old')->assertNotFound();

        $this->redirect('old', 'live');

        $this->get('/old')->assertRedirect('/live');
    }

    public function test_the_language_prefix_selects_the_row(): void
    {
        $this->twoLanguages();
        $this->redirect('old', 'new', ['language' => 'uk']);
        $this->redirect('old', 'live', ['language' => 'en']);

        $this->get('/old')->assertRedirect('/new');
        $this->get('/en/old')->assertRedirect('/en/live');
    }

    public function test_a_hit_on_a_redirect_does_not_flush_the_cache(): void
    {
        $this->redirect('old', 'new');
        $this->get('/old');

        $this->assertTrue(cache()->has('filament-redirects.map'));
    }

    public function test_the_row_survives_a_missing_table_of_the_log(): void
    {
        \Illuminate\Support\Facades\Schema::drop((new NotFoundEntry)->getTable());

        $this->get('/nowhere')->assertNotFound();
    }

    public function test_the_model_is_never_filled_by_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new Redirect)->fill(['old_path' => 'x']);
    }
}
