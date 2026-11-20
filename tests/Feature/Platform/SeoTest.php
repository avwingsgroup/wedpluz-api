<?php

use App\Models\Event;
use App\Models\VendorCategory;
use App\Models\VendorProfile;
use Illuminate\Support\Str;

function vendorProfile(array $attributes = []): VendorProfile
{
    $owner = test()->customer();

    return VendorProfile::query()->create(array_merge([
        'user_id' => $owner->id, 'business_name' => 'Bloom Decor', 'slug' => 'bloom-decor-'.strtolower(Str::random(4)),
        'category_id' => VendorCategory::query()->create(['name' => 'Decoration', 'slug' => 'decoration-'.uniqid()])->id,
        'location' => 'Lagos', 'description' => 'Floral design.', 'status' => 'active', 'verified_at' => now(),
    ], $attributes));
}

it('lists public live events and active vendors in the sitemaps', function () {
    $live = $this->liveEvent();
    $draft = $this->liveEvent(attributes: ['status' => 'draft']);
    $private = $this->liveEvent(attributes: ['visibility' => 'private']);
    $vendor = vendorProfile();
    $pending = vendorProfile(['status' => 'pending_verification']);

    $events = $this->get('/sitemaps/events.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    expect($events)->toContain('/events/'.$live->slug.'</loc>')
        ->not->toContain($draft->slug)->not->toContain($private->slug);

    $vendors = $this->get('/sitemaps/vendors.xml')->assertOk()->getContent();
    expect($vendors)->toContain('/vendors/'.$vendor->slug.'</loc>')->not->toContain($pending->slug);

    expect($this->get('/sitemaps/index.xml')->assertOk()->getContent())
        ->toContain('/sitemaps/events.xml</loc>')->toContain('/sitemaps/vendors.xml</loc>');
});

it('regenerates the sitemap when an event changes', function () {
    $event = $this->liveEvent();
    expect($this->get('/sitemaps/events.xml')->getContent())->toContain($event->slug);

    $event->forceFill(['status' => 'cancelled'])->save();
    expect($this->get('/sitemaps/events.xml')->getContent())->not->toContain($event->slug);
});

it('serves link-preview HTML with Open Graph tags and JSON-LD', function () {
    $event = $this->liveEvent(attributes: ['cover_url' => 'https://images.example.com/cover.jpg']);

    $html = $this->get('/share/events/'.$event->slug)->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->getContent();
    expect($html)->toContain('<meta property="og:title" content="'.e($event->title).'">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('<link rel="canonical" href="'.rtrim(config('wedpluz.web_origin'), '/').'/events/'.$event->slug.'">')
        ->toContain('"@type":"Event"');

    $vendor = vendorProfile();
    expect($this->get('/share/vendors/'.$vendor->slug)->assertOk()->getContent())->toContain('"@type":"LocalBusiness"');
});

it('answers 404 for non-public and 410 for deleted content', function () {
    $this->get('/share/events/nope')->assertNotFound();
    $this->get('/share/events/'.$this->liveEvent(attributes: ['status' => 'draft'])->slug)->assertNotFound();

    $deleted = $this->liveEvent();
    $deleted->delete();
    $this->get('/share/events/'.$deleted->slug)->assertStatus(410);
});

it('keeps old slugs working with a 301 after a published event is renamed', function () {
    $organizer = $this->customer();
    $event = $this->liveEvent($organizer, attributes: ['published_at' => now()]);
    $oldSlug = $event->slug;

    $this->asCustomer($organizer);
    $this->putJson("/api/v1/organizer/events/{$event->id}", ['title' => 'A Brand New Name'])->assertOk();
    $newSlug = $event->refresh()->slug;
    expect($newSlug)->not->toBe($oldSlug)->toStartWith('a-brand-new-name-');

    $this->getJson("/api/v1/events/{$oldSlug}")->assertStatus(301)->assertHeader('Location', url("/api/v1/events/{$newSlug}"));
    $this->get("/share/events/{$oldSlug}")->assertStatus(301)->assertHeader('Location', url("/share/events/{$newSlug}"));

    // Renaming again: the first slug still resolves straight to the current one.
    $this->putJson("/api/v1/organizer/events/{$event->id}", ['title' => 'Third Title'])->assertOk();
    $this->getJson("/api/v1/events/{$oldSlug}")->assertStatus(301)->assertHeader('Location', url('/api/v1/events/'.$event->refresh()->slug));
});

it('does not redirect old slugs of events that are no longer public', function () {
    $organizer = $this->customer();
    $event = $this->liveEvent($organizer, attributes: ['published_at' => now()]);
    $oldSlug = $event->slug;
    $this->asCustomer($organizer);
    $this->putJson("/api/v1/organizer/events/{$event->id}", ['title' => 'Renamed'])->assertOk();

    Event::query()->whereKey($event->id)->update(['visibility' => 'private']);
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/events/{$oldSlug}")->assertNotFound();
});
