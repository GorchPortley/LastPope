<?php

namespace App\Services\Forum;

/**
 * Makes sure the forum tags for the automatic discussions exist (config('forum.tags')). Shared by
 * `forum:setup` and `forum:seed-demo`.
 */
class ForumTagSetup
{
    /**
     * @param  bool  $createMissing  false = only report (`forum:setup --check`)
     * @return array{ok: bool, rows: array<int, array{0: string, 1: string, 2: string}>} rows are [check, status, details]
     */
    public function ensure(ForumClient $forum, bool $createMissing = true): array
    {
        $rows = [];
        $ok = true;

        try {
            $existing = $forum->tags();

            foreach (config('forum.tags') as $kind => $tag) {
                $slug = trim((string) $tag['slug']);

                if ($slug === '') {
                    $rows[] = ["Tag for {$kind}s", 'skipped', 'slug is empty: no automatic discussions for this kind'];

                    continue;
                }

                if (isset($existing[$slug])) {
                    if ($existing[$slug]['primary']) {
                        $rows[] = ["Tag for {$kind}s ({$slug})", 'ok', 'exists (id '.$existing[$slug]['id'].')'];
                    } else {
                        $rows[] = ["Tag for {$kind}s ({$slug})", 'FAILED', 'exists but is a secondary tag; in the forum administration, Tags, drag it into the primary list'];
                        $ok = false;
                    }
                } elseif (! $createMissing) {
                    $rows[] = ["Tag for {$kind}s ({$slug})", 'MISSING', 'run forum:setup without --check to create it'];
                    $ok = false;
                } else {
                    $id = $forum->createTag($slug, $tag['name'], $tag['description'], $tag['color']);
                    $rows[] = ["Tag for {$kind}s ({$slug})", 'created', 'id '.$id];
                }
            }
        } catch (ForumException $e) {
            $rows[] = ['Tags', 'FAILED', $e->getMessage()];
            $ok = false;
        }

        return ['ok' => $ok, 'rows' => $rows];
    }
}
