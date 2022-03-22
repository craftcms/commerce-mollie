<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\mollie\models\forms;

use craft\commerce\models\payments\BasePaymentForm;

class MollieOffsitePaymentForm extends BasePaymentForm
{
    /**
     * @var string|null
     */
    public ?string $paymentMethod = null;

    /**
     * @var string|null
     */
    public ?string $issuer = null;
}
