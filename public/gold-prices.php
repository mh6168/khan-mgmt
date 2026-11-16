<?php

declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'GoldPriceService.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

try {
    $goldData = GoldPriceService::fromEnvironment()->latest();
    $prices = [];

    foreach ([24, 22, 21, 18] as $karat) {
        $field = 'price_gram_' . $karat . 'k';
        if (is_numeric($goldData[$field] ?? null) && (float) $goldData[$field] > 0) {
            $prices[(string) $karat] = (float) $goldData[$field];
        }
    }

    if (($goldData['available'] ?? false) !== true || $prices === []) {
        http_response_code(503);
        echo json_encode([
            'available' => false,
            'error' => 'Gold prices are temporarily unavailable.',
        ]);
        exit;
    }

    echo json_encode([
        'available' => true,
        'prices' => $prices,
        'updatedAt' => $goldData['updatedAt'] ?? null,
        'source' => $goldData['source'] ?? 'unknown',
        'cached' => $goldData['cached'] ?? false,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Gold price endpoint failed: ' . get_class($exception) . '.');
    http_response_code(503);
    echo json_encode([
        'available' => false,
        'error' => 'Gold prices are temporarily unavailable.',
    ]);
}
