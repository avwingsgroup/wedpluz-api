<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use SoftDeletes;

    protected static string $idPrefix = 'evt';

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'gallery' => 'array',
            'tags' => 'array',
            'organizer_info' => 'array',
            'approval_required' => 'boolean',
            'allow_guest_invites' => 'boolean',
            'is_adult_only' => 'boolean',
            'is_featured' => 'boolean',
            'is_free' => 'boolean',
            'always_refundable' => 'boolean',
            'allow_ticket_transfer' => 'boolean',
            'community_enabled' => 'boolean',
            'guest_limit' => 'integer',
            'max_additional_guests' => 'integer',
            'min_price' => 'integer',
            'tickets_sold' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EventSchedule::class, 'event_id')->orderBy('starts_at')->orderBy('sort_order');
    }

    public function subEvents(): HasMany
    {
        return $this->hasMany(SubEvent::class, 'event_id')->orderBy('starts_at');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class, 'event_id')->orderBy('sort_order')->orderBy('created_at');
    }

    /** Planner menu linked through `assets.menuId`; it supplies Event.menu for food selection. */
    public function plannerMenu(): BelongsTo
    {
        return $this->belongsTo(PlannerMenu::class, 'menu_id');
    }

    public function hallLayout(): BelongsTo
    {
        return $this->belongsTo(HallLayout::class, 'hall_layout_id');
    }

    public function promotion(): HasOne
    {
        return $this->hasOne(EventPromotion::class, 'event_id');
    }

    public function community(): HasOne
    {
        return $this->hasOne(Community::class, 'event_id');
    }

    public function managers(): HasMany
    {
        return $this->hasMany(EventManager::class, 'event_id');
    }

    public function vendorAssignments(): HasMany
    {
        return $this->hasMany(EventVendorAssignment::class, 'event_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(EventRequest::class, 'event_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'event_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'event_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_id');
    }

    /** Events anyone can discover: live and public. */
    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query->where('events.status', EventStatus::Live->value)->where('events.visibility', 'public');
    }

    public function isLive(): bool
    {
        return $this->status === EventStatus::Live;
    }

    public function isPromoted(): bool
    {
        return (bool) $this->promotion?->enabled;
    }
}
