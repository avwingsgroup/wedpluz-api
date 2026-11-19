<?php

namespace App\Services\Moderation;

use App\Models\Community;
use App\Models\Event;
use App\Models\Message;
use App\Models\StatusPost;
use App\Models\User;
use App\Models\VendorProfile;
use App\Models\VendorReview;
use App\Models\VendorWork;
use App\Support\Platform;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a reportable target (user, message, community, event, vendor, work,
 * status, review) to its model, its owner and a preview snapshot.
 */
class ModerationTargets
{
    public function find(string $type, string $id, bool $withRemoved = false): ?Model
    {
        $query = match ($type) {
            'user' => User::query(),
            'message' => Message::query(),
            'community' => Community::query(),
            'event' => Event::query(),
            'vendor' => VendorProfile::query(),
            'work' => VendorWork::query(),
            'status' => StatusPost::query(),
            'review' => VendorReview::query(),
            default => null,
        };
        if ($query === null) {
            return null;
        }
        if ($withRemoved && method_exists($query->getModel(), 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    public function owner(string $type, Model $target): ?User
    {
        $userId = match ($type) {
            'user' => $target->getKey(),
            'message' => $target->sender_user_id,
            'community' => $target->owner_user_id,
            'event' => $target->organizer_user_id,
            'vendor' => $target->user_id,
            'work' => VendorProfile::withTrashed()->whereKey($target->vendor_profile_id)->value('user_id'),
            'status' => $target->author_user_id,
            'review' => $target->user_id,
            default => null,
        };

        return $userId ? User::withTrashed()->find($userId) : null;
    }

    /** @return array{title:string, excerpt:?string, imageUrl:?string, link:?string} */
    public function preview(string $type, Model $target): array
    {
        return match ($type) {
            'user' => ['title' => $target->display_name, 'excerpt' => $target->bio, 'imageUrl' => $target->avatar_url, 'link' => Platform::webUrl('/users/'.$target->id)],
            'message' => ['title' => 'Message', 'excerpt' => mb_substr((string) $target->body, 0, 200), 'imageUrl' => null, 'link' => null],
            'community' => ['title' => $target->name, 'excerpt' => $target->description, 'imageUrl' => $target->avatar_url, 'link' => null],
            'event' => ['title' => $target->title, 'excerpt' => mb_substr(strip_tags((string) $target->description), 0, 200), 'imageUrl' => $target->cover_url, 'link' => Platform::webUrl('/events/'.$target->slug)],
            'vendor' => ['title' => $target->business_name, 'excerpt' => mb_substr((string) $target->description, 0, 200), 'imageUrl' => $target->avatar_url, 'link' => Platform::webUrl('/vendors/'.$target->slug)],
            'work' => ['title' => $target->title, 'excerpt' => mb_substr((string) $target->description, 0, 200), 'imageUrl' => null, 'link' => null],
            'status' => ['title' => 'Status', 'excerpt' => $target->caption, 'imageUrl' => $target->media_url, 'link' => null],
            'review' => ['title' => 'Review ('.$target->rating.'/5)', 'excerpt' => mb_substr((string) $target->comment, 0, 200), 'imageUrl' => null, 'link' => null],
            default => ['title' => $type, 'excerpt' => null, 'imageUrl' => null, 'link' => null],
        };
    }
}
