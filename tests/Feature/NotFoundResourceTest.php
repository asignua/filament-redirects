<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Models\NotFoundEntry;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Resources\NotFound\Pages\ListNotFound;
use Asignua\FilamentRedirects\Tests\TestCase;
use Livewire\Livewire;

class NotFoundResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());
    }

    public function test_the_log_is_listed_and_filterable(): void
    {
        $this->twoLanguages();
        $uk = $this->logEntry('one', 'uk');
        $en = $this->logEntry('two', 'en');

        Livewire::test(ListNotFound::class)
            ->assertCanSeeTableRecords([$uk, $en])
            ->filterTable('language', 'en')
            ->assertCanSeeTableRecords([$en])
            ->assertCanNotSeeTableRecords([$uk]);
    }

    public function test_the_bot_filter(): void
    {
        $this->get('/by-bot', ['User-Agent' => 'Googlebot/2.1']);
        $this->get('/by-human', ['User-Agent' => 'Mozilla/5.0 Chrome/120.0']);

        $bot = NotFoundEntry::query()->where('path', 'by-bot')->firstOrFail();
        $human = NotFoundEntry::query()->where('path', 'by-human')->firstOrFail();

        Livewire::test(ListNotFound::class)
            ->filterTable('is_bot', true)
            ->assertCanSeeTableRecords([$bot])
            ->assertCanNotSeeTableRecords([$human]);
    }

    public function test_one_click_create_redirect_writes_the_redirect_and_closes_the_row(): void
    {
        $entry = $this->logEntry('old-page', 'en', hits: 3);

        Livewire::test(ListNotFound::class)
            ->callTableAction('create_redirect', $entry, data: ['to_path' => '/new', 'code' => 301, 'active' => true])
            ->assertHasNoTableActionErrors();

        $redirect = Redirect::query()->firstOrFail();

        $this->assertSame(['old-page', 'new', 'en', 301], [$redirect->old_path, $redirect->to_path, $redirect->language, $redirect->code->value]);
        $this->assertSame(0, NotFoundEntry::query()->count());

        $this->get('/old-page')->assertRedirect('/new');
    }

    public function test_the_modal_is_prefilled_with_the_suggestion(): void
    {
        Redirects::suggestUsing(fn (string $language, string $path): ?string => 'shop/'.$path);
        $entry = $this->logEntry('item');

        Livewire::test(ListNotFound::class)
            ->mountTableAction('create_redirect', $entry)
            ->assertTableActionDataSet(['to_path' => '/shop/item', 'code' => 301, 'active' => true]);
    }

    public function test_the_row_language_is_used_for_the_new_redirect(): void
    {
        $this->twoLanguages();
        $entry = $this->logEntry('old', 'en');

        Livewire::test(ListNotFound::class)
            ->callTableAction('create_redirect', $entry, data: ['to_path' => '/en/new', 'code' => 302, 'active' => true]);

        $redirect = Redirect::query()->firstOrFail();

        $this->assertSame(['en', 'new', 302], [$redirect->language, $redirect->to_path, $redirect->code->value]);
    }

    public function test_a_loop_is_refused_in_the_modal(): void
    {
        $entry = $this->logEntry('old-page');

        Livewire::test(ListNotFound::class)
            ->callTableAction('create_redirect', $entry, data: ['to_path' => '/old-page', 'code' => 301, 'active' => true])
            ->assertHasTableActionErrors(['to_path']);

        $this->assertSame(0, Redirect::query()->count());
        $this->assertSame(1, NotFoundEntry::query()->count());
    }

    public function test_a_stale_row_whose_redirect_already_exists_is_just_closed(): void
    {
        $entry = $this->logEntry('old-page');
        $this->redirect('old-page', 'other');

        Livewire::test(ListNotFound::class)
            ->callTableAction('create_redirect', $entry, data: ['to_path' => '/new', 'code' => 301, 'active' => true]);

        $this->assertSame(1, Redirect::query()->count());
        $this->assertSame('other', Redirect::query()->firstOrFail()->to_path);
        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_bulk_action_sends_many_paths_to_one_target_and_reports_loops(): void
    {
        $a = $this->logEntry('a-old');
        $b = $this->logEntry('b-old');
        $loop = $this->logEntry('shop');

        Livewire::test(ListNotFound::class)
            ->callTableBulkAction('create_redirects', [$a, $b, $loop], data: ['to_path' => '/shop', 'code' => 301]);

        $this->assertSame(['a-old', 'b-old'], Redirect::query()->orderBy('old_path')->pluck('old_path')->all());
        $this->assertSame(['shop'], NotFoundEntry::query()->pluck('path')->all(), 'the looping row stays in the log');
    }

    public function test_delete_removes_a_row(): void
    {
        $entry = $this->logEntry('junk');

        Livewire::test(ListNotFound::class)->callTableAction('delete', $entry);

        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_header_recheck_and_prune_actions(): void
    {
        $moved = $this->logEntry('moved');
        $old = $this->logEntry('old');
        $this->redirect('moved', 'new');
        NotFoundEntry::query()->whereKey($old->id)->update(['last_seen_at' => now()->subDays(100)]);

        Livewire::test(ListNotFound::class)->callAction('recheck');

        $this->assertNull(NotFoundEntry::query()->find($moved->id));
        $this->assertNotNull(NotFoundEntry::query()->find($old->id));

        Livewire::test(ListNotFound::class)->callAction('prune');

        $this->assertSame(0, NotFoundEntry::query()->count());
    }

    public function test_the_status_column_appears_only_with_a_hook(): void
    {
        $entry = $this->logEntry('draft-page');

        Livewire::test(ListNotFound::class)->assertTableColumnHidden('status');

        Redirects::statusUsing(fn (string $path, ?string $language): ?array => $path === 'draft-page' ? ['label' => 'Draft', 'color' => 'warning'] : null);

        Livewire::test(ListNotFound::class)
            ->assertTableColumnVisible('status')
            ->assertTableColumnStateSet('status', 'Draft', $entry);
    }
}
