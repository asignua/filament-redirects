<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Resources\NotFound\NotFoundResource;
use Asignua\FilamentRedirects\Resources\NotFound\Pages\ListNotFound;
use Asignua\FilamentRedirects\Resources\Redirects\Pages\EditRedirect;
use Asignua\FilamentRedirects\Support\NotFoundRecheck;
use Asignua\FilamentRedirects\Support\RedirectCache;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Asignua\FilamentRedirects\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Workbench\App\Models\Post;

/**
 * Regression tests for the code-review findings.
 */
class ReviewFixesTest extends TestCase
{
    private function threeLanguages(): void
    {
        Redirects::locales(default: 'uk', all: ['uk', 'en', 'de'], unprefixed: 'uk');
    }

    public function test_display_is_the_inverse_of_normalize(): void
    {
        foreach (['my page', 'what?', 'a#b', '100%', 'привіт', 'tab	x'] as $stored) {
            $this->assertSame($stored, RedirectPath::normalize(RedirectPath::display($stored), 'en'), $stored);
        }

        foreach (['my page', 'what%3F', 'a%23b', '100%25', 'q?x=1&y=2#top'] as $stored) {
            $this->assertSame($stored, RedirectPath::normalize(RedirectPath::display($stored, target: true), 'en', target: true), $stored);
        }
    }

    public function test_a_row_with_whitespace_or_a_question_mark_can_be_saved_again_from_the_edit_form(): void
    {
        $this->actingAs($this->admin());

        foreach (['my page', 'what?'] as $path) {
            $entry = $this->logEntry($path, 'en');
            $redirect = app(RedirectRepository::class)->createFromLog($entry, ['to_path' => '/my target', 'code' => 301]);

            Livewire::test(EditRedirect::class, ['record' => $redirect->getKey()])
                ->fillForm(['code' => 302])
                ->call('save')
                ->assertHasNoFormErrors();

            $redirect->refresh();

            $this->assertSame($path, $redirect->old_path);
            $this->assertSame(302, $redirect->code->value);
        }
    }

    public function test_log_paths_that_look_like_addresses_survive_the_edit_form(): void
    {
        $this->actingAs($this->admin());
        $this->twoLanguages();

        foreach (['https:/site.test/x' => '/shop', 'https:/evil.com/x' => '/shop', 'en/foo' => '/foo'] as $path => $target) {
            $redirect = app(RedirectRepository::class)->createFromLog($this->logEntry($path, 'en'), ['to_path' => $target, 'code' => 301]);

            Livewire::test(EditRedirect::class, ['record' => $redirect->getKey()])
                ->fillForm(['code' => 302])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame($path, $redirect->refresh()->old_path, $path);
            $this->assertSame(302, $redirect->code->value);
        }
    }

    public function test_the_cache_is_flushed_after_the_outer_transaction_commits(): void
    {
        $cache = app(RedirectCache::class);

        DB::transaction(function () use ($cache): void {
            $this->redirect('old', 'new');

            // A concurrent request rebuilt the map from the committed (old) rows in the window.
            Cache::forever($cache->key(), []);
        });

        $this->assertArrayHasKey('en|old', $cache->map());
    }

    public function test_a_row_of_a_prefixed_language_can_target_another_language(): void
    {
        $this->threeLanguages();

        $this->redirect('promo', '/de/aktion', ['language' => 'en']);
        $this->redirect('home-de', 'de', ['language' => 'en']);
        $this->redirect('about-old', 'https://site.test/about', ['language' => 'en']);

        $this->assertSame('https://site.test/about', Redirect::query()->where('old_path', 'about-old')->value('to_path'));

        $this->get('/en/promo')->assertRedirect('/de/aktion');
        $this->get('/en/home-de')->assertRedirect('/de');
        $this->get('/en/about-old')->assertRedirect('/about');
    }

    public function test_the_entity_binding_follows_the_target(): void
    {
        Post::query()->create(['slug' => 'post-a']);
        Redirects::entityUsing(fn (string $language, string $path): ?Post => Post::query()->where('slug', $path)->first());

        $redirect = $this->redirect('old', 'post-a');
        $this->assertNotNull($redirect->entity_id);

        $other = $this->redirect('old2', 'post-a');
        $repository = app(RedirectRepository::class);

        $repository->update($redirect, ['to_path' => 'nowhere']);
        $this->assertNull($redirect->refresh()->entity_id, 'a target without a record drops the stale binding');

        $repository->update($other, ['to_path' => 'https://other.site/x']);
        $this->assertNull($other->refresh()->entity_id);

        $explicit = $repository->create(['old_path' => 'x', 'to_path' => 'whatever'], Post::query()->firstOrFail());
        $this->assertNotNull($explicit->entity_id, 'an entity passed by the caller is kept');
    }

    public function test_a_log_path_is_taken_as_already_canonical(): void
    {
        $repository = app(RedirectRepository::class);

        $scanned = $repository->createFromLog($this->logEntry('https:/site.test/x', 'en'), ['to_path' => '/shop']);
        $this->assertSame('https:/site.test/x', $scanned->old_path);

        $this->twoLanguages();

        $doubled = $repository->createFromLog($this->logEntry('en/foo', 'en'), ['to_path' => '/shop']);
        $this->assertSame('en/foo', $doubled->old_path);
        $this->assertSame('en/foo', $doubled->fresh()->old_path);
    }

    public function test_the_log_action_does_not_delete_a_row_whose_canonical_path_is_free(): void
    {
        $this->actingAs($this->admin());
        $entry = $this->logEntry('https:/site.test/x', 'en');
        $this->redirect('x', 'elsewhere');

        Livewire::test(ListNotFound::class)
            ->callTableAction('create_redirect', $entry, data: ['to_path' => '/shop', 'code' => 301, 'active' => true]);

        $this->assertSame('https:/site.test/x', Redirect::query()->where('to_path', 'shop')->value('old_path'));
    }

    public function test_an_encoded_reserved_character_in_a_target_stays_encoded(): void
    {
        $this->assertSame('search/what%3F', $this->redirect('a', '/search/what%3F')->to_path);
        $this->assertSame('a%23b', $this->redirect('b', '/a%23b')->to_path);
        $this->assertSame('100%25', $this->redirect('c', '/100%25')->to_path);
        $this->assertSame('q?x=%26y', $this->redirect('d', '/q?x=%26y')->to_path);
        $this->assertSame('привіт', $this->redirect('e', '/%D0%BF%D1%80%D0%B8%D0%B2%D1%96%D1%82')->to_path);

        $this->get('/a')->assertRedirect('/search/what%3F');
    }

    public function test_the_query_goes_before_the_fragment_of_the_target(): void
    {
        config()->set('filament-redirects.redirects.preserve_query', true);
        $this->redirect('old', 'new#pricing');
        $this->redirect('old2', 'new?a=1#pricing');

        $this->get('/old?utm_source=x')->assertRedirect('/new?utm_source=x#pricing');
        $this->get('/old2?utm_source=x')->assertRedirect('/new?a=1&utm_source=x#pricing');
    }

    public function test_gone_from_the_log_needs_no_target(): void
    {
        $this->actingAs($this->admin());
        $entry = $this->logEntry('dead', 'en');

        Livewire::test(ListNotFound::class)
            ->callTableAction('create_redirect', $entry, data: ['code' => 404, 'active' => true])
            ->assertHasNoTableActionErrors();

        $row = Redirect::query()->firstOrFail();
        $this->assertSame(['dead', ''], [$row->old_path, $row->to_path]);
        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_bulk_action_refuses_rows_of_several_languages(): void
    {
        $this->actingAs($this->admin());
        $this->twoLanguages();
        $uk = $this->logEntry('a', 'uk');
        $en = $this->logEntry('b', 'en');

        Livewire::test(ListNotFound::class)
            ->callTableBulkAction('create_redirects', [$uk, $en], data: ['to_path' => '/shop', 'code' => 301]);

        $this->assertSame(0, Redirect::query()->count());
        $this->assertSame(2, NotFoundEntry::query()->count());
    }

    public function test_the_404_log_is_not_globally_searchable(): void
    {
        $this->assertFalse(NotFoundResource::canGloballySearch());
    }

    public function test_the_recheck_can_be_limited_to_the_latest_rows(): void
    {
        $this->redirect('a', 'x');
        $this->redirect('b', 'x');
        $old = $this->logEntry('a', 'en');
        $this->logEntry('b', 'en');
        NotFoundEntry::query()->whereKey($old->getKey())->update(['last_seen_at' => now()->subDays(5)]);

        $result = app(NotFoundRecheck::class)->run(limit: 1);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, NotFoundEntry::query()->count());
    }

    public function test_a_too_long_path_is_a_validation_error_and_the_edit_form_accepts_the_longest_one(): void
    {
        $this->actingAs($this->admin());
        $longest = str_repeat('a', RedirectRepository::OLD_PATH_MAX);

        try {
            $this->redirect($longest.'b', 'x');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.old_path', $exception->errors());
        }

        $redirect = $this->redirect('/'.$longest, 'x');

        Livewire::test(EditRedirect::class, ['record' => $redirect->getKey()])
            ->fillForm(['code' => 302])
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
