<?php

use App\Models\Ticket;
use App\Services\Admin\TwoFactorService;
use App\Services\Checkout\TicketQr;
use App\Support\Money;
use App\Support\RichText;

describe('Money', function () {
    it('converts between minor and major units without float drift', function () {
        expect(Money::fromMajor('19.99'))->toBe(1999)
            ->and(Money::fromMajor(0.1 + 0.2))->toBe(30)
            ->and(Money::toMajor(525000))->toBe('5250.00')
            ->and(Money::format(525000))->toBe('₦5,250.00');
    });

    it('rounds percentages half up to whole kobo', function () {
        expect(Money::percentOf(1000, 2.5))->toBe(25)
            ->and(Money::percentOf(333, 5))->toBe(17);
    });
});

describe('RichText', function () {
    it('strips scripts, handlers and javascript urls but keeps allowed formatting', function () {
        $html = RichText::sanitize('<p onclick="x()">Hi <strong>there</strong><script>alert(1)</script></p><a href="javascript:alert(1)">bad</a><a href="https://wedpluz.com">ok</a>');

        expect($html)->toContain('<strong>there</strong>')
            ->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript:')
            ->toContain('href="https://wedpluz.com"')->toContain('noopener');
    });

    it('passes plain text through', function () {
        expect(RichText::sanitize('  Just text  '))->toBe('Just text')
            ->and(RichText::sanitize(null))->toBe('');
    });
});

describe('TOTP', function () {
    it('matches the RFC 6238 SHA-1 test vectors (6-digit truncation)', function () {
        $totp = app(TwoFactorService::class);
        $key = '12345678901234567890';

        expect($totp->codeAt($key, intdiv(59, 30)))->toBe('287082')
            ->and($totp->codeAt($key, intdiv(1111111109, 30)))->toBe('081804')
            ->and($totp->codeAt($key, intdiv(2000000000, 30)))->toBe('279037');
    });

    it('accepts one step of clock drift either side', function () {
        $totp = app(TwoFactorService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 of the RFC key
        $now = 1111111109;
        $previous = $totp->codeAt('12345678901234567890', intdiv($now, 30) - 1);

        expect($totp->matchingStep($secret, $previous, $now))->toBe(intdiv($now, 30) - 1)
            ->and($totp->matchingStep($secret, '000000', $now))->toBeNull();
    });
});

describe('TicketQr', function () {
    it('signs payloads that only verify for the same ticket and secret version', function () {
        $ticket = (new Ticket)->forceFill(['id' => 'tik_01JABCDEFGHJKMNPQRSTVWXYZ0', 'schedule_id' => null, 'qr_secret_version' => 1]);
        $payload = TicketQr::payload($ticket);
        $parsed = TicketQr::parse($payload);

        expect($parsed['ticketId'])->toBe($ticket->id)->and(TicketQr::verify($ticket, $parsed))->toBeTrue();

        // Reissue/transfer bumps the version and invalidates printed codes.
        $ticket->qr_secret_version = 2;
        expect(TicketQr::verify($ticket, $parsed))->toBeFalse()
            ->and(TicketQr::parse('WP1.garbage'))->toBeNull();
    });
});
