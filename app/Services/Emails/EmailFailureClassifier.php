<?php

declare(strict_types=1);

namespace App\Services\Emails;

use Throwable;

/**
 * Decides whether a failed email attempt may succeed if retried.
 *
 * Mail transports surface provider errors as plain exceptions, so the signal is
 * in the message: a 4xx "try later" or a timeout is temporary, while a rejected
 * recipient, an invalid address or a policy block will fail identically on
 * every retry. Retrying a permanent failure only delays the honest record and
 * burns provider quota, so the queue stops after one attempt.
 *
 * Unknown errors are treated as temporary: it is safer to retry once more than
 * to discard a message on an unfamiliar transport hiccup. The final attempt
 * still records a failure, so nothing is silently lost.
 */
final class EmailFailureClassifier
{
    public const string PERMANENT = 'permanent';

    public const string TEMPORARY = 'temporary';

    /**
     * @var list<string>
     */
    private const array PERMANENT_MARKERS = [
        'invalid recipient',
        'invalid email',
        'recipient address rejected',
        'recipient rejected',
        'mailbox unavailable',
        'mailbox not found',
        'no such user',
        'user unknown',
        'address rejected',
        'domain not found',
        'unrouteable address',
        'does not exist',
        'message rejected',
        'content rejected',
        'spam',
        'blocked',
        'blacklist',
        'suppressed',
        'complaint',
        'permanent',
        '550 ',
        '551 ',
        '553 ',
        '554 ',
    ];

    /**
     * @var list<string>
     */
    private const array TEMPORARY_MARKERS = [
        'timeout',
        'timed out',
        'connection',
        'temporarily',
        'try again',
        'rate limit',
        'too many',
        'throttl',
        'unavailable',
        'deferred',
        '421 ',
        '450 ',
        '451 ',
        '452 ',
    ];

    public function classify(Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        foreach (self::PERMANENT_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return self::PERMANENT;
            }
        }

        foreach (self::TEMPORARY_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return self::TEMPORARY;
            }
        }

        return self::TEMPORARY;
    }

    public function isPermanent(Throwable $exception): bool
    {
        return $this->classify($exception) === self::PERMANENT;
    }

    /**
     * A short, safe reason for the delivery row. The full exception is reported
     * to the application log by the job; this column is for operators.
     */
    public function reason(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            $message = $exception::class;
        }

        return mb_strlen($message) > 500 ? mb_substr($message, 0, 497).'...' : $message;
    }
}
