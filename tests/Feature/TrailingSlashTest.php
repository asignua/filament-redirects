<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

class TrailingSlashTest extends TestCase
{
    public function test_a_slashed_old_address_goes_to_the_target_in_one_hop(): void
    {
        $redirect = $this->redirect('old', 'new');

        $response = $this->rawRequest('/old/')->assertStatus(301);

        $this->assertSame('/new', $response->headers->get('Location'), 'one hop: straight to the target');
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

    public function test_a_leading_double_slash_never_becomes_a_protocol_relative_location(): void
    {
        config()->set('filament-redirects.trailing_slash.canonical', true);

        foreach (['//evil.example/', '///evil.example/', '/\\evil.example/', '/\\/evil.example/'] as $uri) {
            $location = (string) $this->requestUri($uri)->assertStatus(301)->headers->get('Location');

            $this->assertSame('/evil.example', $location, $uri);
        }
    }

    public function test_the_host_header_does_not_shape_the_location(): void
    {
        config()->set('filament-redirects.trailing_slash.canonical', true);
        $this->redirect('old', 'new');

        $this->assertSame('/new', $this->rawRequest('/old/', headers: ['Host' => 'attacker.example'])->headers->get('Location'));
        $this->assertSame('/nope', $this->rawRequest('/nope/', headers: ['Host' => 'attacker.example'])->headers->get('Location'));
    }

    public function test_a_percent_encoded_slashed_address_finds_its_row(): void
    {
        $this->redirect('привіт', 'new');

        $this->rawRequest('/'.rawurlencode('привіт').'/')->assertStatus(301)->assertRedirect('/new');
    }

    /**
     * `Request::create()` runs the URI through parse_url(), which reads `//evil.example/` as a
     * HOST. PHP-FPM hands the app the raw REQUEST_URI instead - this builds exactly that.
     */
    private function requestUri(string $uri): TestResponse
    {
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/index.php',
        ]);

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }

    public function test_a_subdirectory_install_keeps_its_base_path_in_the_location(): void
    {
        config()->set('filament-redirects.trailing_slash.canonical', true);
        $this->redirect('old', 'new');

        $this->assertSame('/sub/new', $this->subdirectoryRequest('/sub/old/')->headers->get('Location'));
        $this->assertSame('/sub/nope', $this->subdirectoryRequest('/sub/nope/')->headers->get('Location'));
    }

    public function test_a_subdirectory_install_keeps_its_base_path_on_a_404_fallback(): void
    {
        $this->redirect('old', 'new');

        $response = $this->subdirectoryRequest('/sub/old');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/sub/new', $response->headers->get('Location'));
    }

    private function subdirectoryRequest(string $uri): TestResponse
    {
        // An app served under `/sub` (front controller /sub/index.php): the base URL comes from
        // the script path, the path info is relative to it.
        $request = Request::create('http://localhost'.$uri, 'GET', server: [
            'SCRIPT_NAME' => '/sub/index.php',
            'SCRIPT_FILENAME' => '/var/www/sub/index.php',
            'PHP_SELF' => '/sub/index.php',
        ]);

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }
}
