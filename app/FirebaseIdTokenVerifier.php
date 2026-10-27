<?php

declare(strict_types=1);

final class FirebaseTokenVerificationException extends RuntimeException
{
}

final class FirebaseKeyFetchException extends RuntimeException
{
}

final class FirebaseIdTokenVerifier
{
    private const PUBLIC_KEYS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    private const MAX_CACHE_AGE_SECONDS = 86400;

    public function __construct(
        private readonly string $projectId,
        private readonly string $cacheFile,
        private readonly int $timeoutSeconds = 10,
        private readonly int $now = 0
    ) {
        if ($this->projectId === '' || !preg_match('/^[a-z0-9][a-z0-9-]{4,61}[a-z0-9]$/', $this->projectId)) {
            throw new InvalidArgumentException('Firebase project ID is not configured correctly.');
        }

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 60) {
            throw new InvalidArgumentException('Firebase token verification timeout must be between 1 and 60 seconds.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyAuthorizationHeader(?string $authorizationHeader): array
    {
        if (!is_string($authorizationHeader) ||
            !preg_match('/^Bearer[ \t]+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/', $authorizationHeader, $matches)
        ) {
            throw new FirebaseTokenVerificationException('A valid bearer token is required.');
        }

        return $this->verify($matches[1]);
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new FirebaseTokenVerificationException('The ID token format is invalid.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->decodeJsonPart($encodedHeader);
        $payload = $this->decodeJsonPart($encodedPayload);
        $signature = $this->decodeBase64Url($encodedSignature);

        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw new FirebaseTokenVerificationException('The ID token header is invalid.');
        }

        $this->validateClaims($payload);
        $publicKeys = $this->loadPublicKeys($header['kid']);
        $certificate = $publicKeys[$header['kid']] ?? null;

        if (!is_string($certificate) || $certificate === '') {
            throw new FirebaseTokenVerificationException('The ID token signing key is not recognized.');
        }

        $verificationResult = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $certificate,
            OPENSSL_ALGO_SHA256
        );

        if ($verificationResult !== 1) {
            throw new FirebaseTokenVerificationException('The ID token signature is invalid.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPart(string $part): array
    {
        try {
            $json = $this->decodeBase64Url($part);
            $this->assertNoDuplicateJsonKeys($json);
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException | FirebaseTokenVerificationException | InvalidArgumentException) {
            throw new FirebaseTokenVerificationException('The ID token encoding is invalid.');
        }

        if (!is_array($decoded)) {
            throw new FirebaseTokenVerificationException('The ID token JSON is invalid.');
        }

        return $decoded;
    }

    private function decodeBase64Url(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new FirebaseTokenVerificationException('The ID token encoding is invalid.');
        }

        $padding = strlen($value) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . ($padding === 0 ? '' : str_repeat('=', 4 - $padding)), true);

        if ($decoded === false) {
            throw new FirebaseTokenVerificationException('The ID token encoding is invalid.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateClaims(array $payload): void
    {
        $requiredClaims = ['exp', 'iat', 'aud', 'iss', 'sub', 'auth_time'];

        foreach ($requiredClaims as $claim) {
            if (!array_key_exists($claim, $payload)) {
                throw new FirebaseTokenVerificationException('The ID token claims are incomplete.');
            }
        }

        $now = $this->currentTime();
        $numericClaims = ['exp', 'iat', 'auth_time'];

        foreach ($numericClaims as $claim) {
            if (!is_int($payload[$claim]) && !is_float($payload[$claim])) {
                throw new FirebaseTokenVerificationException('The ID token claims are invalid.');
            }
        }

        if ($payload['exp'] <= $now || $payload['iat'] > $now || $payload['auth_time'] > $now) {
            throw new FirebaseTokenVerificationException('The ID token is expired or not yet valid.');
        }

        if ($payload['aud'] !== $this->projectId ||
            $payload['iss'] !== 'https://securetoken.google.com/' . $this->projectId ||
            !is_string($payload['sub']) ||
            $payload['sub'] === ''
        ) {
            throw new FirebaseTokenVerificationException('The ID token claims are invalid.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function loadPublicKeys(string $keyId): array
    {
        $cached = $this->readCache();

        if ($cached !== null && isset($cached['keys'][$keyId])) {
            return $cached['keys'];
        }

        try {
            $fresh = $this->fetchPublicKeys();
            $this->writeCache($fresh);
            return $fresh['keys'];
        } catch (FirebaseKeyFetchException $exception) {
            error_log('Firebase token key refresh failed.');

            if ($cached !== null && isset($cached['keys'][$keyId])) {
                return $cached['keys'];
            }

            throw $exception;
        }
    }

    /**
     * @return array{expiresAt: int, keys: array<string, string>}|null
     */
    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        $contents = file_get_contents($this->cacheFile);

        if ($contents === false) {
            return null;
        }

        try {
            $this->assertNoDuplicateJsonKeys($contents);
            $cache = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException | InvalidArgumentException) {
            return null;
        }

        if (!is_array($cache) ||
            !is_int($cache['expiresAt'] ?? null) ||
            $cache['expiresAt'] <= $this->currentTime() ||
            !is_array($cache['keys'] ?? null)
        ) {
            return null;
        }

        $keys = [];

        foreach ($cache['keys'] as $keyId => $certificate) {
            if (is_string($keyId) &&
                $keyId !== '' &&
                is_string($certificate) &&
                openssl_x509_read($certificate) !== false
            ) {
                $keys[$keyId] = $certificate;
            }
        }

        return $keys === [] ? null : ['expiresAt' => $cache['expiresAt'], 'keys' => $keys];
    }

    /**
     * @return array{expiresAt: int, keys: array<string, string>}
     */
    private function fetchPublicKeys(): array
    {
        $handle = curl_init(self::PUBLIC_KEYS_URL);

        if ($handle === false) {
            throw new FirebaseKeyFetchException('Unable to initialize the Firebase key request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($handle);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
            throw new FirebaseKeyFetchException('Firebase public keys are unavailable.');
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $maxAge = $this->cacheMaxAge($responseHeaders);

        try {
            $this->assertNoDuplicateJsonKeys($body);
            $keys = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException | InvalidArgumentException $exception) {
            throw new FirebaseKeyFetchException('Firebase public keys response is invalid.', 0, $exception);
        }

        if (!is_array($keys) || $keys === []) {
            throw new FirebaseKeyFetchException('Firebase public keys response is invalid.');
        }

        foreach ($keys as $keyId => $certificate) {
            if (!is_string($keyId) || !is_string($certificate) || openssl_x509_read($certificate) === false) {
                throw new FirebaseKeyFetchException('Firebase public keys response is invalid.');
            }
        }

        return ['expiresAt' => $this->currentTime() + $maxAge, 'keys' => $keys];
    }

    private function cacheMaxAge(string $headers): int
    {
        if (preg_match('/^Cache-Control:\s*[^\\r\\n]*?max-age=(\d+)/im', $headers, $matches) !== 1) {
            return 300;
        }

        return min((int) $matches[1], self::MAX_CACHE_AGE_SECONDS);
    }

    private function currentTime(): int
    {
        return $this->now > 0 ? $this->now : time();
    }

    private function assertNoDuplicateJsonKeys(string $json): void
    {
        $offset = 0;
        $this->scanJsonValue($json, $offset);

        if ($offset !== strlen($json)) {
            throw new InvalidArgumentException('Unexpected JSON content.');
        }
    }

    private function scanJsonValue(string $json, int &$offset): void
    {
        $this->skipJsonWhitespace($json, $offset);
        $character = $json[$offset] ?? '';

        if ($character === '{') {
            $this->scanJsonObject($json, $offset);
            return;
        }

        if ($character === '[') {
            $this->scanJsonArray($json, $offset);
            return;
        }

        if ($character === '"') {
            $this->scanJsonString($json, $offset);
            return;
        }

        $start = $offset;

        while (isset($json[$offset]) && !in_array($json[$offset], [',', ']', '}'], true)) {
            $offset++;
        }

        if ($start === $offset) {
            throw new InvalidArgumentException('Expected JSON value.');
        }
    }

    private function scanJsonObject(string $json, int &$offset): void
    {
        $offset++;
        $keys = [];
        $this->skipJsonWhitespace($json, $offset);

        if (($json[$offset] ?? '') === '}') {
            $offset++;
            return;
        }

        while (true) {
            $this->skipJsonWhitespace($json, $offset);
            $key = $this->scanJsonString($json, $offset);

            if (isset($keys[$key])) {
                throw new InvalidArgumentException('Duplicate JSON object key.');
            }

            $keys[$key] = true;
            $this->skipJsonWhitespace($json, $offset);

            if (($json[$offset] ?? '') !== ':') {
                throw new InvalidArgumentException('Expected JSON object separator.');
            }

            $offset++;
            $this->scanJsonValue($json, $offset);
            $this->skipJsonWhitespace($json, $offset);
            $separator = $json[$offset] ?? '';

            if ($separator === '}') {
                $offset++;
                return;
            }

            if ($separator !== ',') {
                throw new InvalidArgumentException('Expected JSON object delimiter.');
            }

            $offset++;
        }
    }

    private function scanJsonArray(string $json, int &$offset): void
    {
        $offset++;
        $this->skipJsonWhitespace($json, $offset);

        if (($json[$offset] ?? '') === ']') {
            $offset++;
            return;
        }

        while (true) {
            $this->scanJsonValue($json, $offset);
            $this->skipJsonWhitespace($json, $offset);
            $separator = $json[$offset] ?? '';

            if ($separator === ']') {
                $offset++;
                return;
            }

            if ($separator !== ',') {
                throw new InvalidArgumentException('Expected JSON array delimiter.');
            }

            $offset++;
        }
    }

    private function scanJsonString(string $json, int &$offset): string
    {
        $start = $offset;
        $offset++;
        $escaped = false;

        while (isset($json[$offset])) {
            $character = $json[$offset];
            $offset++;

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($character === '\\') {
                $escaped = true;
                continue;
            }

            if ($character === '"') {
                $decoded = json_decode(substr($json, $start, $offset - $start), true, 512, JSON_THROW_ON_ERROR);

                if (!is_string($decoded)) {
                    throw new InvalidArgumentException('Invalid JSON string.');
                }

                return $decoded;
            }
        }

        throw new InvalidArgumentException('Unterminated JSON string.');
    }

    private function skipJsonWhitespace(string $json, int &$offset): void
    {
        while (isset($json[$offset]) && in_array($json[$offset], [" ", "\t", "\r", "\n"], true)) {
            $offset++;
        }
    }

    /**
     * @param array{expiresAt: int, keys: array<string, string>} $cache
     */
    private function writeCache(array $cache): void
    {
        $directory = dirname($this->cacheFile);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new FirebaseKeyFetchException('Unable to prepare Firebase key cache.');
        }

        $temporaryFile = tempnam($directory, 'firebase-keys-');

        if ($temporaryFile === false) {
            throw new FirebaseKeyFetchException('Unable to prepare Firebase key cache.');
        }

        $contents = json_encode($cache, JSON_THROW_ON_ERROR);

        if (file_put_contents($temporaryFile, $contents, LOCK_EX) === false ||
            !rename($temporaryFile, $this->cacheFile)
        ) {
            @unlink($temporaryFile);
            throw new FirebaseKeyFetchException('Unable to write Firebase key cache.');
        }

        @chmod($this->cacheFile, 0600);
    }
}
