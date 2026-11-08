<?php

declare(strict_types=1);

final class FirestoreException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 502,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

final class FirestoreClient
{
    private const API_BASE_URL = 'https://firestore.googleapis.com/v1/projects/';

    public function __construct(
        private readonly string $projectId,
        private readonly int $timeoutSeconds = 10
    ) {
        if ($this->projectId === '' || !preg_match('/^[a-z0-9][a-z0-9-]{4,61}[a-z0-9]$/', $this->projectId)) {
            throw new InvalidArgumentException('Firestore project ID is not configured correctly.');
        }

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 60) {
            throw new InvalidArgumentException('Firestore timeout must be between 1 and 60 seconds.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserProfile(string $uid, string $firebaseIdToken): ?array
    {
        if ($uid === '' || $firebaseIdToken === '') {
            throw new InvalidArgumentException('Firestore user profile request is invalid.');
        }

        $documentPath = 'databases/(default)/documents/users/' . rawurlencode($uid);
        $handle = curl_init(self::API_BASE_URL . rawurlencode($this->projectId) . '/' . $documentPath);

        if ($handle === false) {
            throw new FirestoreException('Unable to initialize the Firestore request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $firebaseIdToken,
            ],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($responseBody === false) {
            error_log('Firestore profile request failed: network error.');
            throw new FirestoreException('Firestore is temporarily unavailable.', 503);
        }

        if ($httpStatus === 404) {
            return null;
        }

        if ($httpStatus === 401 || $httpStatus === 403) {
            error_log('Firestore profile request was denied: HTTP ' . $httpStatus . '.');
            throw new FirestoreException('Firestore access was denied.', 403);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            error_log('Firestore profile request failed: HTTP ' . $httpStatus . '.');
            throw new FirestoreException('Firestore is temporarily unavailable.', 503);
        }

        try {
            $document = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            error_log('Firestore returned invalid JSON.');
            throw new FirestoreException('Firestore returned an invalid response.', 503, $exception);
        }

        if (!is_array($document)) {
            error_log('Firestore returned a non-object profile response.');
            throw new FirestoreException('Firestore returned an invalid response.', 503);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApplication(string $uid, string $firebaseIdToken): ?array
    {
        return $this->getDocument('applications/' . rawurlencode($uid), $firebaseIdToken);
    }

    /**
     * @param array{fullName: string, storeName: string, phone: string, storeAddress: string, uid: string, nationalIdPath: string, commercialRegisterPath: string, taxCardPath: string, goldLicensePath: string, status: string, submittedAt: string} $application
     */
    public function createApplication(string $uid, string $firebaseIdToken, array $application): void
    {
        if ($uid === '' || $firebaseIdToken === '') {
            throw new InvalidArgumentException('Firestore application request is invalid.');
        }

        $fields = [];
        foreach ($application as $name => $value) {
            $fields[$name] = ['stringValue' => $value];
        }

        $this->request(
            'POST',
            'databases/(default)/documents/applications?documentId=' . rawurlencode($uid),
            $firebaseIdToken,
            ['fields' => $fields]
        );
    }

    /**
     * @param array{fullName: string, storeName: string, phone: string, storeAddress: string, uid: string, nationalIdPath: string, commercialRegisterPath: string, taxCardPath: string, goldLicensePath: string, status: string, submittedAt: string} $application
     */
    public function updateApplication(string $uid, string $firebaseIdToken, array $application): void
    {
        if ($uid === '' || $firebaseIdToken === '') {
            throw new InvalidArgumentException('Firestore application request is invalid.');
        }

        $fields = [];
        foreach ($application as $name => $value) {
            $fields[$name] = ['stringValue' => $value];
        }

        $this->request(
            'PATCH',
            'databases/(default)/documents/applications/' . rawurlencode($uid),
            $firebaseIdToken,
            ['fields' => $fields]
        );
    }

    /**
     * Update only subscription fields so application data remains unchanged.
     *
     * @param array{subscriptionStatus: string, subscriptionAmount: int, subscriptionPaidAt: string, subscriptionExpiresAt: string, paymentTransactionId: string} $subscription
     */
    public function updateSubscription(string $uid, string $firebaseIdToken, array $subscription): void
    {
        if ($uid === '' || $firebaseIdToken === '') {
            throw new InvalidArgumentException('Firestore subscription request is invalid.');
        }

        $fields = [
            'subscriptionStatus' => ['stringValue' => $subscription['subscriptionStatus']],
            'subscriptionAmount' => ['integerValue' => (string) $subscription['subscriptionAmount']],
            'subscriptionPaidAt' => ['stringValue' => $subscription['subscriptionPaidAt']],
            'subscriptionExpiresAt' => ['stringValue' => $subscription['subscriptionExpiresAt']],
            'paymentTransactionId' => ['stringValue' => $subscription['paymentTransactionId']],
        ];

        $updateMask = implode('&', array_map(
            static fn (string $field): string => 'updateMask.fieldPaths=' . rawurlencode($field),
            array_keys($fields)
        ));

        $this->request(
            'PATCH',
            'databases/(default)/documents/applications/' . rawurlencode($uid) . '?' . $updateMask,
            $firebaseIdToken,
            ['fields' => $fields]
        );
    }

    /**
     * Creates users/{uid} using the existing Firestore Security Rules schema.
     *
     * @param array{name: string, email: string, phone: string, uid: string, createdAt: string} $profile
     */
    public function createUserProfile(string $uid, string $firebaseIdToken, array $profile): void
    {
        if ($uid === '' || $firebaseIdToken === '') {
            throw new InvalidArgumentException('Firestore user profile request is invalid.');
        }

        $fields = [];

        foreach ($profile as $name => $value) {
            $fields[$name] = ['stringValue' => $value];
        }

        $this->request(
            'POST',
            'databases/(default)/documents/users?documentId=' . rawurlencode($uid),
            $firebaseIdToken,
            ['fields' => $fields]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDocument(string $documentPath, string $firebaseIdToken): ?array
    {
        $handle = curl_init(self::API_BASE_URL . rawurlencode($this->projectId) . '/databases/(default)/documents/' . $documentPath);

        if ($handle === false) {
            throw new FirestoreException('Unable to initialize the Firestore request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $firebaseIdToken],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($responseBody === false) {
            throw new FirestoreException('Firestore is temporarily unavailable.', 503);
        }
        if ($httpStatus === 404) {
            return null;
        }
        if ($httpStatus === 401 || $httpStatus === 403) {
            throw new FirestoreException('Firestore access was denied.', 403);
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new FirestoreException('Firestore is temporarily unavailable.', 503);
        }

        try {
            $document = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FirestoreException('Firestore returned an invalid response.', 503, $exception);
        }

        return is_array($document) ? $document : null;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, string $firebaseIdToken, ?array $payload = null): ?array
    {
        $handle = curl_init(self::API_BASE_URL . rawurlencode($this->projectId) . '/' . $path);

        if ($handle === false) {
            throw new FirestoreException('Unable to initialize the Firestore request.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $firebaseIdToken,
            ],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($responseBody === false) {
            error_log('Firestore profile write failed: network error.');
            throw new FirestoreException('Firestore is temporarily unavailable.', 503);
        }

        if ($httpStatus === 401 || $httpStatus === 403) {
            error_log('Firestore profile write was denied: HTTP ' . $httpStatus . '.');
            throw new FirestoreException('Firestore access was denied.', 403);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            error_log('Firestore profile write failed: HTTP ' . $httpStatus . '.');
            throw new FirestoreException('Firestore is temporarily unavailable.', 503);
        }

        if ($responseBody === '') {
            return null;
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FirestoreException('Firestore returned an invalid response.', 503, $exception);
        }

        return is_array($response) ? $response : null;
    }
}
