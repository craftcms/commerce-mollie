# Upgrading to Mollie for Craft Commerce 5.0 (Commerce 6)

Mollie for Craft Commerce 5.0 supports Craft Commerce 6. It talks to the [Mollie Payments API](https://docs.mollie.com/reference/overview) directly, rather than through Omnipay.

## Requirements

- Craft CMS 6.0 and Craft Commerce 6.0 or later
- PHP 8.5 or later
- `craftcms/yii2-adapter` is not needed.

## Upgrade steps

1. Update to Mollie for Craft Commerce 4.4.3 and Craft Commerce 5 first.
2. Upgrade your project to Craft 6 and Craft Commerce 6.
3. Update the plugin: `composer require craftcms/commerce-mollie:^5.0`.
4. Review the breaking changes below.

Existing Mollie gateways keep working: gateways saved with the old `craft\commerce\mollie\gateways\Gateway` type load as `CraftCms\CommerceMollie\Gateway`, and save with the new class name the next time they’re saved.

## Breaking changes

### Omnipay

The plugin no longer depends on `craftcms/commerce-omnipay`, `omnipay/mollie` or `omnipay/common`. If your project uses Omnipay classes directly, require those packages yourself.

### Events

The Omnipay gateway events are gone. To change the request sent to Mollie when a payment is created, listen for `CraftCms\CommerceMollie\Events\PaymentRequestBuilding` and modify its `$request` array, which is the body of the [Create payment](https://docs.mollie.com/reference/create-payment) request.

| Before | After |
| --- | --- |
| `craft\commerce\omnipay\base\Gateway::EVENT_BUILD_GATEWAY_REQUEST` | `CraftCms\CommerceMollie\Events\PaymentRequestBuilding` |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_SEND_PAYMENT_REQUEST` | `CraftCms\CommerceMollie\Events\PaymentRequestBuilding` |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_GATEWAY_REQUEST_SEND` | No replacement |
| `craft\commerce\omnipay\base\Gateway::EVENT_AFTER_CREATE_ITEM_BAG` | No replacement |

Before:

```php
use craft\commerce\mollie\gateways\Gateway;
use craft\commerce\omnipay\events\SendPaymentRequestEvent;
use yii\base\Event;

Event::on(Gateway::class, Gateway::EVENT_BEFORE_SEND_PAYMENT_REQUEST, function(SendPaymentRequestEvent $event) {
    $data = $event->requestData;
    $data['locale'] = 'nl_NL';
    $event->modifiedRequestData = $data;
});
```

After:

```php
use CraftCms\CommerceMollie\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    $event->request['locale'] = 'nl_NL';
});
```

The keys of the request are Mollie’s own parameter names, so code that set Omnipay parameters (such as `paymentMethod` or `returnUrl`) needs to use Mollie’s names (`method`, `redirectUrl`).

### Classes and namespaces

| Before | After |
| --- | --- |
| `craft\commerce\mollie\Plugin` | `CraftCms\CommerceMollie\Plugin` |
| `craft\commerce\mollie\gateways\Gateway` | `CraftCms\CommerceMollie\Gateway` (the old name is kept as an alias) |
| `craft\commerce\mollie\models\forms\MollieOffsitePaymentForm` | `CraftCms\CommerceMollie\Forms\MolliePaymentForm` |
| `craft\commerce\mollie\models\RequestResponse` | `CraftCms\CommerceMollie\Responses\PaymentResponse` and `CraftCms\CommerceMollie\Responses\RefundResponse` |

`Gateway::fetchPaymentMethods()` now returns arrays with `id`, `name` and `issuers` keys, rather than Omnipay `PaymentMethod` objects. `Gateway::fetchIssuers()` was removed; each method’s issuers are in its `issuers` key.

### Settings

The “Send cart information” (`sendCartInfo`) setting was removed. Klarna payments always send the order lines, which Klarna requires; other payment methods don’t send them.

### Templates and Twig

The `commerce-mollie/paymentForm` template was removed. The gateway now builds its payment form with Commerce 6’s `paymentFormUi()`, and `gateway.getPaymentFormHtml()` renders it as before. To change the form, extend the gateway and override `paymentFormUi()`, or render your own form.

The payment form posts the same `paymentMethod` and `issuer` fields as before. If you render your own payment form, `gateway.fetchPaymentMethods()` returns arrays with `id`, `name` and `issuers` keys.
