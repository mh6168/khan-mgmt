<?php

declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'SupabaseStorageClient.php';

$results = [
    'firebase' => checkFirebaseConfiguration(),
    'supabase' => checkSupabaseConnection(),
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(in_array(false, array_column($results, 'connected'), true) ? 1 : 0);

/**
 * This check is read-only: it fetches Google's public Firebase signing keys.
 *
 * @return array{connected: bool, message: string}
 */
function checkFirebaseConfiguration(): array
{
    $projectId = trim((string) (getenv('FIREBASE_PROJECT_ID') ?: ''));
    $apiKey = trim((string) (getenv('FIREBASE_WEB_API_KEY') ?: ''));

    if ($projectId === '' || $apiKey === '') {
        return ['connected' => false, 'message' => 'FIREBASE_PROJECT_ID and FIREBASE_WEB_API_KEY are required.'];
    }

    $handle = curl_init('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');

    if ($handle === false) {
        return ['connected' => false, 'message' => 'Unable to initialize the Firebase request.'];
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    if ($body === false || $status < 200 || $status >= 300) {
        return ['connected' => false, 'message' => 'Firebase public signing keys could not be reached.'];
    }

    return ['connected' => true, 'message' => 'Firebase configuration is present and the public signing-key endpoint responded.'];
}

/**
 * @return array{connected: bool, message: string}
 */
function checkSupabaseConnection(): array
{
    $timeout = filter_var(getenv('SUPABASE_TIMEOUT_SECONDS') ?: '10', FILTER_VALIDATE_INT);

    try {
        $client = new SupabaseStorageClient(
            trim((string) (getenv('SUPABASE_URL') ?: '')),
            trim((string) (getenv('SUPABASE_STORAGE_BUCKET') ?: '')),
            trim((string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '')),
            $timeout === false ? 10 : $timeout
        );
        $client->checkConnection();
    } catch (Throwable $exception) {
        return ['connected' => false, 'message' => $exception instanceof SupabaseStorageException || $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Supabase Storage could not be reached.'];
    }

    return ['connected' => true, 'message' => 'Supabase Storage bucket is reachable.'];
}
