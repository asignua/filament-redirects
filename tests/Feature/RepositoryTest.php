<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Workbench\App\Models\Post;

class RepositoryTest extends TestCase
{
    public function test_create_normalises_the_paths(): void
    {
        $redirect = Redirects::create('https://site.test/Old/Page/', '/new/page/', 302);

        $this->assertSame('Old/Page', $redirect->old_path);
        $this->assertSame('new/page', $redirect->to_path);
        $this->assertSame(RedirectCode::Temporary, $redirect->code);
        $this->assertSame('en', $redirect->language);
        $this->assertTrue($redirect->active);
    }

    public function test_the_row_own_language_prefix_is_stripped_and_a_foreign_one_stays(): void
    {
        $this->twoLanguages();

        $own = Redirects::create('/en/old', '/en/new', language: 'en');
        $foreign = Redirects::create('/old2', '/en/new', language: 'uk');

        $this->assertSame(['old', 'new'], [$own->old_path, $own->to_path]);
        $this->assertSame(['old2', 'en/new'], [$foreign->old_path, $foreign->to_path]);
    }

    public function test_an_external_target_stays_a_whole_url(): void
    {
        $redirect = Redirects::create('partner', 'https://other.site/x');

        $this->assertSame('https://other.site/x', $redirect->to_path);
    }

    public function test_the_home_page_is_a_slash(): void
    {
        $this->assertSame('/', Redirects::create('start', '/')->to_path);
        $this->assertSame('/', Redirects::create('start2', 'https://site.test')->to_path);
    }

    public function test_gone_drops_the_target(): void
    {
        $redirect = Redirects::create('old', 'whatever', RedirectCode::Gone);

        $this->assertSame('', $redirect->to_path);
    }

    public function test_a_self_loop_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        Redirects::create('a', '/a/');
    }

    public function test_a_loop_through_the_chain_is_rejected(): void
    {
        Redirects::create('b', 'c');
        Redirects::create('c', 'a');

        $this->expectException(ValidationException::class);

        Redirects::create('a', 'b');
    }

    public function test_the_target_is_flattened_to_the_end_of_the_chain(): void
    {
        Redirects::create('b', 'c');

        $this->assertSame('c', Redirects::create('a', 'b')->to_path);
    }

    public function test_existing_redirects_into_a_new_source_are_re_pointed_to_its_target(): void
    {
        $first = Redirects::create('a', 'b');
        Redirects::create('b', 'c');

        $this->assertSame('c', $first->refresh()->to_path, 'a -> b -> c is stored as a -> c');
    }

    public function test_chains_are_per_language(): void
    {
        $this->twoLanguages();
        Redirects::create('b', 'c', language: 'en');

        $this->assertSame('b', Redirects::create('a', 'b', language: 'uk')->to_path);
    }

    public function test_an_external_target_is_not_flattened(): void
    {
        Redirects::create('b', 'c');

        $this->assertSame('https://other.site/b', Redirects::create('a', 'https://other.site/b')->to_path);
    }

    public function test_an_entity_can_be_given_or_found(): void
    {
        $post = Post::query()->create(['slug' => 'new']);
        $explicit = Redirects::create('old', 'new', entity: $post);

        $this->assertTrue($post->is($explicit->entity));

        Redirects::entityUsing(fn (string $language, string $path): ?Post => Post::query()->where('slug', $path)->first());
        $found = Redirects::create('old2', 'new');

        $this->assertTrue($post->is($found->entity));
    }

    public function test_update_keeps_the_language_and_renormalises(): void
    {
        $this->twoLanguages();
        $redirect = Redirects::create('old', 'new', language: 'en');

        app(RedirectRepository::class)->update($redirect, ['to_path' => '/en/other']);

        $this->assertSame('other', $redirect->refresh()->to_path);
        $this->assertSame('en', $redirect->language);
    }

    public function test_the_source_is_unique_per_language(): void
    {
        $this->twoLanguages();
        Redirects::create('old', 'new', language: 'uk');
        Redirects::create('old', 'new', language: 'en');

        $this->assertTrue(app(RedirectRepository::class)->oldPathTaken('uk', 'old', null));
        $this->assertFalse(app(RedirectRepository::class)->oldPathTaken('uk', 'other', null));

        try {
            Redirects::create('old', 'newer', language: 'uk');
            $this->fail('a duplicate source must be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.old_path', $exception->errors(), 'a readable error, not SQLSTATE 23000');
        }
    }

    public function test_the_table_name_is_configurable(): void
    {
        $this->assertSame('redirects', (new Redirect)->getTable());

        config()->set('filament-redirects.tables.redirects', 'my_redirects');

        $this->assertSame('my_redirects', (new Redirect)->getTable());
    }

    public function test_a_failed_save_takes_the_compaction_back(): void
    {
        $chain = Redirects::create('a', 'b');
        Redirect::saving(function (Redirect $redirect): void {
            if ($redirect->old_path === 'b') {
                throw new RuntimeException('the save failed');
            }
        });

        try {
            Redirects::create('b', 'c');
            $this->fail('the save should have failed');
        } catch (RuntimeException) {
        }

        $this->assertSame('b', $chain->refresh()->to_path, 'the other row is not re-pointed to a redirect that does not exist');
        $this->assertSame(1, Redirect::query()->count());
    }

    public function test_a_source_with_a_query_or_a_fragment_is_refused(): void
    {
        foreach (['old?id=5', 'page#x'] as $from) {
            try {
                Redirects::create($from, 'new');
                $this->fail($from.' must be refused');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data.old_path', $exception->errors());
            }
        }

        $this->assertSame(0, Redirect::query()->count());
    }

    public function test_compaction_never_makes_a_self_loop(): void
    {
        $back = Redirects::create('b', 'a', active: false);
        Redirects::create('a', 'b');

        $this->assertSame('a', $back->refresh()->to_path, 'b -> a is not re-pointed to b -> b');
    }

    public function test_would_loop_sees_a_loop_through_the_chain(): void
    {
        Redirects::create('b', 'old');

        $repository = app(RedirectRepository::class);

        $this->assertTrue($repository->wouldLoop('en', 'old', 'b'));
        $this->assertFalse($repository->wouldLoop('en', 'old', 'c'));
    }

    public function test_an_inactive_draft_does_not_re_point_an_active_redirect(): void
    {
        $live = Redirects::create('a', 'b');
        $draft = Redirects::create('b', 'c', active: false);

        $this->assertSame('b', $live->refresh()->to_path, 'an inactive b -> c leaves a -> b alone');
        $this->assertSame('c', $draft->to_path);

        app(RedirectRepository::class)->update($draft, ['active' => true]);

        $this->assertSame('c', $live->refresh()->to_path, 'switching the draft on compacts the chain');
    }

    public function test_bulk_deactivation_does_not_compact(): void
    {
        $live = Redirects::create('a', 'b');
        $other = Redirects::create('x', 'y');
        $draft = Redirects::create('b', 'c', active: false);

        app(RedirectRepository::class)->update($other, ['active' => false]);
        app(RedirectRepository::class)->update($draft, ['active' => false]);

        $this->assertSame('b', $live->refresh()->to_path);
    }

    public function test_an_encoded_query_or_fragment_character_is_a_valid_source(): void
    {
        $redirect = Redirects::create('/what%3F', 'new');
        $hash = Redirects::create('/c%23', 'new');

        $this->assertSame('what?', $redirect->old_path);
        $this->assertSame('c#', $hash->old_path);
    }

    public function test_a_temporary_redirect_does_not_re_point_permanent_ones(): void
    {
        $promo = Redirects::create('old-promo', 'shop');
        $legacy = Redirects::create('legacy', 'shop', RedirectCode::PermanentKeepMethod);

        Redirects::create('shop', 'sale-2026', RedirectCode::Temporary);
        Redirects::create('shop2', 'x', RedirectCode::TemporaryKeepMethod);

        $this->assertSame('shop', $promo->refresh()->to_path, 'a 302 shop -> sale-2026 leaves old-promo -> shop alone');
        $this->assertSame('shop', $legacy->refresh()->to_path);
    }

    public function test_a_permanent_redirect_is_not_flattened_through_a_temporary_one(): void
    {
        Redirects::create('b', 'c', RedirectCode::Temporary);
        Redirects::create('y', 'z', RedirectCode::TemporaryKeepMethod);

        $this->assertSame('b', Redirects::create('a', 'b')->to_path);
        $this->assertSame('y', Redirects::create('x', 'y')->to_path);
    }

    public function test_a_temporary_redirect_is_flattened_through_permanent_ones(): void
    {
        Redirects::create('b', 'c');

        $this->assertSame('c', Redirects::create('a', 'b', RedirectCode::Temporary)->to_path);
    }

    public function test_a_loop_through_a_temporary_redirect_is_still_rejected(): void
    {
        Redirects::create('b', 'a', RedirectCode::Temporary);

        $this->expectException(ValidationException::class);

        Redirects::create('a', 'b');
    }

    public function test_a_redirect_into_a_gone_source_keeps_its_target(): void
    {
        Redirects::create('b', '', RedirectCode::Gone);
        $into = Redirects::create('x', 'a');

        $redirect = Redirects::create('a', 'b');

        $this->assertSame('b', $redirect->to_path, 'a 301 into a Gone source is not flattened to the home page');
        $this->assertSame(RedirectCode::Permanent, $redirect->code);
        $this->assertSame('b', $into->refresh()->to_path);
        $this->assertFalse(app(RedirectRepository::class)->wouldLoop('en', 'c', 'b'));
    }
}
