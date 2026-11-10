<?php

declare(strict_types=1);

namespace CraftCms\CommerceMollie;

use Composer\InstalledVersions;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A small client for the Mollie Payments API v2.
 *
 * @see https://docs.mollie.com/reference/overview
 */
class Client
{
    public const string BASE_URL = 'https://api.mollie.com/v2';

    /**
     * Mollie’s partner identifier for Craft Commerce.
     */
    private const string PARTNER_ID = 'uap/MvVFR6uSW5NzK8Kq';

    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createPayment(array $data): array
    {
        return $this->request()->post('payments', $data)->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(string $id): array
    {
        return $this->request()->get('payments/' . rawurlencode($id))->json() ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createRefund(string $paymentId, array $data): array
    {
        return $this->request()->post('payments/' . rawurlencode($paymentId) . '/refunds', $data)->json() ?? [];
    }

    /**
     * Returns the payment methods enabled on the account, with their issuers.
     *
     * @return list<array<string, mixed>>
     */
    public function getMethods(): array
    {
        $data = $this->request()->get('methods', ['include' => 'issuers'])->throw()->json();

        return $data['_embedded']['methods'] ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->withUserAgent($this->userAgent());
    }

    private function userAgent(): string
    {
        return implode(' ', [
            'MollieCraftCommerce/' . (InstalledVersions::getPrettyVersion('craftcms/commerce-mollie') ?? 'dev'),
            'CraftCommerce/' . (InstalledVersions::getPrettyVersion('craftcms/commerce') ?? 'dev'),
            self::PARTNER_ID,
        ]);
    }
}
