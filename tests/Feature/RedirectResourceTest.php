<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Tests\Feature;

use Asignua\FilamentRedirects\Enums\RedirectCode;
use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Resources\Redirects\Pages\CreateRedirect;
use Asignua\FilamentRedirects\Resources\Redirects\Pages\EditRedirect;
use Asignua\FilamentRedirects\Resources\Redirects\Pages\ListRedirects;
use Asignua\FilamentRedirects\Tests\TestCase;
use Livewire\Livewire;

/**
 * The form. The key thing: the value REACHES the database - the model has `$guarded = ['*']`,
 * so without the repository a submit would succeed and store nothing.
 */
class RedirectResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());
    }

    public function test_create_stores_the_normalised_values(): void
    {
        Livewire::test(CreateRedirect::class)
            ->fillForm(['old_path' => '/old/page/', 'to_path' => 'https://site.test/new', 'code' => 302, 'active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = Redirect::query()->firstOrFail();

        $this->assertSame(['old/page', 'new', 302, 'en', true], [$row->old_path, $row->to_path, $row->code->value, $row->language, $row->active]);
    }

    public function test_the_home_page_can_be_a_target(): void
    {
        Livewire::test(CreateRedirect::class)
            ->fillForm(['old_path' => 'start', 'to_path' => '/', 'code' => 301])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('/', Redirect::query()->firstOrFail()->to_path);
    }

    public function test_gone_needs_no_target(): void
    {
        Livewire::test(CreateRedirect::class)
            ->fillForm(['old_path' => 'removed', 'code' => RedirectCode::Gone->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('', Redirect::query()->firstOrFail()->to_path);
    }

    public function test_a_target_is_required_unless_gone(): void
    {
        Livewire::test(CreateRedirect::class)
            ->fillForm(['old_path' => 'old', 'to_path' => '', 'code' => 301])
            ->call('create')
            ->assertHasFormErrors(['to_path']);
    }

    public function test_validation_of_the_old_path(): void
    {
        $this->twoLanguages();
        $this->redirect('taken', 'new', ['language' => 'uk']);

        $cases = [
            'spaces' => ['two words', 'uk', 'spaces'],
            'a foreign site' => ['https://other.site/x', 'uk', 'old_external'],
            'the home page' => ['/', 'uk', 'home'],
            'a prefix of another language on the unprefixed one' => ['en/page', 'uk', 'language_prefix'],
            'a duplicate' => ['/taken/', 'uk', 'taken'],
        ];

        foreach ($cases as $label => [$value, $language, $message]) {
            Livewire::test(CreateRedirect::class)
                ->fillForm(['old_path' => $value, 'language' => $language, 'to_path' => 'new', 'code' => 301])
                ->call('create')
                ->assertHasFormErrors(['old_path'])
                ->assertSee(__('filament-redirects::redirects.validation.'.$message));
        }

        $this->assertSame(1, Redirect::query()->count());
    }

    public function test_the_same_path_is_fine_in_another_language_and_its_own_prefix_is_not_an_error(): void
    {
        $this->twoLanguages();
        $this->redirect('taken', 'new', ['language' => 'uk']);

        Livewire::test(CreateRedirect::class)
            ->fillForm(['old_path' => '/en/taken', 'language' => 'en', 'to_path' => 'new', 'code' => 301])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Redirect::query()->count());
    }

    public function test_validation_of_the_target(): void
    {
        $cases = [
            'a loop' => ['old', 'old', 'loop'],
            'a loop written differently' => ['/old', 'https://site.test/old/', 'loop'],
            'spaces' => ['old', 'two words', 'spaces'],
            'a broken URL' => ['old', 'https://', 'invalid_url'],
        ];

        foreach ($cases as $label => [$old, $to, $message]) {
            Livewire::test(CreateRedirect::class)
                ->fillForm(['old_path' => $old, 'to_path' => $to, 'code' => 301])
                ->call('create')
                ->assertHasFormErrors(['to_path'])
                ->assertSee(__('filament-redirects::redirects.validation.'.$message));
        }
    }

    public function test_a_single_language_site_has_no_language_select(): void
    {
        Livewire::test(CreateRedirect::class)->assertFormFieldIsHidden('language');

        $this->twoLanguages();

        Livewire::test(CreateRedirect::class)->assertFormFieldIsVisible('language');
    }

    public function test_edit_goes_through_the_repository(): void
    {
        $redirect = $this->redirect('old', 'new');

        Livewire::test(EditRedirect::class, ['record' => $redirect->getKey()])
            ->assertFormSet(['old_path' => '/old', 'to_path' => '/new'])
            ->fillForm(['to_path' => 'https://other.site/x', 'active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $redirect->refresh();

        $this->assertSame('https://other.site/x', $redirect->to_path);
        $this->assertFalse($redirect->active);
    }

    public function test_editing_does_not_trip_the_duplicate_check_on_itself(): void
    {
        $redirect = $this->redirect('old', 'new');

        Livewire::test(EditRedirect::class, ['record' => $redirect->getKey()])
            ->fillForm(['code' => 302])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_list_shows_rows_and_bulk_toggles_them(): void
    {
        $first = $this->redirect('a', 'b');
        $second = $this->redirect('c', 'd');

        Livewire::test(ListRedirects::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->searchTable('c')
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first]);

        Livewire::test(ListRedirects::class)
            ->callTableBulkAction('deactivate', [$first, $second]);

        $this->assertSame(0, Redirect::query()->where('active', true)->count());
    }

    public function test_deactivating_through_the_list_drops_the_redirect_from_the_cache(): void
    {
        $redirect = $this->redirect('old', 'new');
        $this->get('/old')->assertRedirect('/new');

        Livewire::test(ListRedirects::class)->callTableBulkAction('deactivate', [$redirect]);

        $this->get('/old')->assertNotFound();
    }

    public function test_deleting_a_row_through_the_list(): void
    {
        $redirect = $this->redirect('old', 'new');

        Livewire::test(ListRedirects::class)->callTableAction('delete', $redirect);

        $this->assertSame(0, Redirect::query()->count());
    }

    public function test_the_list_filters_by_code(): void
    {
        $permanent = $this->redirect('a', 'b');
        $temporary = $this->redirect('c', 'd', ['code' => 302]);

        Livewire::test(ListRedirects::class)
            ->filterTable('code', 302)
            ->assertCanSeeTableRecords([$temporary])
            ->assertCanNotSeeTableRecords([$permanent]);
    }

    public function test_the_flush_action(): void
    {
        $this->redirect('old', 'new');
        $this->get('/old');
        $this->assertTrue(cache()->has('filament-redirects.map'));

        Livewire::test(ListRedirects::class)->callAction('flush_cache');

        $this->assertFalse(cache()->has('filament-redirects.map'));
    }
}
