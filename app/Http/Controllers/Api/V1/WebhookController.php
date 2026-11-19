<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Services\Payments\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inbound provider callbacks. Authenticated by signature or shared secret,
 * never by tokens. Valid events are stored and processed on the queue; the
 * response is returned quickly. Signed webhooks are never answered with 4xx.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function payment(Request $request, string $provider, PaymentManager $payments): JsonResponse
    {
        if (! $payments->has($provider)) {
            return $this->reject(404, 'Unknown provider.');
        }
        $status = $this->webhooks->receivePayment($provider, $payments->gateway($provider), $request);

        return $status === 401 ? $this->reject(401, 'Invalid signature.') : response()->json(['received' => true]);
    }

    public function prembly(Request $request): JsonResponse
    {
        $secret = (string) config('services.prembly.webhook_secret');
        $header = (string) ($request->header('x-prembly-signature') ?? $request->header('x-identitypass-signature') ?? '');
        if ($secret === '' || $header === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $header)) {
            $this->webhooks->signatureFailure('prembly', $request);

            return $this->reject(401, 'Invalid signature.');
        }
        $payload = (array) $request->json()->all();
        $reference = (string) ($payload['reference'] ?? $payload['verification']['reference'] ?? $payload['data']['reference'] ?? '');
        $status = (string) ($payload['verification']['status'] ?? $payload['status'] ?? '');
        $this->webhooks->receiveGeneric('prembly', 'prembly:'.$reference.':'.$status, 'identity.result', $payload);

        return response()->json(['received' => true]);
    }

    public function zeptomail(Request $request): JsonResponse
    {
        $key = (string) config('services.zeptomail.webhook_key');
        $given = (string) ($request->header('authorization') ?? $request->query('key', ''));
        if ($key === '' || ! hash_equals($key, str_replace('Bearer ', '', $given))) {
            $this->webhooks->signatureFailure('zeptomail', $request);

            return $this->reject(401, 'Invalid key.');
        }
        $payload = (array) $request->json()->all();
        $type = (string) ($payload['event_name'][0] ?? $payload['event_name'] ?? 'unknown');
        $id = (string) ($payload['event_message'][0]['request_id'] ?? md5($request->getContent()));
        $this->webhooks->receiveGeneric('zeptomail', 'zeptomail:'.$type.':'.$id, $type, $payload);

        return response()->json(['received' => true]);
    }

    public function termii(Request $request): JsonResponse
    {
        $secret = (string) config('services.termii.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->query('secret', ''))) {
            $this->webhooks->signatureFailure('termii', $request);

            return $this->reject(401, 'Invalid secret.');
        }
        $payload = (array) $request->all();
        $this->webhooks->receiveGeneric('termii', 'termii:'.($payload['message_id'] ?? md5($request->getContent())).':'.($payload['status'] ?? ''), 'sms.status', $payload);

        return response()->json(['received' => true]);
    }

    public function twilio(Request $request): JsonResponse
    {
        $token = (string) config('services.twilio.auth_token');
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $token, true));
        if ($token === '' || ! hash_equals($expected, (string) $request->header('X-Twilio-Signature'))) {
            $this->webhooks->signatureFailure('twilio', $request);

            return $this->reject(401, 'Invalid signature.');
        }
        $this->webhooks->receiveGeneric('twilio', 'twilio:'.($params['MessageSid'] ?? '').':'.($params['MessageStatus'] ?? ''), 'sms.status', $params);

        return response()->json(['received' => true]);
    }

    /** GET /webhooks/whatsapp — Meta verification handshake. */
    public function whatsappVerify(Request $request): Response
    {
        $expected = (string) config('services.whatsapp.verify_token');
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response((string) $request->query('hub_challenge', $request->query('hub.challenge', '')), 200, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
    }

    public function whatsapp(Request $request, WhatsAppClient $whatsapp): JsonResponse
    {
        if (! $whatsapp->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            $this->webhooks->signatureFailure('whatsapp', $request);

            return $this->reject(401, 'Invalid signature.');
        }
        foreach ((array) $request->json('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $this->webhooks->receiveGeneric('whatsapp', 'whatsapp:'.($status['id'] ?? '').':'.($status['status'] ?? ''), 'message.status', (array) $status);
                }
            }
        }

        return response()->json(['received' => true]);
    }

    private function reject(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $status === 401 ? 'UNAUTHENTICATED' : 'NOT_FOUND', 'correlationId' => request()->attributes->get('correlation_id')], $status);
    }
}
