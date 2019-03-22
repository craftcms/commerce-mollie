<?php

namespace craft\commerce\mollie\models;

use Craft;
use craft\commerce\omnipay\base\RequestResponse as BaseRequestResponse;

/**
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class RequestResponse extends BaseRequestResponse
{
    /**
     * @inheritdoc
     */
    public function getMessage(): string
    {
        $data = $this->response->getData();

        if (is_array($data) && !empty($data['status'])) {
            switch ($data['status']) {
                case 'canceled':
                    return Craft::t('commerce-mollie', 'The payment was canceled.');
                case 'failed':
                    return Craft::t('commerce-mollie', 'The payment failed.');
            }
        }

        return (string)$this->response->getMessage();
    }
}
