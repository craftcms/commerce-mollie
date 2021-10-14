<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\mollie\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\mollie\models\RequestResponse;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\UrlHelper;
use craft\web\Response;
use craft\web\View;
use craft\commerce\mollie\models\forms\MollieOffsitePaymentForm;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\ResponseInterface;
use Omnipay\Mollie\Message\Request\FetchTransactionRequest;
use Omnipay\Omnipay;
use Omnipay\Mollie\Gateway as OmnipayGateway;
use yii\base\NotSupportedException;

/**
 * Gateway represents Mollie gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * @property-read null|string $settingsHtml
 */
class Gateway extends OffsiteGateway
{
    /**
     * @var string|null
     */
    public ?string $apiKey = null;

    /**
     * @inheritdoc
     */
    public function populateRequest(array &$request, BasePaymentForm $form = null): void
    {
        if ($form) {
            /** @var MollieOffsitePaymentForm $form */
            if ($form->paymentMethod) {
                $request['paymentMethod'] = $form->paymentMethod;
            }

            if ($form->issuer) {
                $request['issuer'] = $form->issuer;
            }
        }
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
     * @return Response|void
     * @throws \Throwable
     * @throws \craft\commerce\errors\CurrencyException
     * @throws \craft\commerce\errors\OrderStatusException
     * @throws \craft\commerce\errors\TransactionException
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function processWebHook(): Response
    {
        $response = Craft::$app->getResponse();

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            Craft::warning('Transaction with the hash “'.$transactionHash.'“ not found.', 'commerce');
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
            Craft::warning('Successful child transaction for “'.$transactionHash.'“ already exists.', 'commerce');
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
        } else if ($res->isExpired()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } else if ($res->isCancelled()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } else if (isset($this->data['status']) && 'failed' === $this->data['status']) {
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
            'purchase' => Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)')
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
     * @return mixed
     */
    public function fetchPaymentMethods(array $parameters = [])
    {
        $paymentMethodsRequest = $this->createGateway()->fetchPaymentMethods($parameters);

        return $paymentMethodsRequest->sendData($paymentMethodsRequest->getData())->getPaymentMethods();
    }

    /**
     * @param array $parameters
     * @return mixed
     */
    public function fetchIssuers(array $parameters = [])
    {
        $issuersRequest = $this->createGateway()->fetchIssuers($parameters);

        return $issuersRequest->sendData($issuersRequest->getData())->getIssuers();
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

        $gateway->setApiKey(Craft::parseEnv($this->apiKey));

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
}
