<?php

declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'SupabaseStorageClient.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$path = trim((string) ($_GET['path'] ?? ''));
if (!preg_match(
    '#^jewelers/[^/]+/(?:jewelry|watch)/photos/[A-Za-z0-9._-]+\\.(?:jpg|jpeg|png|webp)$#i',
    $path
)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid product image path.';
    exit;
}

try {
    $timeout = filter_var(getenv('SUPABASE_TIMEOUT_SECONDS') ?: '10', FILTER_VALIDATE_INT);
    $storage = new SupabaseStorageClient(
        trim((string) (getenv('SUPABASE_URL') ?: '')),
        trim((string) (getenv('SUPABASE_STORAGE_BUCKET') ?: '')),
        trim((string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '')),
        $timeout === false ? 10 : $timeout
    );

    header('Cache-Control: private, max-age=300');
    header('Location: ' . $storage->createSignedUrl($path, 600), true, 302);
} catch (Throwable $exception) {
    error_log('Product image endpoint failed: ' . get_class($exception) . '.');
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Product image unavailable.';
}
