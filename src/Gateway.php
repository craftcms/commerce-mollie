<?php

declare(strict_types=1);

namespace CraftCms\CommerceMollie;

use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Commerce\Exceptions\NotImplementedException;
use CraftCms\Commerce\Order\Adjuster\Discount;
use CraftCms\Commerce\Order\Data\OrderAdjustment;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Currencies;
use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;
use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;
use CraftCms\Commerce\Payment\Gateway\Gateway as BaseGateway;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceMollie\Events\PaymentRequestBuilding;
use CraftCms\CommerceMollie\Forms\MolliePaymentForm;
use CraftCms\CommerceMollie\Responses\PaymentResponse;
use CraftCms\CommerceMollie\Responses\RefundResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Mollie payment gateway, using the Mollie Payments API.
 *
 * Customers are redirected to Mollie’s hosted checkout, and Mollie reports status changes to the gateway’s webhook.
 */
class Gateway extends BaseGateway
{
    /**
     * @var string|null The Mollie API key. Can be set to an environment variable.
     */
    public ?string $apiKey = null;

    /**
     * Payment methods that need the billing address sent to Mollie.
     */
    private const array BILLING_ADDRESS_METHODS = ['alma', 'klarna'];

    /** @var list<array<string, mixed>>|null */
    private ?array $_methods = null;

    #[Override]
    public static function displayName(): string
    {
        return t('Mollie', category: 'commerce-mollie');
    }

    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->apiKey) : $this->apiKey;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'paymentType' => ['required', Rule::in(['purchase'])],
            'apiKey' => ['required'],
        ]);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext()): ?Ui
    {
        return Ui::make([
            Field::make(t('API Key', category: 'commerce-mollie'))
                ->required()
                ->control(Text::make('apiKey')
                    ->value($this->apiKey)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
        ]);
    }

    #[Override]
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => t('Purchase (Authorize and Capture Immediately)', category: 'commerce'),
        ];
    }

    #[Override]
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new MolliePaymentForm();
    }

    #[Override]
    public function paymentFormUi(BasePaymentForm $form, array $params = []): ?Ui
    {
        try {
            $methods = $this->fetchPaymentMethods();
        } catch (Throwable $e) {
            // Without the methods, customers can still choose one on Mollie’s checkout.
            Log::warning('Unable to fetch Mollie payment methods: ' . $e->getMessage());
            return null;
        }

        if ($methods === []) {
            return null;
        }

        $issuerOptions = [];
        foreach ($methods as $method) {
            foreach ($method['issuers'] as $issuer) {
                $issuerOptions[] = ['label' => $issuer['name'], 'value' => $issuer['id'], 'group' => $method['name']];
            }
        }

        return Ui::make([
            Field::make(t('Payment Method', category: 'commerce-mollie'), Choice::make('paymentMethod')
                ->options(array_map(fn(array $method) => ['label' => $method['name'], 'value' => $method['id']], $methods))),
        ])->when($issuerOptions !== [], fn(Ui $ui) => $ui->add(
            Field::make(t('Issuer', category: 'commerce-mollie'), Choice::make('issuer')->options($issuerOptions)),
        ));
    }

    /**
     * Returns the payment methods enabled on the Mollie account, each with an `issuers` array.
     *
     * @return list<array{id: string, name: string, issuers: list<array{id: string, name: string}>}>
     */
    public function fetchPaymentMethods(): array
    {
        if ($this->_methods === null) {
            $this->_methods = array_map(fn(array $method) => [
                'id' => (string)$method['id'],
                'name' => (string)($method['description'] ?? $method['id']),
                // iDEAL no longer uses issuers as of iDEAL 2.0.
                'issuers' => $method['id'] === 'ideal' ? [] : array_map(fn(array $issuer) => [
                    'id' => (string)$issuer['id'],
                    'name' => (string)$issuer['name'],
                ], $method['issuers'] ?? []),
            ], $this->client()->getMethods());
        }

        return $this->_methods;
    }

    #[Override]
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $event = new PaymentRequestBuilding($transaction, $form, $this->buildPaymentRequest($transaction, $form));
        event($event);

        return new PaymentResponse($this->client()->createPayment($event->request));
    }

    #[Override]
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        return new PaymentResponse($this->client()->getPayment((string)$transaction->reference), completing: true);
    }

    #[Override]
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        // Refund transactions start with their parent’s reference, which is the Mollie payment ID.
        return new RefundResponse($this->client()->createRefund((string)$transaction->reference, [
            'amount' => $this->amount($transaction->paymentAmount, (string)$transaction->paymentCurrency),
            'description' => $transaction->note ?: t('Refund for order #{id}', ['id' => $transaction->orderId], category: 'commerce-mollie'),
        ]));
    }

    #[Override]
    public function processWebHook(): Response
    {
        $request = request();
        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = $transactionHash ? app(Transactions::class)->getTransactionByHash($transactionHash) : null;

        if (!$transaction) {
            Log::warning('Mollie webhook: transaction with the hash “' . $transactionHash . '” not found.');
            return response('ok');
        }

        $alreadyPaid = collect(app(Transactions::class)->getChildrenByTransactionId((int)$transaction->id))
            ->contains(fn(Transaction $child) => $child->status === TransactionModel::STATUS_SUCCESS && $child->type === TransactionModel::TYPE_PURCHASE);

        if ($alreadyPaid) {
            Log::warning('Mollie webhook: a successful child transaction for “' . $transactionHash . '” already exists.');
            return response('ok');
        }

        // The `id` param is untrusted, so it has to match the reference already stored on the transaction.
        // The status lookup always uses the stored reference.
        if ($request->input('id') !== $transaction->reference) {
            Log::warning('Mollie webhook: payment ID “' . $request->input('id') . '” did not match the transaction reference for “' . $transactionHash . '”.');
            return response('ok');
        }

        $payment = $this->client()->getPayment((string)$transaction->reference);

        $status = match ($payment['status'] ?? null) {
            'paid' => TransactionModel::STATUS_SUCCESS,
            'expired', 'canceled', 'failed' => TransactionModel::STATUS_FAILED,
            default => null,
        };

        if ($status === null) {
            return response('ok');
        }

        $response = new PaymentResponse($payment);
        $child = app(Transactions::class)->createTransaction(null, $transaction);
        $child->type = $transaction->type;
        $child->status = $status;
        $child->response = $payment;
        $child->code = $response->getCode();
        $child->reference = $response->getTransactionReference();
        $child->message = $response->getMessage();
        app(Transactions::class)->saveTransaction($child);

        return response('ok');
    }

    #[Override]
    public function getTransactionHashFromWebhook(): ?string
    {
        $hash = request()->input('commerceTransactionHash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Builds the body of the Mollie “Create payment” request.
     *
     * @return array<string, mixed>
     */
    protected function buildPaymentRequest(Transaction $transaction, ?BasePaymentForm $form): array
    {
        $order = $transaction->getOrder() ?? throw new InvalidArgumentException('The transaction has no order.');
        $params = ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash];
        $currency = (string)$transaction->paymentCurrency;

        $request = [
            'amount' => $this->amount($transaction->paymentAmount, $currency),
            'description' => t('Order', category: 'commerce') . ' #' . $transaction->orderId,
            'redirectUrl' => Url::actionUrl('commerce/payments/complete-payment', $params),
            'cancelUrl' => Url::siteUrl((string)$order->cancelUrl),
            'webhookUrl' => $this->getWebhookUrl($params),
            'metadata' => ['transactionHash' => $transaction->hash, 'orderId' => $order->id],
        ];

        if ($form instanceof MolliePaymentForm) {
            if ($form->paymentMethod) {
                $request['method'] = $form->paymentMethod;
            }

            if ($form->issuer) {
                $request['issuer'] = $form->issuer;
            }
        }

        if (in_array($request['method'] ?? null, self::BILLING_ADDRESS_METHODS, true)) {
            if ($billingAddress = $order->getBillingAddress()) {
                $request['billingAddress'] = array_filter([
                    'givenName' => $billingAddress->getGivenName(),
                    'familyName' => $billingAddress->getFamilyName(),
                    'email' => $order->getEmail(),
                    'streetAndNumber' => $billingAddress->getAddressLine1(),
                    'city' => $billingAddress->getLocality(),
                    'postalCode' => $billingAddress->getPostalCode(),
                    'country' => $billingAddress->getCountryCode(),
                ]);
            }

            if ($request['method'] === 'klarna') {
                // Klarna needs the order lines, which are in the order’s currency.
                if ($transaction->currency !== $transaction->paymentCurrency) {
                    throw new InvalidArgumentException('The Klarna payment method requires the payment currency to match the order currency.');
                }

                $request['lines'] = $this->orderLines($order, $currency);
            }
        }

        return $request;
    }

    /**
     * Returns the order’s line items, shipping and order-level discounts as Mollie payment lines.
     *
     * @return list<array<string, mixed>>
     */
    protected function orderLines(Order $order, string $currency): array
    {
        $teller = app(Currencies::class)->getTeller($currency);
        $lines = [];

        foreach ($order->getLineItems() as $lineItem) {
            $lines[] = [
                'description' => $lineItem->getDescription(),
                'quantity' => $lineItem->qty,
                'unitPrice' => $this->amount($lineItem->getSalePrice(), $currency),
                'discountAmount' => ['currency' => $currency, 'value' => $teller->convertToString($teller->multiply($lineItem->getDiscount(), -1))],
                'totalAmount' => $this->amount($lineItem->getTotal(), $currency),
            ];
        }

        $shippingCost = $order->getTotalShippingCost();
        if ($shippingCost > 0) {
            $lines[] = [
                'description' => $order->shippingMethodName,
                'type' => 'shipping_fee',
                'quantity' => 1,
                'unitPrice' => $this->amount($shippingCost, $currency),
                'totalAmount' => $this->amount($shippingCost, $currency),
            ];
        }

        // Core discounts are spread across line items, so this only picks up custom order-level discounts.
        $discounts = array_filter(
            $order->getAdjustmentsByType(Discount::ADJUSTMENT_TYPE),
            fn(OrderAdjustment $adjustment) => !$adjustment->included && $adjustment->lineItemId === null && $adjustment->amount < 0,
        );

        foreach ($discounts as $adjustment) {
            $lines[] = [
                'description' => $adjustment->description ?: t('Discount', category: 'commerce'),
                'type' => 'discount',
                'quantity' => 1,
                'unitPrice' => $this->amount($adjustment->amount, $currency),
                'totalAmount' => $this->amount($adjustment->amount, $currency),
            ];
        }

        return $lines;
    }

    /**
     * @return array{currency: string, value: string}
     */
    protected function amount(float $amount, string $currency): array
    {
        return [
            'currency' => $currency,
            'value' => app(Currencies::class)->getTeller($currency)->convertToString($amount),
        ];
    }

    protected function client(): Client
    {
        return new Client((string)$this->getApiKey());
    }

    #[Override]
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        throw new NotImplementedException(t('Authorizing is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        throw new NotImplementedException(t('Capturing is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(t('Completing authorization is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        throw new NotImplementedException(t('Payment sources are not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function deletePaymentSource(string $token): bool
    {
        throw new NotImplementedException(t('Payment sources are not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function supportsAuthorize(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCapture(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCompletePurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    #[Override]
    public function supportsPurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPartialRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsWebhooks(): bool
    {
        return true;
    }
}
