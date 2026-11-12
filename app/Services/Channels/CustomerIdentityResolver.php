<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Exceptions\BusinessRuleViolation;
use App\Models\ChannelConnection;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Services\Channels\Data\NormalizedInboundMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a provider sender into one stable customer.
 *
 * The resolver is deliberately conservative. A name or username is a label the
 * provider shows, not proof of who is writing: two people can share a display
 * name, and a username can be changed. Therefore:
 *
 * 1. an existing `(tenant, channel, external_id)` identity always wins;
 * 2. a channel whose sender id *is* the contact detail (a WhatsApp number, an
 *    SMS number, an email address) may match an existing customer on that exact
 *    value;
 * 3. otherwise a new customer is created.
 *
 * Identities are never merged automatically. Linking two provider identities to
 * one customer is an explicit action (`link`), which the API exposes to an
 * authorised merchant.
 */
final class CustomerIdentityResolver
{
    public function resolve(NormalizedInboundMessage $message, ChannelConnection $connection): Customer
    {
        $identity = $this->findIdentity($message->channel, $message->senderId);

        if ($identity instanceof CustomerIdentity) {
            $this->refreshIdentity($identity, $message);

            return $identity->customer;
        }

        return DB::transaction(function () use ($message, $connection): Customer {
            $customer = $this->matchContact($message) ?? $this->createCustomer($message, $connection);

            CustomerIdentity::query()->create([
                'customer_id' => $customer->getKey(),
                'channel' => $message->channel,
                'external_id' => $message->senderId,
                'username' => data_get($message->meta, 'username'),
                'phone' => $this->phoneFor($message),
                'email' => $this->emailFor($message),
                'display_name' => $message->senderName,
                'meta' => ['provider' => data_get($message->meta, 'provider')],
            ]);

            return $customer;
        });
    }

    /**
     * Explicitly attach a provider identity to an existing customer.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function link(Customer $customer, string $channel, string $externalId, array $attributes = []): CustomerIdentity
    {
        $identity = CustomerIdentity::query()->firstOrNew([
            'channel' => $channel,
            'external_id' => $externalId,
        ]);

        if ($identity->exists && $identity->customer_id !== $customer->getKey()) {
            // Moving an identity between customers is a deliberate re-link, but
            // it must be explicit rather than an accidental side effect of an
            // inbound message. The API asks the caller to unlink first.
            throw BusinessRuleViolation::because(
                'This identity is already linked to another customer. Unlink it first.',
                ['identity_id' => $identity->getKey()],
                'identity_already_linked',
            );
        }

        $identity->fill($attributes + [
            'customer_id' => $customer->getKey(),
            'channel' => $channel,
            'external_id' => $externalId,
        ])->save();

        return $identity->refresh();
    }

    public function unlink(CustomerIdentity $identity): void
    {
        $identity->delete();
    }

    /**
     * @return Collection<int, CustomerIdentity>
     */
    public function identitiesFor(Customer $customer): Collection
    {
        return CustomerIdentity::query()
            ->where('customer_id', $customer->getKey())
            ->orderBy('channel')
            ->get();
    }

    private function findIdentity(string $channel, string $externalId): ?CustomerIdentity
    {
        return CustomerIdentity::query()
            ->with('customer')
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->first();
    }

    private function refreshIdentity(CustomerIdentity $identity, NormalizedInboundMessage $message): void
    {
        $changes = array_filter([
            'username' => data_get($message->meta, 'username'),
            'display_name' => $message->senderName,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($changes !== []) {
            $identity->fill($changes)->save();
        }
    }

    /**
     * Match on the exact contact detail the channel carries, and nothing else.
     */
    private function matchContact(NormalizedInboundMessage $message): ?Customer
    {
        $phone = $this->phoneFor($message);

        if ($phone !== null) {
            $candidates = [$phone];

            if (! str_starts_with($phone, '+')) {
                $candidates[] = '+'.$phone;
            }

            $match = Customer::query()->whereIn('phone', array_unique($candidates))->first();

            if ($match instanceof Customer) {
                return $match;
            }
        }

        $email = $this->emailFor($message);

        if ($email !== null) {
            return Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
        }

        return null;
    }

    private function createCustomer(NormalizedInboundMessage $message, ChannelConnection $connection): Customer
    {
        return Customer::query()->create([
            'store_id' => $connection->store_id,
            'name' => $message->senderName ?: $this->fallbackName($message),
            'phone' => $this->phoneFor($message),
            'email' => $this->emailFor($message),
            'status' => 'active',
        ]);
    }

    private function fallbackName(NormalizedInboundMessage $message): string
    {
        return sprintf('%s contact %s', ucfirst($message->channel), $message->senderId);
    }

    /**
     * Only a channel whose sender id is a phone number can yield one.
     */
    private function phoneFor(NormalizedInboundMessage $message): ?string
    {
        if (! in_array($message->channel, ['whatsapp', 'sms'], true)) {
            return null;
        }

        $phone = trim($message->senderId);

        return $phone !== '' ? $phone : null;
    }

    /**
     * Email addresses are the identity on the email channel; some providers put
     * one in the message metadata too.
     */
    private function emailFor(NormalizedInboundMessage $message): ?string
    {
        $email = data_get($message->meta, 'email');

        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        if ($message->channel === 'email' && filter_var($message->senderId, FILTER_VALIDATE_EMAIL)) {
            return $message->senderId;
        }

        return null;
    }
}
