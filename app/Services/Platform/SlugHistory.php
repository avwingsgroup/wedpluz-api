<?php

namespace App\Services\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps shared links and search rankings alive across renames: when a public
 * event or vendor gets a new slug, the old one is recorded and resolves to the
 * record, so callers can 301 to the current slug.
 */
class SlugHistory
{
    /** Give $model a new slug; remember the old one when it has been public. */
    public function change(string $type, Model $model, string $newSlug, bool $rememberOld): void
    {
        $old = (string) $model->getAttribute('slug');
        if ($old === $newSlug) {
            return;
        }
        // The new slug may be one this record used before: it is live again, not a redirect.
        DB::table('slug_redirects')->where('type', $type)->where('old_slug', $newSlug)->delete();
        if ($rememberOld && $old !== '') {
            DB::table('slug_redirects')->insertOrIgnore(['type' => $type, 'old_slug' => $old, 'target_id' => $model->getKey(), 'created_at' => now()]);
        }
        $model->forceFill(['slug' => $newSlug])->save();
    }

    /** Record ID an old slug points at, or null. */
    public function resolve(string $type, string $slug): ?string
    {
        return DB::table('slug_redirects')->where('type', $type)->where('old_slug', $slug)->value('target_id');
    }

    /** Is the slug taken as an old slug by another record (so it must not be reused)? */
    public function reserved(string $type, string $slug, ?string $exceptTargetId = null): bool
    {
        return DB::table('slug_redirects')->where('type', $type)->where('old_slug', $slug)
            ->when($exceptTargetId, fn ($q) => $q->where('target_id', '!=', $exceptTargetId))->exists();
    }
}
