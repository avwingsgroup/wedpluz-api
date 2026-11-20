<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A domain error that renders as the contract's ApiErrorBody:
 * `{ message, code, errors?, correlationId }`.
 *
 * `message` must always be safe to show to end users.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    /** @param array<string, list<string>|string> $errors */
    public static function validation(string $message = 'Please check the highlighted fields.', array $errors = []): self
    {
        $normalised = [];
        foreach ($errors as $field => $messages) {
            $normalised[$field] = (array) $messages;
        }

        return new self($message, 'VALIDATION_ERROR', 422, $normalised);
    }

    public static function unauthenticated(string $message = 'Your session has expired. Please sign in again.'): self
    {
        return new self($message, 'UNAUTHENTICATED', 401);
    }

    public static function accountSuspended(string $message = 'Your account has been suspended. Contact support for help.'): self
    {
        return new self($message, 'ACCOUNT_SUSPENDED', 403);
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): self
    {
        return new self($message, 'FORBIDDEN', 403);
    }

    /** Email not verified yet: the client offers a "Verify email" action (additive code). */
    public static function emailUnverified(string $message = 'Verify your email to continue.'): self
    {
        return new self($message, 'EMAIL_UNVERIFIED', 403);
    }

    public static function identityUnverified(string $message = 'Verify your identity to continue.'): self
    {
        return new self($message, 'IDENTITY_UNVERIFIED', 403);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): self
    {
        return new self($message, 'NOT_FOUND', 404);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 'CONFLICT', 409);
    }

    public static function ticketsUnavailable(string $message, array $errors = []): self
    {
        return new self($message, 'TICKETS_UNAVAILABLE', 409, $errors);
    }

    public static function seatTaken(string $message): self
    {
        return new self($message, 'SEAT_TAKEN', 409);
    }

    public static function paymentRequired(string $message = 'Upgrade your plan to use this feature.'): self
    {
        return new self($message, 'PAYMENT_REQUIRED', 402);
    }

    public static function insufficientFunds(string $message = 'Insufficient wallet balance.'): self
    {
        return new self($message, 'INSUFFICIENT_FUNDS', 402);
    }

    public static function paymentFailed(string $message = 'We could not process this payment. Please try again.'): self
    {
        return new self($message, 'PAYMENT_FAILED', 402);
    }

    public static function promoInvalid(string $message = 'This promo code is not valid for your order.'): self
    {
        return new self($message, 'PROMO_INVALID', 422, ['promoCode' => [$message]]);
    }

    public static function otpInvalid(string $message = 'The code you entered is incorrect.', string $field = 'code'): self
    {
        return new self($message, 'OTP_INVALID', 422, [$field => [$message]]);
    }

    public static function otpExpired(string $message = 'This code has expired. Request a new one.', string $field = 'code'): self
    {
        return new self($message, 'OTP_EXPIRED', 422, [$field => [$message]]);
    }

    public static function pinInvalid(string $message = 'Incorrect wallet PIN.'): self
    {
        return new self($message, 'PIN_INVALID', 422, ['pin' => [$message]]);
    }

    public static function rateLimited(int $retryAfter, ?string $message = null): self
    {
        $message ??= 'Too many attempts. Please try again in '.self::humanSeconds($retryAfter).'.';

        return new self($message, 'RATE_LIMITED', 429, [], ['Retry-After' => (string) $retryAfter]);
    }

    public static function humanSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' second'.($seconds === 1 ? '' : 's');
        }
        $minutes = (int) ceil($seconds / 60);

        return $minutes.' minute'.($minutes === 1 ? '' : 's');
    }
}
