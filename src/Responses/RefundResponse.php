<?php

declare(strict_types=1);

namespace CraftCms\CommerceMollie\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;

use function CraftCms\Cms\t;

/**
 * Wraps a Mollie refund object, or the error Mollie returned instead of one.
 *
 * @see https://docs.mollie.com/reference/create-refund
 */
class RefundResponse implements RequestResponseInterface
{
    /**
     * @param array<string, mixed> $data The decoded Mollie response
     */
    public function __construct(
        protected readonly array $data,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->isRefund() && !in_array($this->data['status'] ?? null, ['failed', 'canceled'], true);
    }

    public function isProcessing(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return false;
    }

    public function getRedirectMethod(): string
    {
        return '';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        return '';
    }

    public function getTransactionReference(): string
    {
        return $this->isRefund() ? (string)($this->data['id'] ?? '') : '';
    }

    public function getCode(): string
    {
        return (string)($this->data['status'] ?? '');
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        if ($this->isRefund()) {
            return $this->isSuccessful() ? '' : t('The refund failed.', category: 'commerce-mollie');
        }

        return (string)($this->data['detail'] ?? $this->data['title'] ?? t('Unable to reach Mollie.', category: 'commerce-mollie'));
    }

    private function isRefund(): bool
    {
        return ($this->data['resource'] ?? null) === 'refund';
    }
}
