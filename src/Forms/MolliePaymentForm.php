<?php

declare(strict_types=1);

namespace CraftCms\CommerceMollie\Forms;

use CraftCms\Commerce\Payment\Forms\BasePaymentForm;

class MolliePaymentForm extends BasePaymentForm
{
    /**
     * The Mollie payment method ID, such as `ideal` or `creditcard`. Leave empty to let the customer choose on Mollie’s checkout.
     */
    public ?string $paymentMethod = null;

    public ?string $issuer = null;
}
