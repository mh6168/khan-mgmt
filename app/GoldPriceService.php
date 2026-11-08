<?php

declare(strict_types=1);

final class GoldPriceService
{
    private const DEFAULT_TTL_SECONDS = 1800;
    private const DEFAULT_API_URL = 'https://www.goldapi.io/api/XAU/EGP';
    private const KARAT_FIELDS = [
        24 => 'price_gram_24k',
        22 => 'price_gram_22k',
        21 => 'price_gram_21k',
        18 => 'price_gram_18k',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $cacheFile = null,
        private readonly int $cacheTtlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly int $timeoutSeconds = 10,
        private readonly ?string $apiUrl = null
    ) {
        if ($this->cacheTtlSeconds < 300 || $this->cacheTtlSeconds > 3600) {
            throw new InvalidArgumentException('Gold price cache TTL must be between 300 and 3600 seconds.');
        }

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 30) {
            throw new InvalidArgumentException('Gold price API timeout must be between 1 and 30 seconds.');
        }
    }

    public static function fromEnvironment(): self
    {
        $cacheFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'gold-price-cache.json';

        return new self(
            trim((string) (getenv('GOLD_API_KEY') ?: '')),
            $cacheFile,
            self::DEFAULT_TTL_SECONDS,
            10,
            self::DEFAULT_API_URL
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(): array
    {
        $cached = $this->readCache();
        if ($cached !== null) {
            return $cached;
        }

        try {
            $live = $this->fetchLatest();
            $this->writeCache($live);
            return $live;
        } catch (Throwable $exception) {
            return [
                'available' => false,
                'cached' => false,
                'source' => 'unavailable',
                'message' => 'Gold price is temporarily unavailable. Please try again later.',
                'updatedAt' => null,
                'prices' => [],
            ];
        }
    }

    public function priceForKarat(int $karat): ?float
    {
        $data = $this->latest();

        if (($data['available'] ?? false) !== true) {
            return null;
        }

        $field = self::KARAT_FIELDS[$karat] ?? null;
        if ($field === null || !is_numeric($data[$field] ?? null)) {
            return null;
        }

        return (float) $data[$field];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLatest(): array
    {
        if ($this->apiKey === '') {
            throw new InvalidArgumentException('GOLD_API_KEY is not configured.');
        }

        $url = $this->apiUrl ?? self::DEFAULT_API_URL;
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the GoldAPI request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'x-access-token: ' . $this->apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);

        if ($responseBody === false) {
            $safeError = preg_replace('/[^A-Za-z0-9 .:_-]/', '', $curlError) ?: 'unknown curl error';
            throw new RuntimeException('GoldAPI is temporarily unavailable: ' . $safeError . '.');
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('GoldAPI rejected the request with HTTP ' . $httpStatus . '.');
        }

        $body = preg_replace('/^\xEF\xBB\xBF/', '', $responseBody) ?? $responseBody;

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GoldAPI returned an invalid JSON response.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('GoldAPI returned an invalid response payload.');
        }

        $normalized = $this->normalizeResponse($decoded);

        if ($normalized === null) {
            throw new RuntimeException('GoldAPI did not provide any valid karat prices.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    private function normalizeResponse(array $response): ?array
    {
        $prices = [];
        foreach (self::KARAT_FIELDS as $karat => $fieldName) {
            $value = $this->coerceFloat($response[$fieldName] ?? null);
            if ($value !== null) {
                $prices[$fieldName] = $value;
            }
        }

        if ($prices === []) {
            return null;
        }

        $timestamp = $response['timestamp'] ?? $response['updated_at'] ?? time();
        $convertedTimestamp = is_numeric($timestamp) ? (int) $timestamp : strtotime((string) $timestamp);
        if ($convertedTimestamp === false) {
            $convertedTimestamp = time();
        }

        $result = [
            'available' => true,
            'cached' => false,
            'source' => 'live',
            'message' => 'Live gold price from GoldAPI.',
            'metal' => (string) ($response['metal'] ?? 'XAU'),
            'currency' => strtoupper((string) ($response['currency'] ?? 'EGP')),
            'updatedAt' => date(DATE_ATOM, $convertedTimestamp),
            'updatedAtUnix' => $convertedTimestamp,
            'cacheExpiresAt' => time() + $this->cacheTtlSeconds,
        ];

        foreach (self::KARAT_FIELDS as $karat => $fieldName) {
            $result[$fieldName] = $prices[$fieldName] ?? null;
            $result['karat_' . $karat] = $prices[$fieldName] ?? null;
        }

        $result['prices'] = $prices;

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $cacheFile = $this->cacheFile;
        if ($cacheFile === null || !is_file($cacheFile)) {
            return null;
        }

        $contents = file_get_contents($cacheFile);
        if ($contents === false) {
            return null;
        }

        $normalizedContents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        try {
            $payload = json_decode($normalizedContents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $expiresAt = $payload['cacheExpiresAt'] ?? null;
        if (!is_numeric($expiresAt) || (int) $expiresAt <= time()) {
            return null;
        }

        $cached = [
            'available' => true,
            'cached' => true,
            'source' => 'cache',
            'message' => 'Showing the most recently fetched gold price until its refresh time.',
            'metal' => (string) ($payload['metal'] ?? 'XAU'),
            'currency' => strtoupper((string) ($payload['currency'] ?? 'EGP')),
            'updatedAt' => is_string($payload['updatedAt'] ?? null) ? $payload['updatedAt'] : date(DATE_ATOM, (int) ($payload['updatedAtUnix'] ?? time())),
            'updatedAtUnix' => (int) ($payload['updatedAtUnix'] ?? time()),
            'cacheExpiresAt' => (int) $expiresAt,
            'prices' => [],
        ];

        $hasData = false;
        foreach (self::KARAT_FIELDS as $karat => $fieldName) {
            $value = $this->coerceFloat($payload[$fieldName] ?? null);
            if ($value !== null) {
                $cached[$fieldName] = $value;
                $cached['karat_' . $karat] = $value;
                $cached['prices'][$fieldName] = $value;
                $hasData = true;
            }
        }

        if (!$hasData) {
            return null;
        }

        return $cached;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(array $payload): void
    {
        $cacheFile = $this->cacheFile;
        if ($cacheFile === null) {
            return;
        }

        $directory = dirname($cacheFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $temporaryFile = tempnam($directory, 'gold-price-');
        if ($temporaryFile === false) {
            return;
        }

        $contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporaryFile, $contents, LOCK_EX) === false) {
            @unlink($temporaryFile);
            return;
        }

        @rename($temporaryFile, $cacheFile);
        @chmod($cacheFile, 0664);
    }

    private function coerceFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return is_finite($number) && $number > 0 ? $number : null;
    }
}
