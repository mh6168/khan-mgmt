<?php

declare(strict_types=1);

final class FirestoreAdminClient
{
    private const API_BASE_URL = 'https://firestore.googleapis.com/v1/projects/';
    private const TOKEN_SCOPE = 'https://www.googleapis.com/auth/datastore';

    /**
     * @var array{client_email: string, private_key: string, token_uri: string}
     */
    private array $credentials;
    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly string $projectId,
        string $credentialsFile,
        private readonly int $timeoutSeconds = 10
    ) {
        if ($this->projectId === '' || !preg_match('/^[a-z0-9][a-z0-9-]{4,61}[a-z0-9]$/', $this->projectId)) {
            throw new InvalidArgumentException('Firebase project ID is not configured correctly.');
        }

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 60) {
            throw new InvalidArgumentException('Firestore admin timeout must be between 1 and 60 seconds.');
        }

        $contents = file_get_contents($credentialsFile);
        if ($contents === false) {
            throw new InvalidArgumentException('Firebase admin credentials file is unavailable.');
        }

        try {
            $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Firebase admin credentials file is invalid.', 0, $exception);
        }

        if (!is_array($credentials) ||
            !is_string($credentials['client_email'] ?? null) ||
            !is_string($credentials['private_key'] ?? null)
        ) {
            throw new InvalidArgumentException('Firebase admin credentials are incomplete.');
        }

        $this->credentials = [
            'client_email' => $credentials['client_email'],
            'private_key' => $credentials['private_key'],
            'token_uri' => is_string($credentials['token_uri'] ?? null)
                ? $credentials['token_uri']
                : 'https://oauth2.googleapis.com/token',
        ];
    }

    public static function fromEnvironment(): self
    {
        $file = trim((string) (getenv('FIREBASE_ADMIN_SERVICE_ACCOUNT_FILE') ?: ''));
        if ($file === '') {
            throw new InvalidArgumentException('FIREBASE_ADMIN_SERVICE_ACCOUNT_FILE is not configured.');
        }

        if (!str_starts_with($file, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $file)) {
            $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . $file;
        }

        $timeout = filter_var(getenv('FIREBASE_AUTH_TIMEOUT_SECONDS') ?: '10', FILTER_VALIDATE_INT);

        return new self(
            trim((string) (getenv('FIREBASE_PROJECT_ID') ?: '')),
            $file,
            $timeout === false ? 10 : $timeout
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApplications(): array
    {
        return $this->listDocuments('applications');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUserProfiles(): array
    {
        return $this->listDocuments('users');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApplication(string $uid): ?array
    {
        if ($uid === '' || preg_match('/^[A-Za-z0-9_-]+$/', $uid) !== 1) {
            throw new InvalidArgumentException('Firebase application identifier is invalid.');
        }

        $document = $this->request(
            'GET',
            'databases/(default)/documents/applications/' . rawurlencode($uid)
        );

        return $document === null ? null : $this->decodeDocument($document);
    }

    public function updateApplicationStatus(string $uid, string $status): void
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Application status is invalid.');
        }

        if ($this->getApplication($uid) === null) {
            throw new InvalidArgumentException('Application was not found.');
        }

        $this->request(
            'PATCH',
            'databases/(default)/documents/applications/' . rawurlencode($uid)
                . '?updateMask.fieldPaths=status',
            ['fields' => ['status' => ['stringValue' => $status]]]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listDocuments(string $collection): array
    {
        $documents = [];
        $pageToken = null;

        do {
            $path = 'databases/(default)/documents/' . rawurlencode($collection) . '?pageSize=300';
            if ($pageToken !== null) {
                $path .= '&pageToken=' . rawurlencode($pageToken);
            }

            $response = $this->request('GET', $path);
            if (!is_array($response)) {
                break;
            }

            foreach ($response['documents'] ?? [] as $document) {
                if (is_array($document)) {
                    $documents[] = $this->decodeDocument($document);
                }
            }

            $pageToken = is_string($response['nextPageToken'] ?? null) && $response['nextPageToken'] !== ''
                ? $response['nextPageToken']
                : null;
        } while ($pageToken !== null);

        return $documents;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function decodeDocument(array $document): array
    {
        $name = is_string($document['name'] ?? null) ? $document['name'] : '';
        $uid = basename($name);
        $decoded = ['id' => $uid];

        foreach ($document['fields'] ?? [] as $field => $value) {
            if (is_string($field) && is_array($value)) {
                $decoded[$field] = $this->decodeValue($value);
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function decodeValue(array $value): mixed
    {
        foreach (['stringValue', 'timestampValue', 'referenceValue', 'bytesValue'] as $key) {
            if (array_key_exists($key, $value)) {
                return $value[$key];
            }
        }

        if (array_key_exists('integerValue', $value)) {
            return (int) $value['integerValue'];
        }

        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }

        if (array_key_exists('booleanValue', $value)) {
            return (bool) $value['booleanValue'];
        }

        if (array_key_exists('nullValue', $value)) {
            return null;
        }

        if (is_array($value['mapValue']['fields'] ?? null)) {
            $result = [];
            foreach ($value['mapValue']['fields'] as $key => $child) {
                if (is_string($key) && is_array($child)) {
                    $result[$key] = $this->decodeValue($child);
                }
            }
            return $result;
        }

        if (is_array($value['arrayValue']['values'] ?? null)) {
            return array_map(fn ($item) => is_array($item) ? $this->decodeValue($item) : null, $value['arrayValue']['values']);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, ?array $payload = null): ?array
    {
        $handle = curl_init(self::API_BASE_URL . rawurlencode($this->projectId) . '/' . $path);
        if ($handle === false) {
            throw new FirestoreException('Unable to initialize the Firestore admin request.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken(),
            ],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false) {
            throw new FirestoreException('Firestore is temporarily unavailable.', 503);
        }

        if ($status === 404) {
            return null;
        }

        if ($status === 401 || $status === 403) {
            throw new FirestoreException('Firestore admin access was denied.', 403);
        }

        if ($status < 200 || $status >= 300) {
            error_log('Firestore admin request failed: HTTP ' . $status . '.');
            throw new FirestoreException('Firestore is temporarily unavailable.', 503);
        }

        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FirestoreException('Firestore returned an invalid response.', 503, $exception);
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && $this->accessTokenExpiresAt > time() + 60) {
            return $this->accessToken;
        }

        $issuedAt = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => self::TOKEN_SCOPE,
            'aud' => $this->credentials['token_uri'],
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], JSON_THROW_ON_ERROR));

        if (!openssl_sign($header . '.' . $claims, $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new FirestoreException('Unable to sign Firebase admin credentials.', 503);
        }

        $handle = curl_init($this->credentials['token_uri']);
        if ($handle === false) {
            throw new FirestoreException('Unable to initialize the Firebase admin token request.', 503);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $header . '.' . $claims . '.' . $this->base64Url($signature),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new FirestoreException('Firebase admin authentication is unavailable.', 503);
        }

        try {
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FirestoreException('Firebase admin authentication returned invalid data.', 503, $exception);
        }

        if (!is_array($response) || !is_string($response['access_token'] ?? null)) {
            throw new FirestoreException('Firebase admin authentication returned no access token.', 503);
        }

        $this->accessToken = $response['access_token'];
        $this->accessTokenExpiresAt = $issuedAt + (int) ($response['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
