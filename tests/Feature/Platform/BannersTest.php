<?php

use App\Models\Banner;
use Database\Seeders\ReferenceDataSeeder;

it('lists live banners for a placement, highest priority first', function () {
    $make = fn (array $a) => Banner::query()->create(array_merge(['title' => 'x', 'placement' => 'onboarding', 'audience' => 'all', 'is_active' => true, 'priority' => 1], $a));
    $make(['title' => 'Second', 'priority' => 5]);
    $make(['title' => 'First', 'priority' => 9]);
    $make(['title' => 'Switched off', 'is_active' => false, 'priority' => 50]);
    $make(['title' => 'Expired', 'ends_at' => now()->subDay(), 'priority' => 40]);
    $make(['title' => 'Not started', 'starts_at' => now()->addDay(), 'priority' => 30]);
    $make(['title' => 'Home hero', 'placement' => 'home_hero', 'priority' => 20]);

    $this->getJson('/api/v1/banners?placement=onboarding')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'First')
        ->assertJsonPath('data.1.title', 'Second');
    $this->getJson('/api/v1/banners?placement=nope')->assertStatus(422);
});

it('seeds the three onboarding slides once, without overwriting admin edits', function () {
    $this->seed(ReferenceDataSeeder::class);

    $slides = Banner::query()->where('placement', 'onboarding')->orderByDesc('priority')->get();
    expect($slides->pluck('title')->all())->toBe(['Let the Experience Find You', 'Event Planning Made Easy', 'Show Your Work'])
        ->and($slides->every(fn ($s) => filled($s->image_url)))->toBeTrue();

    $slides[0]->forceFill(['image_url' => 'https://images.example.com/custom.jpg'])->save();
    $this->seed(ReferenceDataSeeder::class);
    expect(Banner::query()->where('placement', 'onboarding')->count())->toBe(3)
        ->and($slides[0]->refresh()->image_url)->toBe('https://images.example.com/custom.jpg');
});

it('lets admins create onboarding banners', function () {
    $this->asAdmin(null, ['settings.view', 'settings.manage']);
    $this->postJson('/api/v1/admin/banners', ['title' => 'New slide', 'placement' => 'onboarding', 'imageUrl' => 'https://images.example.com/s.jpg'])
        ->assertSuccessful()->assertJsonPath('data.placement', 'onboarding');
});
