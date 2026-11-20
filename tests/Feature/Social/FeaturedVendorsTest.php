<?php

use App\Models\VendorCategory;
use App\Models\VendorProfile;

it('returns headline, coverUrl and featured vendors first for the homepage cards', function () {
    $category = VendorCategory::query()->create(['name' => 'Rentals', 'slug' => 'rentals-'.uniqid()]);
    $make = fn (string $slug, bool $featured, float $rating, ?string $headline) => VendorProfile::query()->create([
        'user_id' => $this->customer()->id, 'business_name' => ucfirst($slug), 'slug' => $slug, 'headline' => $headline,
        'category_id' => $category->id, 'location' => 'Port Harcourt', 'description' => 'x', 'status' => 'active',
        'verified_at' => now(), 'is_featured' => $featured, 'rating_avg' => $rating, 'cover_url' => 'https://images.example.com/'.$slug.'.jpg',
    ]);
    $make('plain', false, 5.0, null);
    $make('bea-you-wed', true, 4.9, 'Bridal wear rentals');

    $this->getJson('/api/v1/vendors/featured')->assertOk()
        ->assertJsonPath('data.0.slug', 'bea-you-wed')
        ->assertJsonPath('data.0.headline', 'Bridal wear rentals')
        ->assertJsonPath('data.0.coverUrl', 'https://images.example.com/bea-you-wed.jpg')
        ->assertJsonPath('data.1.headline', null);
});

it('lets a vendor set and clear their headline', function () {
    $owner = $this->asCustomer();
    $vendor = VendorProfile::query()->create([
        'user_id' => $owner->id, 'business_name' => 'Shot it moi', 'slug' => 'shot-it-moi',
        'category_id' => VendorCategory::query()->create(['name' => 'Photography', 'slug' => 'photo-'.uniqid()])->id,
        'location' => 'Port Harcourt', 'description' => 'x', 'status' => 'active',
    ]);
    $this->putJson('/api/v1/me/vendor-profile', ['headline' => 'Photographer'])->assertOk()->assertJsonPath('data.headline', 'Photographer');
    expect($vendor->refresh()->headline)->toBe('Photographer');
    $this->putJson('/api/v1/me/vendor-profile', ['headline' => null])->assertOk();
    expect($vendor->refresh()->headline)->toBeNull();
});
