<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceMollie\Events\PaymentRequestBuilding;
use CraftCms\CommerceMollie\Forms\MolliePaymentForm;
use CraftCms\CommerceMollie\Gateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function mollieGateway(array $settings = ['apiKey' => 'test_key']): Gateway
{
    /** @var Gateway $gateway */
    $gateway = app(Gateways::class)->createGateway([
        'type' => Gateway::class,
        'name' => 'Mollie',
        'handle' => 'mollie',
        'paymentType' => 'purchase',
        'settings' => $settings,
    ]);

    return $gateway;
}

/**
 * Saves a gateway and an order, and returns a purchase transaction for it.
 */
function mollieTransaction(float $amount = 12.3): Transaction
{
    $gateway = mollieGateway();
    app(Gateways::class)->saveGateway($gateway);

    $order = new Order();
    $order->email = 'customer@example.com';
    $order->gatewayId = $gateway->id;
    $order->cancelUrl = 'shop/checkout';
    Elements::saveElement($order);

    $transaction = app(Transactions::class)->createTransaction($order, null, TransactionModel::TYPE_PURCHASE);
    $transaction->paymentAmount = $amount;
    $transaction->amount = $amount;

    return $transaction;
}

function molliePayment(array $attributes = []): array
{
    return array_merge([
        'resource' => 'payment',
        'id' => 'tr_test',
        'status' => 'open',
        'method' => 'ideal',
        '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/test']],
    ], $attributes);
}

it('registers the gateway type', function() {
    expect(app(GatewayTypes::class)->types()->all())->toContain(Gateway::class);
});

it('loads gateways saved with the Craft Commerce 5 class name', function() {
    $gateway = app(Gateways::class)->createGateway([
        'type' => 'craft\\commerce\\mollie\\gateways\\Gateway',
        'settings' => ['apiKey' => '$MOLLIE_API_KEY'],
    ]);

    expect($gateway)->toBeInstanceOf(Gateway::class)
        ->and($gateway->getSettings())->toBe(['apiKey' => '$MOLLIE_API_KEY'])
        ->and($gateway->getConfig()['type'])->toBe(Gateway::class);
});

it('requires an API key and the purchase payment type', function() {
    $gateway = mollieGateway(['apiKey' => '']);
    $gateway->paymentType = 'authorize';

    expect($gateway->validate())->toBeFalse()
        ->and($gateway->errors()->keys())->toContain('apiKey', 'paymentType');
});

it('renders the API key setting', function() {
    $html = mollieGateway(['apiKey' => '$MOLLIE_API_KEY'])->getSettingsHtml();

    expect($html)->toContain('apiKey')->toContain('$MOLLIE_API_KEY');
});

it('creates a payment and redirects to the Mollie checkout', function() {
    Http::fake(['api.mollie.com/v2/payments' => Http::response(molliePayment(), 201)]);
    $transaction = mollieTransaction(12.3);

    $form = new MolliePaymentForm(['paymentMethod' => 'ideal']);
    $response = $transaction->getGateway()->purchase($transaction, $form);

    expect($response->isRedirect())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getRedirectMethod())->toBe('GET')
        ->and($response->getRedirectUrl())->toBe('https://www.mollie.com/checkout/test')
        ->and($response->getTransactionReference())->toBe('tr_test');

    Http::assertSent(function(Request $request) use ($transaction) {
        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test_key')
            && str_contains($request->header('User-Agent')[0], 'uap/MvVFR6uSW5NzK8Kq')
            && $request['amount'] === ['currency' => 'USD', 'value' => '12.30']
            && $request['method'] === 'ideal'
            && !isset($request['issuer'])
            && str_contains($request['redirectUrl'], 'commerceTransactionHash=' . $transaction->hash)
            && str_contains($request['webhookUrl'], 'commerceTransactionHash=' . $transaction->hash)
            && str_ends_with($request['cancelUrl'], 'shop/checkout');
    });
});

it('lets listeners modify the payment request', function() {
    Http::fake(['api.mollie.com/v2/payments' => Http::response(molliePayment(), 201)]);
    Event::listen(function(PaymentRequestBuilding $event) {
        $event->request['locale'] = 'nl_NL';
    });
    $transaction = mollieTransaction();

    $transaction->getGateway()->purchase($transaction, new MolliePaymentForm());

    Http::assertSent(fn(Request $request) => $request['locale'] === 'nl_NL' && !isset($request['method']));
});

it('reports Mollie errors as failed payments', function() {
    Http::fake(['api.mollie.com/v2/payments' => Http::response(['status' => 422, 'title' => 'Unprocessable Entity', 'detail' => 'The amount is lower than the minimum'], 422)]);
    $transaction = mollieTransaction(0.01);

    $response = $transaction->getGateway()->purchase($transaction, new MolliePaymentForm());

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getMessage())->toBe('The amount is lower than the minimum');
});

it('completes a purchase from the payment status', function(array $payment, bool $successful, bool $processing, bool $redirect, string $message) {
    Http::fake(['api.mollie.com/v2/payments/tr_test' => Http::response(molliePayment($payment))]);
    $transaction = mollieTransaction();
    $transaction->reference = 'tr_test';

    $response = $transaction->getGateway()->completePurchase($transaction);

    expect($response->isSuccessful())->toBe($successful)
        ->and($response->isProcessing())->toBe($processing)
        ->and($response->isRedirect())->toBe($redirect)
        ->and($response->getMessage())->toBe($message);
})->with([
    'paid' => [['status' => 'paid', '_links' => []], true, false, false, ''],
    'open bank transfer' => [['status' => 'open', 'method' => 'banktransfer'], false, true, false, ''],
    'open, not paid yet' => [['status' => 'open'], false, false, true, ''],
    'authorized' => [['status' => 'authorized', '_links' => []], false, true, false, ''],
    'expired' => [['status' => 'expired', '_links' => []], false, false, false, 'The payment expired.'],
    'canceled' => [['status' => 'canceled', '_links' => []], false, false, false, 'The payment was canceled.'],
]);

it('sends the billing address for Klarna payments', function() {
    Http::fake(['api.mollie.com/v2/payments' => Http::response(molliePayment(), 201)]);
    $transaction = mollieTransaction();
    $transaction->getOrder()->setBillingAddress([
        'firstName' => 'Jenny',
        'lastName' => 'Andrews',
        'addressLine1' => 'Keizersgracht 126',
        'locality' => 'Amsterdam',
        'postalCode' => '1015 CW',
        'countryCode' => 'NL',
    ]);

    $transaction->getGateway()->purchase($transaction, new MolliePaymentForm(['paymentMethod' => 'klarna']));

    Http::assertSent(fn(Request $request) => $request['method'] === 'klarna'
        && $request['lines'] === []
        && $request['billingAddress'] === [
            'givenName' => 'Jenny',
            'familyName' => 'Andrews',
            'email' => 'customer@example.com',
            'streetAndNumber' => 'Keizersgracht 126',
            'city' => 'Amsterdam',
            'postalCode' => '1015 CW',
            'country' => 'NL',
        ]);
});

it('refuses Klarna payments in a different currency than the order', function() {
    Http::fake();
    $transaction = mollieTransaction();
    $transaction->paymentCurrency = 'EUR';

    expect(fn() => $transaction->getGateway()->purchase($transaction, new MolliePaymentForm(['paymentMethod' => 'klarna'])))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('refunds a payment', function() {
    Http::fake(['api.mollie.com/v2/payments/tr_test/refunds' => Http::response(['resource' => 'refund', 'id' => 're_test', 'status' => 'pending'], 201)]);
    $purchase = mollieTransaction(20);
    $purchase->reference = 'tr_test';
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Transactions::class)->createTransaction(null, $purchase, TransactionModel::TYPE_REFUND);
    $refund->paymentAmount = 5;
    $response = $purchase->getGateway()->refund($refund);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('re_test');

    Http::assertSent(fn(Request $request) => $request['amount'] === ['currency' => 'USD', 'value' => '5.00']);
});

it('records the payment status reported by the webhook', function() {
    Http::fake(['api.mollie.com/v2/payments/tr_test' => Http::response(molliePayment(['status' => 'paid']))]);
    $transaction = mollieTransaction();
    $transaction->reference = 'tr_test';
    $transaction->status = TransactionModel::STATUS_REDIRECT;
    app(Transactions::class)->saveTransaction($transaction);

    request()->merge(['commerceTransactionHash' => $transaction->hash, 'id' => 'tr_test']);
    $response = $transaction->getGateway()->processWebHook();

    $children = app(Transactions::class)->getChildrenByTransactionId($transaction->id);
    expect($response->getContent())->toBe('ok')
        ->and($children)->toHaveCount(1)
        ->and($children[0]->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($children[0]->reference)->toBe('tr_test');
});

it('ignores webhooks for a different payment ID', function() {
    Http::fake();
    $transaction = mollieTransaction();
    $transaction->reference = 'tr_test';
    $transaction->status = TransactionModel::STATUS_REDIRECT;
    app(Transactions::class)->saveTransaction($transaction);

    request()->merge(['commerceTransactionHash' => $transaction->hash, 'id' => 'tr_someone_else']);
    $transaction->getGateway()->processWebHook();

    expect(app(Transactions::class)->getChildrenByTransactionId($transaction->id))->toBeEmpty();
    Http::assertNothingSent();
});

it('renders the payment methods and issuers in the payment form', function() {
    Http::fake(['api.mollie.com/v2/methods*' => Http::response(['_embedded' => ['methods' => [
        ['id' => 'ideal', 'description' => 'iDEAL', 'issuers' => [['id' => 'ideal_ABNANL2A', 'name' => 'ABN AMRO']]],
        ['id' => 'kbc', 'description' => 'KBC/CBC Payment Button', 'issuers' => [['id' => 'kbc', 'name' => 'KBC']]],
    ]]])]);

    $html = mollieGateway()->getPaymentFormHtml([]);

    expect($html)->toContain('<option value="ideal">iDEAL</option>')
        ->toContain('<optgroup label="KBC/CBC Payment Button">')
        ->toContain('<option value="kbc">KBC</option>')
        ->not->toContain('ABN AMRO');
});
