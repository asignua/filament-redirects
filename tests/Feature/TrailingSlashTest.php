<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Tests\TestCase;

class TrailingSlashTest extends TestCase
{
    public function test_a_slashed_old_address_goes_to_the_target_in_one_hop(): void
    {
        $redirect = $this->redirect('old', 'new');

        $response = $this->rawRequest('/old/')->assertStatus(301);

        $this->assertSame('http://localhost/new', $response->headers->get('Location'), 'one hop: straight to the target');
        $this->assertSame(1, $redirect->refresh()->hits);
    }

    public function test_head_gets_the_same_answer(): void
    {
        $this->redirect('old', 'new');

        $this->rawRequest('/old/', 'HEAD')->assertStatus(301)->assertRedirect('/new');
    }

    public function test_a_live_page_beats_a_stale_row(): void
    {
        Redirects::resolvesUsing(fn (string $language, string $path): bool => $path === 'live');
        $redirect = $this->redirect('live', 'new');

        $this->rawRequest('/live/')->assertOk();

        $this->assertSame(0, $redirect->refresh()->hits);
    }

    public function test_a_gone_row_does_not_redirect_a_slashed_address(): void
    {
        $this->redirect('old', '', ['code' => 404]);

        $this->rawRequest('/old/')->assertStatus(410);
    }

    public function test_without_a_row_the_slash_is_left_alone_by_default(): void
    {
        $this->rawRequest('/live/')->assertOk();
    }

    public function test_canonical_mode_sends_a_slashed_address_to_the_slashless_one(): void
    {
        config()->set('filament-redirects.trailing_slash.canonical', true);

        $this->rawRequest('/live/?page=2')->assertStatus(301)->assertRedirect('/live?page=2');
    }

    public function test_with_a_redirect_the_target_wins_over_the_canonical_slash_redirect(): void
    {
        config()->set('filament-redirects.trailing_slash.canonical', true);
        $this->redirect('old', 'new');

        $this->rawRequest('/old/')->assertRedirect('/new');
        $this->rawRequest('/nope/')->assertRedirect('/nope');
    }

    public function test_the_root_is_never_touched(): void
    {
        config()->set('filament-redirects.trailing_slash.canonical', true);

        $this->get('/')->assertOk();
    }

    public function test_a_prefixed_language(): void
    {
        $this->twoLanguages();
        $this->redirect('old', 'new', ['language' => 'en']);

        $this->rawRequest('/en/old/')->assertRedirect('/en/new');
    }
}
