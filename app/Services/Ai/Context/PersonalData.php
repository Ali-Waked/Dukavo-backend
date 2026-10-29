<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * Minimises personal data before it is put in front of an external provider.
 *
 * The rule is simple: send the least that lets the model do the job. A reply
 * draft needs to know the customer asked about an order, not their phone
 * number, address or email. These helpers reduce a value to the smallest form
 * that still carries the meaning a prompt needs.
 *
 * Nothing here is a security control on its own — the data simply never leaves
 * the installation.
 */
final class PersonalData
{
    /**
     * Reduce an email address to a recognisable but non-contactable form.
     */
    public function maskEmail(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '' || ! str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);

        $head = mb_substr($local, 0, 1);
        $tail = mb_substr($local, -1);

        return $head.'***'.($head === $tail ? '' : $tail).'@'.$domain;
    }

    /**
     * Keep only the last few digits of a phone number.
     *
     * Enough to recognise a number the customer already quoted in the
     * conversation, useless for reaching them.
     */
    public function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $visible = mb_substr($digits, -3);

        return str_repeat('•', max(0, mb_strlen($digits) - 3)).$visible;
    }

    /**
     * Reduce a full name to its first name and the initial of the last name.
     */
    public function maskName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $name) ?: [];

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = end($parts);

        return $parts[0].' '.mb_substr($last, 0, 1).'.';
    }

    /**
     * Drop a free-text address entirely.
     *
     * A street address is never needed to draft a reply or summarise a
     * conversation, and it is the single most identifying value in the system.
     */
    public function omitAddress(?string $address): string
    {
        return trim((string) $address) === '' ? '' : 'withheld';
    }

    /**
     * A stable, non-reversible handle for a customer.
     *
     * Lets a prompt refer to "customer #a1b2" across several fragments without
     * disclosing who they are.
     */
    public function pseudonym(int $id, string $prefix = 'customer'): string
    {
        return $prefix.'#'.substr(hash('sha256', 'dukavo-context|'.$prefix.'|'.$id), 0, 6);
    }
}
