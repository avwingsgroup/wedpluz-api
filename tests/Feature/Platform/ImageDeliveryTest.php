<?php

use App\Integrations\Storage\CloudinaryClient;
use App\Jobs\ImportRemoteImageJob;
use App\Services\Media\ImageUrl;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function useCloudinary(bool $fetch = false): ImageUrl
{
    config([
        'services.cloudinary.cloud_name' => 'democloud',
        'services.cloudinary.api_key' => 'key',
        'services.cloudinary.api_secret' => 'secret',
        'services.cloudinary.folder' => 'public',
        'services.cloudinary.fetch_remote' => $fetch,
    ]);
    app()->forgetInstance(ImageUrl::class);

    return app(ImageUrl::class);
}

it('replaces stored transformations with the preset for the field', function () {
    $images = useCloudinary();
    $stored = 'https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto/v1712/public/avatar/upl_01J.jpg';

    expect($images->transform($stored, 'avatar'))
        ->toBe('https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto,c_fill,g_face,w_256,h_256/v1712/public/avatar/upl_01J.jpg')
        ->and($images->transform('https://res.cloudinary.com/democloud/image/upload/public/event_cover/x', 'cover'))
        ->toBe('https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto,c_limit,w_1600,h_1600/public/event_cover/x');
});

it('leaves videos, other clouds and local URLs alone, and fetches remote images only when enabled', function () {
    $images = useCloudinary();
    $video = 'https://res.cloudinary.com/democloud/video/upload/so_0,w_480,h_480,c_limit,f_jpg/public/status/v.jpg';
    $google = 'https://lh3.googleusercontent.com/a/abc=s96-c';

    expect($images->transform($video, 'thumbnail'))->toBe($video)
        ->and($images->transform('https://res.cloudinary.com/othercloud/image/upload/a.jpg', 'avatar'))->toBe('https://res.cloudinary.com/othercloud/image/upload/a.jpg')
        ->and($images->transform('https://wedpluz-api.test/storage/a.jpg', 'avatar'))->toBe('https://wedpluz-api.test/storage/a.jpg')
        ->and($images->transform($google, 'avatar'))->toBe($google);

    expect(useCloudinary(fetch: true)->transform($google, 'avatar'))
        ->toBe('https://res.cloudinary.com/democloud/image/fetch/f_auto,q_auto,c_fill,g_face,w_256,h_256/'.rawurlencode($google));
});

it('returns URLs untouched when Cloudinary is not configured', function () {
    app()->forgetInstance(ImageUrl::class);
    $url = 'https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto/a.jpg';

    expect(app(ImageUrl::class)->transform($url, 'avatar'))->toBe($url);
});

it('optimises image fields in API responses', function () {
    useCloudinary();
    $event = $this->liveEvent(attributes: ['cover_url' => 'https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto/public/event_cover/evt.jpg']);

    $this->getJson("/api/v1/events/{$event->slug}")->assertOk()
        ->assertJsonPath('data.coverUrl', 'https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto,c_limit,w_1600,h_1600/public/event_cover/evt.jpg');
});

it('serves the placeholder cover from Cloudinary for events without a cover', function () {
    useCloudinary();
    $event = $this->liveEvent(attributes: ['cover_url' => null]);

    expect($this->getJson("/api/v1/events/{$event->slug}")->json('data.coverUrl'))
        ->toStartWith('https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto,')
        ->toEndWith('/public/static/event-placeholder');
});

it('imports a remote avatar into Cloudinary after save', function () {
    useCloudinary();
    Bus::fake([ImportRemoteImageJob::class]);
    $user = $this->customer(['avatar_url' => 'https://lh3.googleusercontent.com/a/abc=s96-c']);

    Bus::assertDispatched(ImportRemoteImageJob::class, fn ($job) => $job->id === $user->id && $job->folder === 'avatar');

    Http::fake(['api.cloudinary.com/*' => Http::response(['public_id' => 'public/avatar/'.$user->id, 'resource_type' => 'image', 'secure_url' => 'https://res.cloudinary.com/democloud/image/upload/v1/public/avatar/'.$user->id.'.jpg', 'width' => 96, 'height' => 96, 'bytes' => 1000])]);
    (new ImportRemoteImageJob('users', $user->id, 'avatar_url', 'avatar', 'https://lh3.googleusercontent.com/a/abc=s96-c'))->handle(app(CloudinaryClient::class));

    expect($user->refresh()->avatar_url)->toBe('https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto/public/avatar/'.$user->id);
});
