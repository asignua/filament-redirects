<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Support;

/**
 * The pure logic of redirect chains of one language over an in-memory map
 * `[old_path => to_path]`: resolving a target to its final one (compaction) and detecting
 * loops before a write. The middleware stays one-hop - that is exactly why chains are
 * collapsed when they are saved.
 */
final class RedirectChain
{
    /**
     * Walks the chain from `$toPath` to its final target. It stops at a missing key, at the
     * depth limit or at a repeat (a cycle in data that already exists) and returns the last
     * resolved target - a best-effort compaction.
     *
     * @param array<string, string> $map
     */
    public static function resolve(array $map, string $toPath, int $maxDepth = 10): string
    {
        $visited = [$toPath => true];

        for ($i = 0; $i < $maxDepth; $i++) {
            $next = $map[$toPath] ?? null;

            // An empty target is a Gone row ("nowhere"), never a hop to the home page.
            if ($next === null || $next === '' || isset($visited[$next])) {
                break;
            }

            $visited[$next] = true;
            $toPath = $next;
        }

        return $toPath;
    }

    /**
     * Would a redirect `$oldPath -> $toPath` close a loop: a direct self-loop, or a walk over
     * the existing redirects that comes back to `$oldPath`. Somebody else's cycle that does
     * not pass through `$oldPath` is not a loop of this redirect.
     *
     * @param array<string, string> $map
     */
    public static function isLoop(array $map, string $oldPath, string $toPath): bool
    {
        if ($oldPath === $toPath) {
            return true;
        }

        $visited = [];

        while (true) {
            if ($toPath === $oldPath) {
                return true;
            }

            if (isset($visited[$toPath])) {
                return false; // somebody else's cycle that does not contain $oldPath
            }

            $visited[$toPath] = true;
            $next = $map[$toPath] ?? null;

            if ($next === null) {
                return false;
            }

            $toPath = $next;
        }
    }
}
