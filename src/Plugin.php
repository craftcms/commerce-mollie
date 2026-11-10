<?php

declare(strict_types=1);

namespace CraftCms\CommerceMollie;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;

/**
 * Mollie for Craft Commerce.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '5.0.0';

    public string $minVersionRequired = '4.4.3';

    public function boot(GatewayTypes $gatewayTypes): void
    {
        $gatewayTypes->register(Gateway::class);
    }
}
