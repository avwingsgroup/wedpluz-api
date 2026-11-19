<?php

namespace App\Providers;

use App\Integrations\Ai\ImageGenerator;
use App\Integrations\Ai\PlannerAssistant;
use App\Integrations\Google\GoogleIdTokenVerifier;
use App\Integrations\Identity\IdentityVerifier;
use App\Integrations\Identity\PremblyVerifier;
use App\Integrations\Live\LiveKitTokenIssuer;
use App\Integrations\Mail\ZeptoMailTransport;
use App\Integrations\Payments\FlutterwaveGateway;
use App\Integrations\Payments\MonnifyGateway;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Payments\PayPalGateway;
use App\Integrations\Payments\PaystackGateway;
use App\Integrations\Payments\StripeGateway;
use App\Integrations\Push\FcmClient;
use App\Integrations\Sms\FailoverSmsSender;
use App\Integrations\Sms\SmsSender;
use App\Integrations\Sms\TermiiSmsSender;
use App\Integrations\Sms\TwilioSmsSender;
use App\Integrations\Storage\CloudinaryClient;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Services\Media\ImageUrl;
use App\Services\Platform\SettingsService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Binds every third-party integration behind its interface, configured from
 * config/services.php. Swapping a provider is a binding change here.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaystackGateway::class, fn () => new PaystackGateway(config('services.paystack')));
        $this->app->singleton(FlutterwaveGateway::class, fn () => new FlutterwaveGateway(config('services.flutterwave')));
        $this->app->singleton(MonnifyGateway::class, fn () => new MonnifyGateway(config('services.monnify')));
        $this->app->singleton(StripeGateway::class, fn () => new StripeGateway(config('services.stripe')));
        $this->app->singleton(PayPalGateway::class, fn () => new PayPalGateway(config('services.paypal')));

        $this->app->bind(PaymentManager::class, fn ($app) => new PaymentManager([
            'paystack' => $app->make(PaystackGateway::class),
            'flutterwave' => $app->make(FlutterwaveGateway::class),
            'monnify' => $app->make(MonnifyGateway::class),
            'stripe' => $app->make(StripeGateway::class),
            'paypal' => $app->make(PayPalGateway::class),
        ], $app->make(SettingsService::class)));

        $this->app->singleton(SmsSender::class, function () {
            $senders = [
                'termii' => fn () => new TermiiSmsSender(config('services.termii')),
                'twilio' => fn () => new TwilioSmsSender(config('services.twilio')),
            ];
            $primary = $senders[config('services.sms.primary', 'termii')] ?? $senders['termii'];
            $fallbackName = config('services.sms.fallback');
            $fallback = $fallbackName && isset($senders[$fallbackName]) && $fallbackName !== config('services.sms.primary') ? $senders[$fallbackName]() : null;

            return new FailoverSmsSender($primary(), $fallback);
        });

        $this->app->singleton(WhatsAppClient::class, fn () => new WhatsAppClient(config('services.whatsapp')));
        $this->app->singleton(FcmClient::class, fn () => new FcmClient(config('services.fcm')));
        $this->app->singleton(CloudinaryClient::class, fn () => new CloudinaryClient(config('services.cloudinary')));
        $this->app->singleton(ImageUrl::class, fn () => ImageUrl::fromConfig());
        $this->app->singleton(IdentityVerifier::class, fn () => new PremblyVerifier(config('services.prembly')));
        $this->app->singleton(LiveKitTokenIssuer::class, fn () => new LiveKitTokenIssuer(config('services.livekit')));
        $this->app->singleton(PlannerAssistant::class, fn () => new PlannerAssistant(config('services.anthropic')));
        $this->app->singleton(ImageGenerator::class, fn () => new ImageGenerator(config('services.image_generation')));
        $this->app->singleton(GoogleIdTokenVerifier::class, fn () => new GoogleIdTokenVerifier(
            config('wedpluz.auth.google_client_ids', []),
            config('services.google'),
        ));
    }

    public function boot(): void
    {
        Mail::extend('zeptomail', fn () => new ZeptoMailTransport(
            (string) config('services.zeptomail.token'),
            (string) config('services.zeptomail.endpoint'),
            config('services.zeptomail.bounce_address'),
            (int) config('services.zeptomail.timeout', 15),
        ));
    }
}
