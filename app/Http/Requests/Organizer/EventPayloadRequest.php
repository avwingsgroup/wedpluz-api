<?php

namespace App\Http\Requests\Organizer;

use App\Enums\AttendanceMode;
use App\Enums\Currency;
use App\Http\Requests\ApiRequest;

/** Create or update an event (all fields optional; drafts may be incomplete). */
class EventPayloadRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'coverUploadId' => ['sometimes', 'nullable', 'string', 'max:40'],
            'coverUrl' => ['sometimes', 'nullable', 'url:https', 'max:1024'],
            'categoryId' => ['sometimes', 'nullable', 'string', 'exists:event_categories,id'],
            'visibility' => ['sometimes', 'in:public,private'],
            'approvalRequired' => ['sometimes', 'boolean'],
            'guestLimit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'allowGuestInvites' => ['sometimes', 'boolean'],
            'maxAdditionalGuests' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'isAdultOnly' => ['sometimes', 'boolean'],
            'isFree' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'gallery' => ['sometimes', 'array', 'max:30'],
            'gallery.*' => ['url:https', 'max:1024'],
            'schedules' => ['sometimes', 'array', 'max:30'],
            'schedules.*.id' => ['nullable', 'string', 'max:40'],
            'schedules.*.label' => ['nullable', 'string', 'max:120'],
            'schedules.*.startsAt' => ['required', 'date'],
            'schedules.*.endsAt' => ['nullable', 'date', 'after_or_equal:schedules.*.startsAt'],
            'schedules.*.attendanceMode' => ['required', AttendanceMode::rule()],
            'schedules.*.venue' => ['nullable', 'array'],
            'schedules.*.venue.name' => ['nullable', 'string', 'max:200'],
            'schedules.*.venue.address' => ['nullable', 'string', 'max:500'],
            'schedules.*.venue.city' => ['nullable', 'string', 'max:120'],
            'schedules.*.venue.state' => ['nullable', 'string', 'max:120'],
            'schedules.*.venue.countryCode' => ['nullable', 'string', 'size:2'],
            'schedules.*.venue.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'schedules.*.venue.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'schedules.*.online' => ['nullable', 'array'],
            'schedules.*.online.platform' => ['required_with:schedules.*.online', 'in:google_meet,zoom,microsoft_teams,youtube,instagram_live,other'],
            'schedules.*.online.link' => ['nullable', 'url', 'max:1024'],
            'schedules.*.hallLayoutId' => ['nullable', 'string', 'max:40'],
            'schedules.*.menuId' => ['nullable', 'string', 'max:40'],
            'subEvents' => ['sometimes', 'array', 'max:30'],
            'subEvents.*.id' => ['nullable', 'string', 'max:40'],
            'subEvents.*.title' => ['required', 'string', 'max:200'],
            'subEvents.*.description' => ['nullable', 'string', 'max:5000'],
            'subEvents.*.coverUrl' => ['nullable', 'url:https', 'max:1024'],
            'subEvents.*.scheduleId' => ['nullable', 'string', 'max:40'],
            'subEvents.*.startsAt' => ['required', 'date'],
            'subEvents.*.endsAt' => ['nullable', 'date'],
            'subEvents.*.requiresApproval' => ['sometimes', 'boolean'],
            'subEvents.*.isIncludedInBundle' => ['sometimes', 'boolean'],
            'ticketTypes' => ['sometimes', 'array', 'max:30'],
            'ticketTypes.*.id' => ['nullable', 'string', 'max:40'],
            'ticketTypes.*.name' => ['required', 'string', 'max:120'],
            'ticketTypes.*.description' => ['nullable', 'string', 'max:1000'],
            'ticketTypes.*.scheduleId' => ['nullable', 'string', 'max:40'],
            'ticketTypes.*.price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'ticketTypes.*.currency' => ['sometimes', Currency::rule()],
            'ticketTypes.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'ticketTypes.*.salesStartAt' => ['nullable', 'date'],
            'ticketTypes.*.salesEndAt' => ['nullable', 'date'],
            'ticketTypes.*.perOrderLimit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'ticketTypes.*.seatSelection' => ['sometimes', 'boolean'],
            'ticketTypes.*.menuSelection' => ['sometimes', 'boolean'],
            'ticketTypes.*.isEarlyBird' => ['sometimes', 'boolean'],
            'ticketTypes.*.isActive' => ['sometimes', 'boolean'],
            'organizerInfo' => ['sometimes', 'nullable', 'array'],
            'organizerInfo.displayName' => ['nullable', 'string', 'max:200'],
            'organizerInfo.bio' => ['nullable', 'string', 'max:2000'],
            'organizerInfo.contactEmail' => ['nullable', 'email', 'max:255'],
            'organizerInfo.contactPhone' => ['nullable', 'string', 'max:20'],
            'assets' => ['sometimes', 'array'],
            'assets.hallLayoutId' => ['nullable', 'string', 'max:40'],
            'assets.menuId' => ['nullable', 'string', 'max:40'],
        ];
    }
}
