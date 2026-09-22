<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Omniware (Federal Bank) payment gateway.
 *
 * Integration: "two-step" hosted checkout (guide §4). The server builds and
 * signs the request, the gateway returns a one-time payment page URL, and the
 * PWA simply navigates to it — so the SALT never leaves this server, which the
 * guide requires for app-based payments.
 *
 * Hash (guide Appendix 2): SALT, then every non-empty field value in key-sorted
 * order, pipe-joined, SHA-512, upper-cased. The same algorithm signs requests
 * and verifies responses.
 */
class OmniwareGateway
{
    private string $apiUrl;
    private string $apiKey;
    private string $salt;
    private int $timeout;

    public function __construct()
    {
        $cfg = config('services.omniware');
        $this->apiUrl  = rtrim((string) ($cfg['api_url'] ?? ''), '/');
        $this->apiKey  = (string) ($cfg['api_key'] ?? '');
        $this->salt    = (string) ($cfg['salt'] ?? '');
        $this->timeout = (int) ($cfg['timeout'] ?? 20);
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->apiKey !== '' && $this->salt !== '';
    }

    public function mode(): string
    {
        return strtoupper((string) config('services.omniware.mode', 'TEST')) === 'LIVE' ? 'LIVE' : 'TEST';
    }

    public function hash(array $params): string
    {
        unset($params['hash']);
        ksort($params);

        $data = $this->salt;
        foreach ($params as $value) {
            if (is_array($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $data .= '|' . $value;
            }
        }

        return strtoupper(hash('sha512', $data));
    }

    /** Constant-time check of a response's hash against our own calculation. */
    public function verify(array $response): bool
    {
        $given = (string) ($response['hash'] ?? '');
        if ($given === '') {
            return false;
        }
        return hash_equals($this->hash($response), strtoupper($given));
    }

    /**
     * Step 1 of the two-step flow: get a hosted payment page URL.
     *
     * @param  array $params Payment request fields (guide §2.2), without api_key/hash.
     * @return array{url:string, uuid:string, expiry_datetime:?string}
     */
    public function createPaymentUrl(array $params): array
    {
        $this->assertConfigured();

        $params = array_merge(['api_key' => $this->apiKey], $params);
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        $params['hash'] = $this->hash($params);

        $json = $this->post('/v2/getpaymentrequesturl', $params);

        $data = $json['data'] ?? null;
        if (!is_array($data) || empty($data['url'])) {
            throw new RuntimeException($this->errorMessage($json, 'Could not start the payment.'));
        }

        return [
            'url'             => (string) $data['url'],
            'uuid'            => (string) ($data['uuid'] ?? ''),
            'expiry_datetime' => $data['expiry_datetime'] ?? null,
        ];
    }

    /**
     * Payment Status API (guide §6) for one order. Returns the gateway's
     * transaction rows for that order (newest attempt last is not guaranteed),
     * or an empty array when the gateway has no transaction for it yet.
     *
     * Note: the gateway only answers this from a whitelisted server IP.
     */
    public function fetchStatus(string $orderId): array
    {
        $this->assertConfigured();

        $params = ['api_key' => $this->apiKey, 'order_id' => $orderId];
        $params['hash'] = $this->hash($params);

        $json = $this->post('/v2/paymentstatus', $params);

        if (isset($json['error'])) {
            $code = (int) ($json['error']['code'] ?? 0);
            // 1028 no transaction / 1050 no record: the user never reached "Pay".
            if (in_array($code, [1028, 1050], true)) {
                return [];
            }
            throw new RuntimeException($this->errorMessage($json, 'Payment status lookup failed.'));
        }

        return array_values(array_filter((array) ($json['data'] ?? []), 'is_array'));
    }

    private function post(string $path, array $params): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout)
            ->post($this->apiUrl . $path, $params);

        $json = $response->json();
        if (!is_array($json)) {
            throw new RuntimeException('Payment gateway returned an unexpected response (HTTP ' . $response->status() . ').');
        }
        return $json;
    }

    private function errorMessage(array $json, string $fallback): string
    {
        $error = $json['error'] ?? null;
        if (is_array($error) && !empty($error['message'])) {
            return (string) $error['message'];
        }
        if (is_string($error) && $error !== '') {
            return $error;
        }
        return $fallback;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Payment gateway is not configured.');
        }
    }
}
