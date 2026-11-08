<?php

declare(strict_types=1);

final class PaymobException extends RuntimeException
{
}

final class PaymobClient
{
    private const DEFAULT_API_BASE_URL = 'https://accept.paymob.com/api';
    public const SUBSCRIPTION_AMOUNT_CENTS = 100000;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $integrationId,
        private readonly string $iframeId,
        private readonly string $apiBaseUrl = self::DEFAULT_API_BASE_URL,
        private readonly int $timeoutSeconds = 10,
        private readonly string $redirectUrl = ''
    ) {
        if ($this->apiKey === '' || $this->integrationId === '' || $this->iframeId === '') {
            throw new InvalidArgumentException('Paymob test payment is not configured.');
        }

        if (!ctype_digit($this->integrationId) || !ctype_digit($this->iframeId)) {
            throw new InvalidArgumentException('Paymob integration and iframe IDs must be numeric.');
        }

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 60) {
            throw new InvalidArgumentException('Paymob timeout must be between 1 and 60 seconds.');
        }
    }

    public static function fromEnvironment(): self
    {
        $timeout = filter_var(getenv('PAYMOB_TIMEOUT_SECONDS') ?: '10', FILTER_VALIDATE_INT);

        return new self(
            trim((string) (getenv('PAYMOB_API_KEY') ?: '')),
            trim((string) (getenv('PAYMOB_INTEGRATION_ID') ?: '')),
            trim((string) (getenv('PAYMOB_IFRAME_ID') ?: '')),
            rtrim(trim((string) (getenv('PAYMOB_API_BASE_URL') ?: self::DEFAULT_API_BASE_URL)), '/'),
            $timeout === false ? 10 : $timeout,
            trim((string) (getenv('PAYMOB_REDIRECT_URL') ?: ''))
        );
    }

    /**
     * @param array{first_name: string, last_name: string, email: string, phone_number: string} $customer
     */
    public function createSubscriptionCheckout(string $uid, array $customer): string
    {
        if ($uid === '') {
            throw new InvalidArgumentException('Paymob payment customer is invalid.');
        }

        $authToken = $this->requestToken();
        $order = $this->request('POST', '/ecommerce/orders', $authToken, [
            'auth_token' => $authToken,
            'delivery_needed' => false,
            'amount_cents' => self::SUBSCRIPTION_AMOUNT_CENTS,
            'currency' => 'EGP',
            'merchant_order_id' => 'khan-' . $uid . '-' . time(),
            'items' => [[
                'name' => 'KHAN Jeweler Monthly Subscription',
                'amount_cents' => self::SUBSCRIPTION_AMOUNT_CENTS,
                'description' => 'Monthly jeweler subscription',
                'quantity' => 1,
            ]],
        ]);

        $orderId = $order['id'] ?? null;
        if (!is_int($orderId) && !is_string($orderId)) {
            throw new PaymobException('Paymob returned an invalid order.');
        }

        $paymentKeyPayload = [
            'auth_token' => $authToken,
            'amount_cents' => self::SUBSCRIPTION_AMOUNT_CENTS,
            'expiration' => 3600,
            'order_id' => $orderId,
            'billing_data' => [
                'apartment' => 'NA',
                'email' => $customer['email'],
                'floor' => 'NA',
                'first_name' => $customer['first_name'],
                'street' => 'NA',
                'building' => 'NA',
                'phone_number' => $customer['phone_number'],
                'shipping_method' => 'PKG',
                'postal_code' => 'NA',
                'city' => 'Cairo',
                'country' => 'EG',
                'last_name' => $customer['last_name'],
                'state' => 'Cairo',
            ],
            'currency' => 'EGP',
            'integration_id' => (int) $this->integrationId,
            'lock_order_when_paid' => false,
        ];
        if ($this->redirectUrl !== '') {
            $paymentKeyPayload['redirection_url'] = $this->redirectUrl;
        }

        $paymentKey = $this->request('POST', '/acceptance/payment_keys', $authToken, $paymentKeyPayload);

        $paymentToken = $paymentKey['token'] ?? null;
        if (!is_string($paymentToken) || $paymentToken === '') {
            throw new PaymobException('Paymob returned an invalid payment token.');
        }

        return 'https://accept.paymob.com/api/acceptance/iframes/' .
            rawurlencode($this->iframeId) . '?payment_token=' . rawurlencode($paymentToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $transactionId): array
    {
        if ($transactionId === '' || !ctype_digit($transactionId)) {
            throw new InvalidArgumentException('Paymob transaction ID is invalid.');
        }

        return $this->request(
            'GET',
            '/acceptance/transactions/' . rawurlencode($transactionId),
            $this->requestToken()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string|int $orderId): array
    {
        $orderId = (string) $orderId;
        if ($orderId === '' || !ctype_digit($orderId)) {
            throw new InvalidArgumentException('Paymob order ID is invalid.');
        }

        return $this->request(
            'GET',
            '/ecommerce/orders/' . rawurlencode($orderId),
            $this->requestToken()
        );
    }

    private function requestToken(): string
    {
        $response = $this->request('POST', '/auth/tokens', null, ['api_key' => $this->apiKey]);
        $token = $response['token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new PaymobException('Paymob authentication returned no token.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?string $authToken, ?array $payload = null): array
    {
        $handle = curl_init($this->apiBaseUrl . $path);
        if ($handle === false) {
            throw new PaymobException('Unable to initialize the Paymob request.');
        }

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($authToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $authToken;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($responseBody === false) {
            throw new PaymobException('Paymob is temporarily unavailable.');
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PaymobException('Paymob returned an invalid response.', 0, $exception);
        }

        if (!is_array($response)) {
            throw new PaymobException('Paymob returned an invalid response.');
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            error_log('Paymob request failed: HTTP ' . $httpStatus . '.');
            throw new PaymobException('Paymob could not start the payment.');
        }

        return $response;
    }
}
