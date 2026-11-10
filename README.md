<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="Mollie for Craft Commerce icon"></p>

<h1 align="center">Mollie for Craft Commerce</h1>

This plugin provides a [Mollie](https://www.mollie.com/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

Mollie 5.x requires Craft CMS 6.0, Craft Commerce 6.0 and PHP 8.5 or later.

Mollie 4.2.x supports Craft 4 and Craft Commerce 4, or Craft 5 and Craft Commerce 5. If you’re upgrading from 4.x, see [UPGRADE.md](UPGRADE.md).

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the **Plugin Store** in your project’s control panel and search for “Mollie for Craft Commerce”. Then click on the **Install** button in the sidebar.

#### With Composer

Open your terminal and run the following commands:

```bash
# Navigate to your project directory:
cd /path/to/my-project.test

# Require the plugin package with Composer:
composer require craftcms/commerce-mollie

# Run the installer:
php artisan craft:plugin:install commerce-mollie
```

## Setup

To add a Mollie payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “Mollie”.

> [!NOTE]
> The **API Key** setting can be set to environment variables. See [Environmental Configuration](https://craftcms.com/docs/6.x/configure.html#control-panel-settings) in the Craft docs to learn more about using secrets in configuration.

## Supported Payment Methods

These payment methods have been tested and are supported by Mollie for Craft Commerce. Other payment methods may work, but are untested.

- Credit/Debit Cards
- iDEAL
- Bancontact
- SEPA Bank Transfer
- Klarna (only when paying in the base currency of the store)
- Alma
- Pay by Bank (Bank Transfer)

## Customizing payment requests

To change the request sent to Mollie when a payment is created, listen for the `PaymentRequestBuilding` event and modify its `request` array. Its keys are Mollie’s [Create payment](https://docs.mollie.com/reference/create-payment) parameters.

```php
use CraftCms\CommerceMollie\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    $event->request['locale'] = 'nl_NL';
});
```

