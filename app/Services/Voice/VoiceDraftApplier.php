<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\VoiceMessageStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Cart;
use App\Models\User;
use App\Models\VoiceMessage;
use App\Services\Carts\CartService;
use Illuminate\Support\Facades\DB;

/**
 * Applies a reviewed voice draft to the conversation's Smart Cart.
 *
 * This is the only place a voice note can change business data, and it is
 * reached only after a person confirms the reviewed lines. It uses the existing
 * cart service for catalogue, stock and pricing rules, never confirms the cart,
 * and is idempotent per voice message.
 */
final class VoiceDraftApplier
{
    public function __construct(private readonly CartService $carts) {}

    /**
     * @param  list<array{product_id: int, product_variant_id: int, quantity: int}>  $items
     */
    public function apply(VoiceMessage $voice, User $actor, bool $confirmed, array $items): Cart
    {
        if (! $confirmed) {
            throw BusinessRuleViolation::because(
                'Review and confirm the voice order before adding it to the cart.',
                [],
                'voice_confirmation_required',
            );
        }

        if ($items === []) {
            throw BusinessRuleViolation::because(
                'Add at least one reviewed item before creating a cart.',
                [],
                'voice_items_required',
            );
        }

        return DB::transaction(function () use ($voice, $actor, $items): Cart {
            $locked = VoiceMessage::query()->lockForUpdate()->findOrFail($voice->getKey());

            if ($locked->status === VoiceMessageStatus::Applied) {
                $cart = $locked->appliedCart;

                if ($cart instanceof Cart) {
                    return $cart->load($this->relations());
                }

                throw BusinessRuleViolation::because(
                    'This voice order was already applied but its cart is no longer available.',
                    [],
                    'voice_cart_missing',
                );
            }

            if (! in_array($locked->status, [VoiceMessageStatus::Ready, VoiceMessageStatus::Applied], true)
                || ! is_array($locked->extraction)) {
                throw BusinessRuleViolation::because(
                    'This recording is not ready to become a cart yet.',
                    ['status' => $locked->status->value],
                    'voice_not_ready',
                );
            }

            $cart = $this->carts->createForConversation($locked->conversation);

            foreach ($items as $item) {
                $cart = $this->carts->addItem($cart, [
                    'product_id' => (int) $item['product_id'],
                    'product_variant_id' => (int) $item['product_variant_id'],
                    'quantity' => (int) $item['quantity'],
                ], (int) $cart->version);
            }

            $locked->forceFill([
                'status' => VoiceMessageStatus::Applied,
                'applied_cart_id' => $cart->getKey(),
                'applied_by' => $actor->getKey(),
                'applied_at' => now(),
            ])->save();

            return $cart->load($this->relations());
        });
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return ['items', 'store', 'customer', 'conversation', 'order'];
    }
}
