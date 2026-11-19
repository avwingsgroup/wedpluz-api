<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Checkout\TicketQr;
use App\Support\Money;
use App\Support\Platform;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Signed, expiring downloads: ticket PDF (with QR) and order receipt PDF. */
class DocumentController extends Controller
{
    public function ticket(Request $request, string $ticket): Response
    {
        $this->requireSignature($request);
        $model = Ticket::query()->with(['event', 'ticketType', 'seatReservation', 'order'])->findOrFail($ticket);
        if (! in_array($model->status->value, ['valid', 'checked_in'], true)) {
            throw ApiException::conflict('This ticket is no longer valid.');
        }

        $qr = (new Writer(new ImageRenderer(new RendererStyle(260, 1), new SvgImageBackEnd)))->writeString(TicketQr::payload($model));

        return Pdf::loadView('documents.ticket', [
            'ticket' => $model,
            'event' => $model->event,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($qr),
            'starts' => $model->event->starts_at?->setTimezone(Platform::timezone())->format('D j M Y, g:i A'),
        ])->setPaper('a5')->download('wedpluz-ticket-'.$model->code.'.pdf');
    }

    public function receipt(Request $request, string $order): Response
    {
        $this->requireSignature($request);
        $model = Order::query()->with(['event', 'lines', 'foodLines', 'user'])->findOrFail($order);
        if ($model->paid_at === null) {
            throw ApiException::conflict('This order is not paid.');
        }

        return Pdf::loadView('documents.receipt', [
            'order' => $model,
            'money' => fn (int $amount) => Money::format($amount, $model->currency),
            'paidAt' => $model->paid_at->setTimezone(Platform::timezone())->format('j M Y, g:i A'),
        ])->setPaper('a4')->download('wedpluz-receipt-'.$model->reference.'.pdf');
    }

    private function requireSignature(Request $request): void
    {
        if (! $request->hasValidSignature()) {
            throw ApiException::forbidden('This download link has expired.');
        }
    }
}
