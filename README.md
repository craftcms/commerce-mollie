<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Mollie for Craft Commerce icon"></p>

<h1 align="center">Mollie for Craft Commerce</h1>

This plugin provides a [Mollie](https://www.mollie.com/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

Mollie 4.2.x requires either:

- Craft 4.0 and Craft Commerce 4.0 or later
- Craft 5.0 and Craft Commerce 5.0 or later

Mollie 4.1.x is only compatible with Craft 4.0 and Craft Commerce 4.0 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Mollie for Craft Commerce”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftcms/commerce-mollie

# tell Craft to install the plugin
./craft install/plugin commerce-mollie
```

## Setup

To add a Mollie payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “Mollie”.

> **Tip:** The API Key setting can be set to environment variables. See [Environmental Configuration](https://docs.craftcms.com/v3/config/environments.html) in the Craft docs to learn more about that.
