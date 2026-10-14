<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Worldline ePayments India (Paynimo / TPSL) gateway —
 * "Single URL" integration.
 *
 * Flow per their PHP kit:
 *   1. Build a pipe-delimited request string of key=value pairs, ending with
 *      |hash=sha1(everything before it).
 *   2. AES-128-CBC encrypt it with the merchant's Encryption Key + IV (base64).
 *   3. Call the SOAP operation getTransactionToken with "<encrypted>|<merchantCode>~".
 *      It answers with a payment page URL to send the browser to.
 *   4. The result comes back as a single encrypted `msg` field, which decrypts
 *      to the same pipe format and carries its own sha1 hash to verify.
 *
 * The SOAP envelope is posted as plain XML over HTTP rather than through
 * PHP's ext-soap, so no extra PHP extension is needed on the server.
 *
 * Request types: T = payment, S = status enquiry (same operation, same crypto).
 */
class WorldlineGateway
{
    /** AES and kit constants — fixed by the gateway, not configuration. */
    private const CIPHER = 'aes-128-cbc';
    private const KIT_VERSION = '1.0.1';
    private const SOAP_NAMESPACE = 'http://shopping.util.pg.tp.com';

    /** txn_status values in a response. */
    public const STATUS_SUCCESS = '0300';
    public const STATUS_FAILURE = '0399';
    public const STATUS_PENDING = '0002';
    /** Status enquiry for an order the gateway has never seen. */
    public const STATUS_NOT_FOUND = '9999';

    private string $serviceUrl;
    private string $merchantCode;
    private string $schemeCode;
    private string $key;
    private string $iv;
    private int $timeout;

    public function __construct()
    {
        $cfg = config('services.worldline');
        $this->serviceUrl   = rtrim((string) ($cfg['service_url'] ?? ''), '/');
        $this->merchantCode = trim((string) ($cfg['merchant_code'] ?? ''));
        $this->schemeCode   = trim((string) ($cfg['scheme_code'] ?? ''));
        $this->key          = trim((string) ($cfg['enc_key'] ?? ''));
        $this->iv           = trim((string) ($cfg['enc_iv'] ?? ''));
        $this->timeout      = (int) ($cfg['timeout'] ?? 30);
    }

    public function isConfigured(): bool
    {
        return $this->serviceUrl !== '' && $this->merchantCode !== ''
            && strlen($this->key) === 16 && strlen($this->iv) === 16;
    }

    public function mode(): string
    {
        return strtoupper((string) config('services.worldline.mode', 'TEST')) === 'LIVE' ? 'LIVE' : 'TEST';
    }

    public function merchantCode(): string
    {
        return $this->merchantCode;
    }

    public function schemeCode(): string
    {
        return $this->schemeCode;
    }

    /**
     * Ask the gateway for a payment page URL (request type T).
     *
     * @param array{order_id:string, amount:string, name?:string, email?:string,
     *              phone?:string, customer_id?:string, return_url:string,
     *              s2s_url?:string, currency?:string} $o
     */
    public function createPaymentUrl(array $o): string
    {
        $this->assertConfigured();

        $fields = $this->paymentFields($o);
        $response = $this->call($fields);

        // A token response is the payment URL; anything else is an error code
        // such as ERROR065 or a TPPGExxx from their error sheet.
        if (!preg_match('#^https?://#i', $response)) {
            throw new RuntimeException('Gateway did not return a payment page: ' . $response);
        }

        return $response;
    }

    /**
     * Status enquiry (request type S) for one of our order ids.
     *
     * The enquiry answer comes back over our own authenticated TLS call and,
     * unlike the browser return, may be plain text with no hash — so the
     * caller gets the verification state rather than a silent empty array.
     *
     * @return array{fields:array<string,string>, verified:bool, hash_present:bool}
     */
    public function fetchStatus(string $orderId, string $amount, ?string $txnDate = null): array
    {
        $this->assertConfigured();

        $fields = [
            'rqst_type'      => 'S',
            'rqst_kit_vrsn'  => self::KIT_VERSION,
            'tpsl_clnt_cd'   => $this->merchantCode,
            'clnt_txn_ref'   => $orderId,
            'rqst_amnt'      => $amount,
            'rqst_crncy'     => 'INR',
            'rqst_rqst_dtls' => $this->cartDetails($amount),
            'clnt_dt_tm'     => $txnDate ?: $this->txnDate(),
        ];

        $parsed = $this->parseResponse($this->call($fields));

        return [
            'fields'       => $parsed['fields'],
            'verified'     => $parsed['verified'],
            'hash_present' => $parsed['hash_present'],
        ];
    }

    /**
     * Read a response payload: the encrypted `msg` posted to our return URL,
     * or the status enquiry's answer, which the gateway sends as plain text.
     *
     * `hash_present` says whether the payload carried a hash at all, and
     * `verified` whether that hash matched. A payload with no hash is never
     * reported as verified — the caller decides whether its channel is
     * trustworthy enough (TLS enquiry: yes; browser return: no).
     *
     * @return array{verified:bool, hash_present:bool, fields:array<string,string>, raw:?string}
     */
    public function parseResponse(string $payload): array
    {
        $payload = trim($payload);
        $empty = ['verified' => false, 'hash_present' => false, 'fields' => [], 'raw' => null];
        if ($payload === '') {
            return $empty;
        }

        // Already plain (status enquiry), otherwise AES-encrypted (return URL).
        $plain = str_contains($payload, 'txn_status=')
            ? $payload
            : openssl_decrypt($payload, self::CIPHER, $this->key, 0, $this->iv);

        if ($plain === false || !is_string($plain) || $plain === '') {
            return $empty;
        }

        // Their kit strips control characters before parsing.
        $plain = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $plain));
        $parts = explode('|', $plain);

        // The last pair is hash=<sha1 of everything before it>, when present.
        $hashPresent = str_starts_with((string) end($parts), 'hash=');
        $verified = false;
        if ($hashPresent) {
            $given = trim(explode('=', (string) array_pop($parts), 2)[1] ?? '');
            $verified = $given !== '' && hash_equals(sha1(implode('|', $parts)), $given);
        }

        $fields = [];
        foreach ($parts as $pair) {
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $v = trim($v);
            // The gateway writes "NA" for every field it has no value for.
            $fields[trim($k)] = $v === 'NA' ? '' : $v;
        }

        return ['verified' => $verified, 'hash_present' => $hashPresent, 'fields' => $fields, 'raw' => $plain];
    }

    /** Request fields for a payment, in the order their kit builds them. */
    private function paymentFields(array $o): array
    {
        $amount = (string) $o['amount'];

        return array_filter([
            'rqst_type'      => 'T',
            'rqst_kit_vrsn'  => self::KIT_VERSION,
            'tpsl_clnt_cd'   => $this->merchantCode,
            'clnt_txn_ref'   => (string) $o['order_id'],
            'clnt_rqst_meta' => $this->clientMeta($o),
            'rqst_amnt'      => $amount,
            'rqst_crncy'     => $o['currency'] ?? 'INR',
            'rtrn_url'       => (string) $o['return_url'],
            's2s_url'        => $o['s2s_url'] ?? null,
            'rqst_rqst_dtls' => $this->cartDetails($amount),
            'clnt_dt_tm'     => $o['txn_date'] ?? $this->txnDate(),
            'cust_id'        => $o['customer_id'] ?? null,
            'mob'            => $o['phone'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** "{email:...}{mob:...}{custid:...}{custname:...}" — all parts optional. */
    private function clientMeta(array $o): string
    {
        $meta = '';
        foreach ([
            'itc'      => config('services.worldline.itc'),
            'email'    => $o['email'] ?? null,
            'mob'      => $o['phone'] ?? null,
            'custid'   => $o['customer_id'] ?? null,
            'custname' => $o['name'] ?? null,
        ] as $tag => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $meta .= '{' . $tag . ':' . $value . '}';
            }
        }
        return $meta;
    }

    /**
     * Scheme code carries the amount with ONE decimal: "FIRST_10.0_0.0",
     * exactly as their kit documentation shows.
     */
    private function cartDetails(string $amount): string
    {
        return $this->schemeCode . '_' . number_format((float) $amount, 1, '.', '') . '_0.0';
    }

    /**
     * clnt_dt_tm must be dd-MM-yyyy with NO time — sending a timestamp is
     * rejected with ERROR035 (verified against their TEST merchant).
     */
    public function txnDate(?string $date = null): string
    {
        return $date ? date('d-m-Y', strtotime($date)) : now()->format('d-m-Y');
    }

    /**
     * Sign, encrypt and post one request string; returns the gateway's raw
     * answer (a payment URL for T, an encrypted payload for S).
     */
    private function call(array $fields): string
    {
        $request = '';
        foreach ($fields as $k => $v) {
            $request .= ($request === '' ? '' : '|') . $k . '=' . $v;
        }
        $request .= '|hash=' . sha1($request);

        $encrypted = openssl_encrypt($request, self::CIPHER, $this->key, 0, $this->iv);
        if ($encrypted === false) {
            throw new RuntimeException('Could not encrypt the payment request.');
        }

        return $this->soapGetTransactionToken($encrypted . '|' . $this->merchantCode . '~');
    }

    /** getTransactionToken, as a plain XML SOAP 1.1 POST. */
    private function soapGetTransactionToken(string $msg): string
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:ns="' . self::SOAP_NAMESPACE . '">'
            . '<soapenv:Body><ns:getTransactionToken><ns:msg>'
            . htmlspecialchars($msg, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</ns:msg></ns:getTransactionToken></soapenv:Body></soapenv:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => '',
        ])->timeout($this->timeout)->withBody($envelope, 'text/xml')->post($this->serviceUrl);

        $body = (string) $response->body();

        if (!$response->successful()) {
            throw new RuntimeException('Payment gateway returned HTTP ' . $response->status() . '.');
        }

        if (preg_match('#<faultstring[^>]*>(.*?)</faultstring>#si', $body, $fault)) {
            throw new RuntimeException('Payment gateway fault: ' . trim(html_entity_decode($fault[1])));
        }

        if (!preg_match('#<(?:[a-zA-Z0-9]+:)?getTransactionTokenReturn[^>]*>(.*?)</(?:[a-zA-Z0-9]+:)?getTransactionTokenReturn>#si', $body, $m)) {
            throw new RuntimeException('Unexpected response from the payment gateway.');
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Payment gateway is not configured (merchant code, 16-character key and IV required).');
        }
    }
}
