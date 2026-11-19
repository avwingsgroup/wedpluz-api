<?php

use App\Integrations\Sms\FailoverSmsSender;
use App\Integrations\Sms\TermiiSmsSender;
use App\Integrations\Sms\TwilioSmsSender;
use App\Integrations\Support\IntegrationException;
use App\Models\IntegrationLog;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;

function smsSender(): FailoverSmsSender
{
    return new FailoverSmsSender(
        new TermiiSmsSender(['api_key' => 'tk', 'sender_id' => 'Wedpluz', 'base_url' => 'https://api.ng.termii.com', 'channel' => 'dnd']),
        new TwilioSmsSender(['account_sid' => 'AC1', 'auth_token' => 'tw', 'from' => '+15550001111']),
    );
}

it('fails over from Termii to Twilio on a provider outage and logs both calls redacted', function () {
    Http::fake([
        'api.ng.termii.com/*' => Http::response(['message' => 'Service unavailable'], 503),
        'api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201),
    ]);

    $result = smsSender()->send('+2348012345678', 'Your code is 1234');

    expect($result->provider)->toBe('twilio')->and($result->usedFallback)->toBeTrue();
    expect(IntegrationLog::query()->pluck('provider')->all())->toContain('termii', 'twilio');
    expect(IntegrationLog::query()->where('provider', 'termii')->first()->request ?? [])->not->toHaveKey('api_key', 'tk');
});

it('does not fail over when the recipient number is invalid', function () {
    Http::fake([
        'api.ng.termii.com/*' => Http::response(['message' => 'Invalid phone number'], 400),
        'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
    ]);

    expect(fn () => smsSender()->send('+2340000', 'Hi'))->toThrow(IntegrationException::class);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'twilio'));
});

it('verifies Stripe webhook signatures with timestamp tolerance', function () {
    config(['services.stripe.secret_key' => 'sk_test', 'services.stripe.webhook_secret' => 'whsec_test']);
    $body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'client_reference_id' => 'pay_unknown', 'payment_status' => 'paid']]]);
    $sign = fn (int $t) => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, 'whsec_test');
    $post = fn (string $sig) => $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig], $body);

    $post($sign(time() - 3600))->assertStatus(401);           // replayed outside tolerance
    $post('t='.time().',v1=deadbeef')->assertStatus(401);      // forged
    $post($sign(time()))->assertOk();
    $post($sign(time()))->assertOk();                          // duplicate delivery

    expect(WebhookEvent::query()->where('provider', 'stripe')->count())->toBe(1);
});

it('rejects WhatsApp webhooks without a valid app-secret signature', function () {
    config(['services.whatsapp.app_secret' => 'wa_secret', 'services.whatsapp.verify_token' => 'vt']);
    $body = json_encode(['entry' => []]);

    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertStatus(401);
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wa_secret')], $body)->assertOk();

    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=vt&hub.challenge=42')->assertOk()->assertSee('42');
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=42')->assertStatus(403);
});
