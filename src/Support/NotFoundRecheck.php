<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Support;

use Asignua\FilamentRedirects\Redirects;
use Asignua\FilamentRedirects\Repositories\NotFoundRepository;

/**
 * Re-checks the 404 log for "no longer a 404".
 *
 * Rows go stale on their own: an editor creates a page at that address, brings it back from a
 * draft, or a redirect appears. Such a row then hangs in the log as work that does not exist and
 * hides the real broken links.
 *
 * A path counts as closed when an ANONYMOUS visitor gets something there: an active redirect
 * (Gone included - that is a deliberate decision, not an open 404, which is exactly why the
 * middleware does not log it either) or a page, as far as `Redirects::resolvesUsing()` says so.
 * The closure must answer for an anonymous visitor: if it showed drafts to a logged-in panel
 * user, a draft would count as found and its row would vanish while visitors still see a 404 -
 * and the command is also started from the panel, i.e. under an authenticated admin.
 */
class NotFoundRecheck
{
    /**
     * @return array{checked: int, resolved: int, redirected: int, deleted: int}
     */
    public function run(bool $dryRun = false): array
    {
        $redirects = app(RedirectCache::class)->map();

        $checked = 0;
        $resolved = 0;
        $redirected = 0;
        $deleted = 0;

        $repository = app(NotFoundRepository::class);

        $repository->chunkOrderedById(200, function ($entries) use (
            $repository, $redirects, $dryRun, &$checked, &$resolved, &$redirected, &$deleted
        ): void {
            /** @var array<int, int> $stale */
            $stale = [];

            foreach ($entries as $entry) {
                $checked++;

                // A redirect is counted first: if the path both resolves and has a redirect, the
                // redirect is what carries the visitor away.
                if (isset($redirects[$entry->language.'|'.$entry->path])) {
                    $redirected++;
                } elseif (Redirects::resolves($entry->language, $entry->path)) {
                    $resolved++;
                } else {
                    continue;
                }

                $stale[] = $entry->id;
            }

            if ($stale === []) {
                return;
            }

            $deleted += $dryRun ? count($stale) : $repository->deleteByIds($stale);
        });

        return ['checked' => $checked, 'resolved' => $resolved, 'redirected' => $redirected, 'deleted' => $deleted];
    }
}
