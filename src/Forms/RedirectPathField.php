<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Forms;

use Asignua\FilamentRedirects\Models\Redirect;
use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\RedirectRepository;
use Asignua\FilamentRedirects\Support\RedirectPath;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * The path fields of a redirect. They show the base URL of the site plus the language segment
 * as a prefix and keep the VALUE with a leading slash, so a redirect to the home page is typed
 * as `/`. The prefix disappears as soon as an address on a FOREIGN domain is typed: such a
 * target is stored as a whole URL and leads off-site. The canon and its reasons are in
 * {@see RedirectPath}.
 *
 * The normalisation itself (stripping our own domain and language prefix) lives in
 * {@see RedirectRepository}, not here: there are several entrances to the form and exactly one
 * repository.
 */
class RedirectPathField
{
    /** The length of the `to_path` column. */
    private const int TO_PATH_MAX = 2048;

    public static function oldPath(?Closure $languageResolver = null): TextInput
    {
        $language = $languageResolver ?? self::languageFromForm();

        return self::base('old_path', $language)
            ->label(__('filament-redirects::redirects.fields.old_path'))
            ->required()
            ->rule(static function (Get $get, ?Model $record) use ($language): Closure {
                return static function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $language): void {
                    $locale = (string) $language($get);
                    $raw = trim((string) $value);

                    if ($raw === '') {
                        return; // emptiness is `required`'s business
                    }

                    // Shown by the edit form as stored and not touched: nothing to validate.
                    if ($record instanceof Redirect && RedirectPath::unchanged($raw, $record->old_path)) {
                        return;
                    }

                    if (preg_match('/\s/u', $raw) === 1) {
                        $fail(__('filament-redirects::redirects.validation.spaces'));

                        return;
                    }

                    // The middleware matches the path only: `old?id=5` or `page#x` would be saved
                    // and never fire.
                    if (preg_match('/[?#]/', $raw) === 1) {
                        $fail(__('filament-redirects::redirects.validation.query'));

                        return;
                    }

                    $path = RedirectPath::normalize($raw, $locale);

                    // The column is a varchar(191) of the NORMALISED path - not of what is typed (a
                    // leading slash or a pasted host do not count).
                    if (mb_strlen($path) > RedirectRepository::OLD_PATH_MAX) {
                        $fail(__('filament-redirects::redirects.validation.too_long', ['max' => RedirectRepository::OLD_PATH_MAX]));

                        return;
                    }

                    // The source is always an address of THIS site: a request for a foreign host
                    // never reaches the app, so the rule could never fire.
                    if (RedirectPath::isExternal($path)) {
                        $fail(__('filament-redirects::redirects.validation.old_external'));

                        return;
                    }

                    // The home page never 404s, so a redirect on it would be dead.
                    if ($path === Redirects::ROOT) {
                        $fail(__('filament-redirects::redirects.validation.home'));

                        return;
                    }

                    // `en/shop` on a row of the unprefixed language: an incoming `/en/shop` is
                    // parsed as ('en', 'shop'), so this row could never match. Pick that
                    // language instead of typing its prefix. (A row of a PREFIXED language has
                    // no such problem: its path is matched after the prefix is split off.)
                    $urls = Redirects::localeUrls();

                    if ($locale === $urls->unprefixed() && $urls->parse($path)[0] !== $locale) {
                        $fail(__('filament-redirects::redirects.validation.language_prefix'));

                        return;
                    }

                    // unique(old_path, language) is in the schema: without this check a duplicate
                    // surfaces as a raw SQLSTATE 23000 error.
                    if (app(RedirectRepository::class)->oldPathTaken($locale, $path, $record)) {
                        $fail(__('filament-redirects::redirects.validation.taken'));
                    }
                };
            });
    }

    /**
     * @param bool $oldPathCanonical the old path comes from the 404 log: it is already in the canon
     *                               and must not be normalised a second time
     */
    public static function toPath(?Closure $languageResolver = null, ?Closure $oldPathResolver = null, bool $oldPathCanonical = false): TextInput
    {
        $language = $languageResolver ?? self::languageFromForm();
        $oldPath = $oldPathResolver ?? static fn (Get $get): string => (string) ($get('old_path') ?? '');

        return self::base('to_path', $language)
            ->label(__('filament-redirects::redirects.fields.new_path'))
            ->helperText(__('filament-redirects::redirects.fields.new_path_help'))
            ->rule(static function (Get $get, ?Model $record) use ($language, $oldPath, $oldPathCanonical): Closure {
                return static function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $language, $oldPath, $oldPathCanonical): void {
                    $locale = (string) $language($get);
                    $raw = trim((string) $value);

                    if ($raw === '') {
                        return; // empty = Gone; `requiredUnless` handles the rest
                    }

                    if (preg_match('/\s/u', $raw) === 1) {
                        $fail(__('filament-redirects::redirects.validation.spaces'));

                        return;
                    }

                    // `ftp://x`, `mailto:a@b` or a bare `https://` would be glued into the path and
                    // never lead anywhere: only http(s) addresses with a host are targets.
                    // (`https:/host/x`, the form nginx leaves of a scanned URL, is accepted.)
                    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $raw) === 1
                        && preg_match('~^https?:/{1,2}[^/?#\s]+~i', $raw) !== 1) {
                        $fail(__('filament-redirects::redirects.validation.invalid_url'));

                        return;
                    }

                    $path = RedirectPath::normalize($raw, $locale, target: true);

                    if (mb_strlen($path) > self::TO_PATH_MAX) {
                        $fail(__('filament-redirects::redirects.validation.too_long', ['max' => self::TO_PATH_MAX]));

                        return;
                    }

                    if (RedirectPath::isAbsolute($path)) {
                        if (filter_var($path, FILTER_VALIDATE_URL) === false) {
                            $fail(__('filament-redirects::redirects.validation.invalid_url'));
                        }

                        return; // chains and loops concern internal targets only
                    }

                    $submitted = (string) $oldPath($get);

                    // An untouched source of the edited redirect stays as stored (the edit page
                    // does not re-normalise it either): `en/foo` must not be read as `foo`.
                    $source = match (true) {
                        $record instanceof Redirect && RedirectPath::unchanged($submitted, $record->old_path) => (string) $record->old_path,
                        $oldPathCanonical => $submitted,
                        default => RedirectPath::normalize($submitted, $locale),
                    };

                    if ($source === '') {
                        return; // no single source (the bulk modal): the save checks each row
                    }

                    // A direct self-loop, or one that closes over the existing redirects
                    // (`b -> old` exists, `old -> b` is typed). The repository refuses it too, but
                    // its error is keyed for the resource pages and would not reach a modal field.
                    // The record is the redirect being edited - or, in the 404 log, a log row.
                    $excluding = $record instanceof Redirect ? $record : null;

                    if ($path === $source || app(RedirectRepository::class)->wouldLoop($locale, $source, $path, $excluding)) {
                        $fail(__('filament-redirects::redirects.validation.loop'));
                    }
                };
            });
    }

    /**
     * What both fields have in common: the affix, the leading slash in the value, the column
     * maximum. `live(onBlur:)` is needed for the affix - once the value becomes a foreign URL
     * the prefix must go, or the field would lie about where the target is.
     */
    private static function base(string $name, Closure $language): TextInput
    {
        return TextInput::make($name)
            ->live(onBlur: true)
            ->formatStateUsing(static fn (?string $state): string => RedirectPath::display($state, target: $name === 'to_path'))
            ->prefix(static fn (Get $get): ?string => RedirectPath::isAbsolute((string) ($get($name) ?? ''))
                ? null
                : RedirectPath::prefix((string) $language($get)));
    }

    /**
     * The language from the (live) select of the same form. Outside the redirect form - in the
     * modal of the 404 log, say - the row decides, hence the resolver parameter.
     */
    private static function languageFromForm(): Closure
    {
        return static fn (Get $get): string => (string) ($get('language') ?: Redirects::localeUrls()->default());
    }
}
