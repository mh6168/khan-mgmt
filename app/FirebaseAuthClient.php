<?php

declare(strict_types=1);

final class FirebaseAuthException extends RuntimeException
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

final class FirebaseAuthClient
{
    private const SIGN_UP_ENDPOINT = 'https://identitytoolkit.googleapis.com/v1/accounts:signUp';
    private const SIGN_IN_ENDPOINT = 'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 10
    ) {
        if ($this->apiKey === '') {
            throw new FirebaseAuthException('Firebase Authentication is not configured.', 503);
        }

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 60) {
            throw new InvalidArgumentException('Firebase Authentication timeout must be between 1 and 60 seconds.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function signUp(string $email, string $password): array
    {
        return $this->request(self::SIGN_UP_ENDPOINT, $email, $password);
    }

    /**
     * @return array<string, mixed>
     */
    public function signIn(string $email, string $password): array
    {
        return $this->request(self::SIGN_IN_ENDPOINT, $email, $password);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $endpoint, string $email, string $password): array
    {
        $handle = curl_init($endpoint . '?key=' . rawurlencode($this->apiKey));

        if ($handle === false) {
            throw new FirebaseAuthException('Unable to initialize the Firebase Authentication request.');
        }

        $payload = json_encode([
            'email' => $email,
            'password' => $password,
            'returnSecureToken' => true,
        ], JSON_THROW_ON_ERROR);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $responseBody = curl_exec($handle);
        $curlError = curl_error($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($responseBody === false) {
            error_log('Firebase Authentication request failed: ' . $curlError);
            throw new FirebaseAuthException('Firebase Authentication is temporarily unavailable.', 502);
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            error_log('Firebase Authentication returned invalid JSON.');
            throw new FirebaseAuthException('Firebase Authentication returned an invalid response.', 502, $exception);
        }

        if (!is_array($response)) {
            error_log('Firebase Authentication returned a non-object response.');
            throw new FirebaseAuthException('Firebase Authentication returned an invalid response.', 502);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $firebaseMessage = $response['error']['message'] ?? '';
            error_log('Firebase Authentication rejected a request: ' . (is_string($firebaseMessage) ? $firebaseMessage : 'unknown error'));
            throw new FirebaseAuthException(
                self::clientMessage($firebaseMessage),
                self::statusForFirebaseError($firebaseMessage)
            );
        }

        return $response;
    }

    private static function clientMessage(string $firebaseMessage): string
    {
        return match ($firebaseMessage) {
            'EMAIL_EXISTS' => 'An account with this email already exists.',
            'EMAIL_NOT_FOUND', 'INVALID_PASSWORD', 'INVALID_LOGIN_CREDENTIALS' => 'The email or password is incorrect.',
            'USER_DISABLED' => 'This account is disabled.',
            default => 'Firebase Authentication could not complete the request.',
        };
    }

    private static function statusForFirebaseError(string $firebaseMessage): int
    {
        return match ($firebaseMessage) {
            'EMAIL_EXISTS' => 409,
            'EMAIL_NOT_FOUND', 'INVALID_PASSWORD', 'INVALID_LOGIN_CREDENTIALS', 'USER_DISABLED' => 401,
            default => 502,
        };
    }
}
