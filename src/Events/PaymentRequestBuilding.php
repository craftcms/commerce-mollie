<?php

declare(strict_types=1);

namespace CraftCms\CommerceMollie\Events;

use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;

/**
 * Dispatched before a payment is created with Mollie. Listeners may modify `$request`, which is sent as the body of
 * the [Create payment](https://docs.mollie.com/reference/create-payment) request.
 */
class PaymentRequestBuilding
{
    /**
     * @param array<string, mixed> $request
     */
    public function __construct(
        public readonly Transaction $transaction,
        public readonly ?BasePaymentForm $form,
        public array $request,
    ) {
    }
}
