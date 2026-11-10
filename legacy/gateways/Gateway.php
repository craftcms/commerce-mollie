<?php

namespace craft\commerce\mollie\gateways;

// Gateways saved with Mollie for Craft Commerce 4 store this class name as their type.
/** @deprecated in 5.0.0. Use {@see \CraftCms\CommerceMollie\Gateway} instead. */
class_alias(\CraftCms\CommerceMollie\Gateway::class, 'craft\commerce\mollie\gateways\Gateway');

/** @phpstan-ignore-next-line */
if (false) {
    class Gateway extends \CraftCms\CommerceMollie\Gateway
    {
    }
}
