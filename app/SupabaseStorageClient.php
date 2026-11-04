<?php

declare(strict_types=1);

final class SupabaseStorageException extends RuntimeException
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

final class SupabaseStorageClient
{
    public function __construct(
        private readonly string $url,
        private readonly string $bucket,
        private readonly string $serviceRoleKey,
        private readonly int $timeoutSeconds = 10
    ) {
        if (!filter_var($this->url, FILTER_VALIDATE_URL) ||
            !str_starts_with(strtolower($this->url), 'https://')
        ) {
            throw new InvalidArgumentException('Supabase URL must be a valid HTTPS URL.');
        }

        if ($this->bucket === '' || preg_match('/^[A-Za-z0-9._-]+$/', $this->bucket) !== 1) {
            throw new InvalidArgumentException('Supabase Storage bucket is not configured correctly.');
        }

        if ($this->serviceRoleKey === '') {
            throw new InvalidArgumentException('Supabase service-role key is not configured.');
        }

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 60) {
            throw new InvalidArgumentException('Supabase timeout must be between 1 and 60 seconds.');
        }

        if (!function_exists('curl_init')) {
            throw new SupabaseStorageException('Supabase Storage requires the PHP cURL extension.', 503);
        }
    }

    public function checkConnection(): void
    {
        $this->request(
            'GET',
            '/storage/v1/bucket/' . rawurlencode($this->bucket)
        );
    }

    public function ensurePrivateBucket(): void
    {
        try {
            $this->checkConnection();
            return;
        } catch (SupabaseStorageException $exception) {
            if ($exception->httpStatus() !== 404) {
                throw $exception;
            }
        }

        $this->request(
            'POST',
            '/storage/v1/bucket',
            json_encode(['name' => $this->bucket, 'public' => false], JSON_THROW_ON_ERROR),
            'application/json'
        );
    }

    public function upload(string $path, string $contents, string $contentType): void
    {
        $this->validateObjectPath($path);

        $this->request(
            'POST',
            '/storage/v1/object/' . rawurlencode($this->bucket) . '/' . $this->encodedObjectPath($path),
            $contents,
            $contentType
        );
    }

    public function delete(string $path): void
    {
        $this->validateObjectPath($path);
        $this->request(
            'POST',
            '/storage/v1/object/remove/' . rawurlencode($this->bucket),
            json_encode(['prefixes' => [$path]], JSON_THROW_ON_ERROR),
            'application/json'
        );
    }

    public function createSignedUrl(string $path, int $expiresInSeconds = 600): string
    {
        $this->validateObjectPath($path);

        if ($expiresInSeconds < 1 || $expiresInSeconds > 86400) {
            throw new InvalidArgumentException('Signed URL expiry must be between 1 and 86400 seconds.');
        }

        $response = $this->request(
            'POST',
            '/storage/v1/object/sign/' . rawurlencode($this->bucket) . '/' . $this->encodedObjectPath($path),
            json_encode(['expiresIn' => $expiresInSeconds], JSON_THROW_ON_ERROR),
            'application/json'
        );
        $signedUrl = is_array($response) ? ($response['signedURL'] ?? null) : null;

        if (!is_string($signedUrl) || $signedUrl === '') {
            throw new SupabaseStorageException('Supabase Storage returned an invalid signed URL.');
        }

        return rtrim($this->url, '/') . '/storage/v1' . $signedUrl;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, ?string $body = null, ?string $contentType = null): ?array
    {
        $handle = curl_init(rtrim($this->url, '/') . $path);

        if ($handle === false) {
            throw new SupabaseStorageException('Unable to initialize the Supabase Storage request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $this->headers($contentType),
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($responseBody === false) {
            error_log('Supabase Storage request failed: ' . preg_replace('/[^A-Za-z0-9 .:_-]/', '', $curlError));
            throw new SupabaseStorageException('Supabase Storage is temporarily unavailable.', 503);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            error_log('Supabase Storage request failed: HTTP ' . $httpStatus . '.');
            $safeStatus = $httpStatus === 401 || $httpStatus === 403 ? 502 : ($httpStatus === 404 ? 404 : 503);
            throw new SupabaseStorageException('Supabase Storage rejected the request.', $safeStatus);
        }

        if ($responseBody === '') {
            return null;
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SupabaseStorageException('Supabase Storage returned an invalid response.', 502, $exception);
        }

        return is_array($response) ? $response : null;
    }

    /**
     * @return list<string>
     */
    private function headers(?string $contentType): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->serviceRoleKey,
            'apikey: ' . $this->serviceRoleKey,
        ];

        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        return $headers;
    }

    private function validateObjectPath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new InvalidArgumentException('Supabase Storage object path is invalid.');
        }
    }

    private function encodedObjectPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
