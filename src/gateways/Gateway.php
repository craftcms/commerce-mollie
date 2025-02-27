<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\mollie\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\CurrencyException;
use craft\commerce\errors\OrderStatusException;
use craft\commerce\errors\TransactionException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\mollie\models\forms\MollieOffsitePaymentForm;
use craft\commerce\mollie\models\RequestResponse;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\errors\ElementNotFoundException;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\web\Response;
use craft\web\View;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\CreditCard;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Issuer;
use Omnipay\Common\ItemBag;
use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\ResponseInterface;
use Omnipay\Common\PaymentMethod;
use Omnipay\Mollie\Gateway as OmnipayGateway;
use Omnipay\Mollie\Message\Request\FetchTransactionRequest;
use Omnipay\Mollie\Message\Response\FetchPaymentMethodsResponse;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

/**
 * Gateway represents Mollie gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * @property bool $apiKey
 * @property-read null|string $settingsHtml
 */
class Gateway extends OffsiteGateway
{
    /**
     * @var string|null
     */
    private ?string $_apiKey = null;

    /**
     * @var array|null
     */
    private ?array $_paymentMethods = null;

    /**
     * @var array|null
     */
    private ?array $_issuers = null;

    /**
     * @inheritdoc
     */
    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $settings['apiKey'] = $this->getApiKey(false);

        return $settings;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 4.0.0
     */
    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_apiKey) : $this->_apiKey;
    }

    /**
     * @param string|null $apiKey
     * @return void
     * @since 4.0.0
     */
    public function setApiKey(?string $apiKey): void
    {
        $this->_apiKey = $apiKey;
    }

    /**
     * @inheritdoc
     */
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null): void
    {
        if ($paymentForm) {
            /** @var MollieOffsitePaymentForm $paymentForm */
            if ($paymentForm->paymentMethod) {
                $request['paymentMethod'] = $paymentForm->paymentMethod;
            }

            if ($paymentForm->issuer) {
                $request['issuer'] = $paymentForm->issuer;
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function createPaymentRequest(Transaction $transaction, ?CreditCard $card = null, ?ItemBag $itemBag = null): array
    {
        $request = parent::createPaymentRequest($transaction, $card, $itemBag);
        $email = $transaction->getOrder()?->getEmail() ?? null;

        if ($email) {
            $request['billingEmail'] = $email;
        }

        return $request;
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        if (!$this->supportsCompletePurchase()) {
            throw new NotSupportedException(Craft::t('commerce', 'Completing purchase is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction);
        $request['transactionReference'] = $transaction->reference;
        $completeRequest = $this->prepareCompletePurchaseRequest($request);

        return $this->performRequest($completeRequest, $transaction);
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Mollie');
    }

    /**
     * @inheritdoc
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * @return Response
     * @throws \Throwable
     * @throws CurrencyException
     * @throws OrderStatusException
     * @throws TransactionException
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function processWebHook(): Response
    {
        $response = Craft::$app->getResponse();

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            Craft::warning('Transaction with the hash “' . $transactionHash . '“ not found.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        // Check to see if a successful purchase child transaction already exist and skip out early if they do
        $successfulPurchaseChildTransaction = TransactionRecord::find()->where([
            'parentId' => $transaction->id,
            'status' => TransactionRecord::STATUS_SUCCESS,
            'type' => TransactionRecord::TYPE_PURCHASE,
        ])->count();

        if ($successfulPurchaseChildTransaction) {
            Craft::warning('Successful child transaction for “' . $transactionHash . '“ already exists.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        $id = Craft::$app->getRequest()->getBodyParam('id');
        $gateway = $this->createGateway();
        /** @var FetchTransactionRequest $request */
        $request = $gateway->fetchTransaction(['transactionReference' => $id]);
        $res = $request->send();

        if (!$res->isSuccessful()) {
            Craft::warning('Mollie request was unsuccessful.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

        if ($res->isPaid()) {
            $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
        } elseif ($res->isExpired()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } elseif ($res->isCancelled()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } elseif (isset($res->getData()['status']) && 'failed' === $res->getData()['status']) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } else {
            $response->data = 'ok';
            return $response;
        }

        $childTransaction->response = $res->getData();
        $childTransaction->code = $res->getTransactionId();
        $childTransaction->reference = $res->getTransactionReference();
        $childTransaction->message = $res->getMessage();
        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $response->data = 'ok';

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-mollie/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new MollieOffsitePaymentForm();
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params): ?string
    {
        try {
            $defaults = [
                'gateway' => $this,
                'paymentForm' => $this->getPaymentFormModel(),
                'paymentMethods' => $this->fetchPaymentMethods(),
                'issuers' => $this->fetchIssuers(),
            ];
        } catch (\Throwable $exception) {
            // In case this is not allowed for the account
            return parent::getPaymentFormHtml($params);
        }

        $defaults['paymentMethodNamesById'] = ArrayHelper::map($defaults['paymentMethods'], static fn($pm) => $pm->getId(), static fn($pm) => $pm->getName());

        $params = array_merge($defaults, $params);

        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $view->renderTemplate('commerce-mollie/paymentForm', $params);

        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['paymentType', 'compare', 'compareValue' => 'purchase'];

        return $rules;
    }

    /**
     * @param array $parameters
     * @return PaymentMethod[]
     * @throws InvalidRequestException
     */
    public function fetchPaymentMethods(array $parameters = [])
    {
        if ($this->_paymentMethods !== null) {
            return $this->_paymentMethods;
        }
        /** @var OmnipayGateway $gateway */
        $gateway = $this->createGateway();

        $paymentMethodsRequest = $gateway->fetchPaymentMethods($parameters);
        /** @var FetchPaymentMethodsResponse $response */
        $response = $paymentMethodsRequest->sendData(array_merge($paymentMethodsRequest->getData(), ['include' => 'issuers']));

        if (!empty($response->getData()) && isset($response->getData()['_embedded']['methods'])) {
            $this->_issuers = [];
            foreach ($response->getData()['_embedded']['methods'] as $method) {
                // IDEAL no longer uses issuers as of Ideal 2.0
                // See: https://help.mollie.com/hc/en-us/articles/19100313768338-iDEAL-2-0
                if ($method['id'] === 'ideal') {
                    continue;
                }

                if (empty($method['issuers'])) {
                    continue;
                }

                $issuers = collect($method['issuers'])
                    ->map(function($issuer) use ($method) {
                        return new Issuer($issuer['id'], $issuer['name'], $method['id']);
                    })
                    ->all();
                $this->_issuers = [...$this->_issuers, ...$issuers];
            }
        }

        $this->_paymentMethods = $response->getPaymentMethods();
        return $this->_paymentMethods;
    }

    /**
     * @param array $parameters
     * @return Issuer[]
     * @throws InvalidRequestException
     */
    public function fetchIssuers(array $parameters = [])
    {
        if ($this->_issuers !== null) {
            return $this->_issuers;
        }

        $this->_issuers = [];

        // `$_issuers` gets updated as part of the payment methods fetch
        $this->fetchPaymentMethods($parameters);

        return $this->_issuers;
    }

    /**
     * @inheritdoc
     * @since 2.1.2
     */
    public function getTransactionHashFromWebhook(): ?string
    {
        return Craft::$app->getRequest()->getParam('commerceTransactionHash');
    }

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var OmnipayGateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setApiKey($this->getApiKey());

        $commerceMollie = Craft::$app->getPlugins()->getPluginInfo('commerce-mollie');
        if ($commerceMollie) {
            $gateway->addVersionString('MollieCraftCommerce/' . $commerceMollie['version']);
        }

        $commerce = Craft::$app->getPlugins()->getPluginInfo('commerce');
        if ($commerce) {
            $gateway->addVersionString('CraftCommerce/' . $commerce['version']);
        }
        $gateway->addVersionString('uap/MvVFR6uSW5NzK8Kq');

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\' . OmnipayGateway::class;
    }

    /**
     * @inheritdoc
     */
    protected function prepareResponse(ResponseInterface $response, Transaction $transaction): RequestResponseInterface
    {
        /** @var AbstractResponse $response */
        return new RequestResponse($response, $transaction);
    }
}
