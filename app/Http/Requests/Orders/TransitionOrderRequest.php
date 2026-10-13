<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Moving an order through its lifecycle.
 *
 * The target status is validated against the state machine of the order's
 * *current* status, so an illegal jump (`pending → shipped`) is refused with a
 * clear message instead of corrupting stock and history.
 */
class TransitionOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'partially_paid', 'paid', 'refunded'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $order = $this->route('order');
            $current = $order?->status;
            $target = OrderStatus::tryFrom((string) $this->input('status'));

            if (! $current instanceof OrderStatus || $target === null) {
                return;
            }

            if (! $current->canTransitionTo($target)) {
                $validator->errors()->add('status', sprintf(
                    'An order cannot move from %s to %s.',
                    $current->label(),
                    $target->label(),
                ));
            }
        });
    }
}
