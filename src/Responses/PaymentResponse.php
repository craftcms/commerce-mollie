<?php

declare(strict_types=1);

namespace CraftCms\CommerceMollie\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;

use function CraftCms\Cms\t;

/**
 * Wraps a Mollie payment object, or the error Mollie returned instead of one.
 *
 * @see https://docs.mollie.com/reference/get-payment
 */
class PaymentResponse implements RequestResponseInterface
{
    /**
     * @param array<string, mixed> $data The decoded Mollie response
     * @param bool $completing Whether the response is for a customer returning from Mollie
     */
    public function __construct(
        protected readonly array $data,
        protected readonly bool $completing = false,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->isPayment() && $this->status() === 'paid';
    }

    public function isProcessing(): bool
    {
        if (!$this->isPayment()) {
            return false;
        }

        // Bank transfers stay open until the customer has transferred the money.
        if ($this->completing && $this->status() === 'open' && ($this->data['method'] ?? null) === 'banktransfer') {
            return true;
        }

        return in_array($this->status(), ['pending', 'authorized'], true);
    }

    public function isRedirect(): bool
    {
        if (!$this->isPayment() || $this->status() !== 'open' || $this->getRedirectUrl() === '') {
            return false;
        }

        return !$this->isProcessing();
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        return (string)($this->data['_links']['checkout']['href'] ?? '');
    }

    public function getTransactionReference(): string
    {
        return $this->isPayment() ? (string)($this->data['id'] ?? '') : '';
    }

    public function getCode(): string
    {
        return $this->isPayment() ? $this->status() : (string)($this->data['status'] ?? '');
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        if (!$this->isPayment()) {
            return (string)($this->data['detail'] ?? $this->data['title'] ?? t('Unable to reach Mollie.', category: 'commerce-mollie'));
        }

        return match ($this->status()) {
            'canceled' => t('The payment was canceled.', category: 'commerce-mollie'),
            'failed' => t('The payment failed.', category: 'commerce-mollie'),
            'expired' => t('The payment expired.', category: 'commerce-mollie'),
            default => '',
        };
    }

    protected function isPayment(): bool
    {
        return ($this->data['resource'] ?? null) === 'payment';
    }

    protected function status(): string
    {
        return (string)($this->data['status'] ?? '');
    }
}
